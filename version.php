<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @author Yevhen Matasar <matasar.ei@gmail.com>
 *
 * @var $plugin stdClass
 */

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.'); 

$plugin->component = 'local_cleanup';
$plugin->version = 2025061600;
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '2.0';
$plugin->requires = 2022041200; // Moodle 4.1 (LTS)
