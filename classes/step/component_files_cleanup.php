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

namespace local_cleanup\step;

use local_cleanup\output\output_interface;
use moodle_database;

/**
 * Component files cleanup step.
 *
 * Handles cleanup of files from specific components based on age.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class component_files_cleanup extends base {
    /**
     * Default number of days to keep component files.
     */
    const DEFAULT_LIFETIME_DAYS = 180;

    /**
     * List of components to clean up.
     *
     * @var array
     */
    private $components;

    /**
     * Number of days to keep files.
     *
     * @var int
     */
    private $daystokeep;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param array $components List of component names to clean up
     * @param int $daystokeep Number of days to keep files
     */
    public function __construct(moodle_database $db, array $components, int $daystokeep = self::DEFAULT_LIFETIME_DAYS) {
        parent::__construct($db);

        $this->components = $components;
        $this->daystokeep = $daystokeep;
    }

    /**
     * Name this step.
     *
     * @return string Short human-readable name
     */
    public function get_name(): string {
        return 'Component files';
    }

    /**
     * One candidate set per component the administrator opted in to.
     *
     * @return array[] Candidate sets
     */
    protected function get_candidates(): array {
        $cutoffdate = time() - ($this->daystokeep * DAYSECS);
        $candidates = [];

        foreach ($this->components as $component) {
            // Each candidate set is run as its own query, so the placeholder names are local
            // to it and need no suffix. Component names are long enough that suffixing them
            // breaks Moodle's 30 character limit anyway.
            $candidates[] = [
                'table' => 'files',
                'sql' => 'SELECT f.id
                            FROM {files} f
                           WHERE f.component = :component
                             AND f.timecreated < :cutoffdate',
                'params' => [
                    'component' => $component,
                    'cutoffdate' => $cutoffdate,
                ],
                'message' => sprintf('%s files older than %d days', $component, $this->daystokeep),
            ];
        }

        return $candidates;
    }
}
