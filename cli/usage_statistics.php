<?php
/**
 * CLI script to display statistics about files and history tables
 * 
 * @global moodle_database $DB
 * @global object $CFG
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use local_cleanup\finder;

/**
 * Format file size with appropriate units
 * 
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

foreach ($components as $component => $batch_removal) {
    $stats = $finder->stats($component);

    mtrace(sprintf(
        "%s: %d files, %s",
        get_string($component, 'local_cleanup'),
        $stats->count,
        format_file_size($stats->size)
    ));

    // Calculate statistics for specific time periods
    $periods = [
        [null, '-1 year'], // From now to 1 year ago
        ['-1 year', '-2 years'], // From 1 year ago to 2 years ago
    ];

    foreach ($periods as $index => $period) {
        list($from, $to) = $period;

        if ($from === null) {
            // Files newer than 1 year
            $stats_period = $finder->stats($component, $to, true);

            $period_desc = sprintf("to %s", date('Y-m-d', strtotime($to)));
        } else {
            // Files between 1 and 2 years old
            // Use the new functionality to directly query for files in the specific time period
            $stats_period = $finder->stats($component, $to, false, $from);

            $period_desc = sprintf("from %s to %s", 
                date('Y-m-d', strtotime($from)),
                date('Y-m-d', strtotime($to))
            );
        }

        mtrace(sprintf(
            "  %s (%s): %d files, %s",
            get_string($component, 'local_cleanup'),
            $period_desc,
            $stats_period->count,
            format_file_size($stats_period->size)
        ));
    }
}

mtrace("\n=== HISTORY TABLE STATISTICS ===");
$tables = [
    'logstore_standard_log' => 'timecreated',
    'logstore_lanalytics_log' => 'timecreated',
    'grade_grades_history' => 'timemodified',
    'grade_items_history' => 'timemodified'
];

foreach ($tables as $table => $datetime_field) {
    if (!$DB->get_manager()->table_exists($table)) {
        mtrace("Table $table does not exist. Skipping.");
        continue;
    }

    $count = $DB->count_records($table);

    $size_query = $DB->get_records_sql("SHOW TABLE STATUS LIKE '{$CFG->prefix}{$table}'");
    $size = 0;
    foreach ($size_query as $info) {
        // Handle case sensitivity in property names
        $data_length = 0;
        $index_length = 0;

        if (property_exists($info, 'Data_length')) {
            $data_length = $info->Data_length;
        } else if (property_exists($info, 'data_length')) {
            $data_length = $info->data_length;
        }

        if (property_exists($info, 'Index_length')) {
            $index_length = $info->Index_length;
        } else if (property_exists($info, 'index_length')) {
            $index_length = $info->index_length;
        }

        $size = $data_length + $index_length;
    }

    mtrace(sprintf(
        "%s: %d records, %s",
        $table,
        $count,
        format_file_size($size)
    ));

    // Calculate statistics for specific time periods
    $periods = [
        [null, '-1 year'], // From now to 1 year ago
        ['-1 year', '-2 years'], // From 1 year ago to 2 years ago
    ];

    foreach ($periods as $period) {
        list($from, $to) = $period;

        if ($from === null) {
            // Records newer than 1 year
            $to_cutoff = strtotime($to);
            $count_period = $DB->count_records_select($table, "$datetime_field >= ?", [$to_cutoff]);
            $size_period = $count > 0 ? max(0, $size * ($count_period / $count)) : 0;
            $period_desc = sprintf("to %s", date('Y-m-d', $to_cutoff));
        } else {
            // Records between 1 and 2 years old
            $from_cutoff = strtotime($from);
            $to_cutoff = strtotime($to);

            $count_period = $DB->count_records_select(
                $table, 
                "$datetime_field >= ? AND $datetime_field < ?", 
                [$to_cutoff, $from_cutoff]
            );


            $size_period = $count > 0 ? max(0, $size * ($count_period / $count)) : 0;

            $period_desc = sprintf("from %s to %s", 
                date('Y-m-d', $from_cutoff),
                date('Y-m-d', $to_cutoff)
            );
        }

        mtrace(sprintf(
            "  %s (%s): %d records, %s",
            $table,
            $period_desc,
            $count_period,
            format_file_size($size_period)
        ));
    }
}

mtrace("\nStatistics report completed.");
