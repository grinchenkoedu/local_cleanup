<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * CLI script to display statistics about files and history tables.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var moodle_database $DB
 * @var object $CFG
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use local_cleanup\finder;

/**
 * Format file size with appropriate units.
 *
 * @package    local_cleanup
 * @param int $bytes Size in bytes
 * @param int $decimals Number of decimal places
 * @return string Formatted size with unit
 */
function format_file_size($bytes, $decimals = 2) {
    $units = ['bytes', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $decimals) . ' ' . $units[$pow];
}

$finder = new finder($DB);

mtrace("\n=== FILE STATISTICS ===");
$components = [
    'assignsubmission_file' => true,
    'backup' => false,
];

foreach ($components as $component => $batchremoval) {
    $stats = $finder->stats($component);

    mtrace(sprintf(
        "%s: %d files, %s",
        get_string($component, 'local_cleanup'),
        $stats->count,
        format_file_size($stats->size)
    ));

    // Calculate statistics for specific time periods.
    $periods = [
        [null, '-1 year'], // From now to 1 year ago.
        ['-1 year', '-2 years'], // From 1 year ago to 2 years ago.
    ];

    foreach ($periods as $index => $period) {
        list($from, $to) = $period;

        if ($from === null) {
            // Files newer than 1 year.
            $statsperiod = $finder->stats($component, $to, true);

            $perioddesc = sprintf("to %s", date('Y-m-d', strtotime($to)));
        } else {
            // Files between 1 and 2 years old.
            // Use the new functionality to directly query for files in the specific time period.
            $statsperiod = $finder->stats($component, $to, false, $from);

            $perioddesc = sprintf("from %s to %s",
                date('Y-m-d', strtotime($from)),
                date('Y-m-d', strtotime($to))
            );
        }

        mtrace(sprintf(
            "  %s (%s): %d files, %s",
            get_string($component, 'local_cleanup'),
            $perioddesc,
            $statsperiod->count,
            format_file_size($statsperiod->size)
        ));
    }
}

mtrace("\n=== HISTORY TABLE STATISTICS ===");
$tables = [
    'logstore_standard_log' => 'timecreated',
    'logstore_lanalytics_log' => 'timecreated',
    'grade_grades_history' => 'timemodified',
    'grade_items_history' => 'timemodified',
];

foreach ($tables as $table => $datetimefield) {
    if (!$DB->get_manager()->table_exists($table)) {
        mtrace("Table $table does not exist. Skipping.");
        continue;
    }

    $count = $DB->count_records($table);

    // Get table size in a database-agnostic way.
    $size = 0;
    try {
        if ($CFG->dbtype === 'mysqli') {
            // MySQL/MariaDB specific query.
            $sizequery = $DB->get_records_sql("SHOW TABLE STATUS LIKE '{$CFG->prefix}{$table}'");
            foreach ($sizequery as $info) {
                // Handle case sensitivity in property names.
                $datalength = 0;
                $indexlength = 0;

                if (property_exists($info, 'Data_length')) {
                    $datalength = $info->Data_length;
                } else if (property_exists($info, 'data_length')) {
                    $datalength = $info->data_length;
                }

                if (property_exists($info, 'Index_length')) {
                    $indexlength = $info->Index_length;
                } else if (property_exists($info, 'index_length')) {
                    $indexlength = $info->index_length;
                }

                $size = $datalength + $indexlength;
            }
        } else if ($CFG->dbtype === 'pgsql') {
            // PostgreSQL specific query.
            $sizequery = $DB->get_record_sql("
                SELECT pg_total_relation_size(schemaname||'.'||tablename) as total_size
                FROM pg_tables
                WHERE tablename = ?
            ", [$CFG->prefix . $table]);

            if ($sizequery && isset($sizequery->total_size)) {
                $size = (int)$sizequery->total_size;
            }
        } else {
            // For other databases, estimate size based on record count.
            // This is a rough approximation.
            $size = $count * 1024; // Assume 1KB per record on average.
        }
    } catch (Exception $e) {
        // If size calculation fails, just set to 0.
        $size = 0;
        mtrace("Could not calculate size for table $table: " . $e->getMessage());
    }

    mtrace(sprintf(
        "%s: %d records, %s",
        $table,
        $count,
        format_file_size($size)
    ));

    // Calculate statistics for specific time periods.
    $periods = [
        [null, '-1 year'], // From now to 1 year ago.
        ['-1 year', '-2 years'], // From 1 year ago to 2 years ago.
    ];

    foreach ($periods as $period) {
        list($from, $to) = $period;

        if ($from === null) {
            // Records newer than 1 year.
            $tocutoff = strtotime($to);
            $countperiod = $DB->count_records_select($table, "$datetimefield >= ?", [$tocutoff]);
            $sizeperiod = $count > 0 ? max(0, $size * ($countperiod / $count)) : 0;
            $perioddesc = sprintf("to %s", date('Y-m-d', $tocutoff));
        } else {
            // Records between 1 and 2 years old.
            $fromcutoff = strtotime($from);
            $tocutoff = strtotime($to);

            $countperiod = $DB->count_records_select(
                $table,
                "$datetimefield >= ? AND $datetimefield < ?",
                [$tocutoff, $fromcutoff]
            );


            $sizeperiod = $count > 0 ? max(0, $size * ($countperiod / $count)) : 0;

            $perioddesc = sprintf("from %s to %s",
                date('Y-m-d', $fromcutoff),
                date('Y-m-d', $tocutoff)
            );
        }

        mtrace(sprintf(
            "  %s (%s): %d records, %s",
            $table,
            $perioddesc,
            $countperiod,
            format_file_size($sizeperiod)
        ));
    }
}

mtrace("\nStatistics report completed.");
