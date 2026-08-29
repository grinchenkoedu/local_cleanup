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

namespace local_cleanup;

use advanced_testcase;
use context_system;

/**
 * Tests for the capability definitions.
 *
 * The entry points call require_capability(), whose behaviour is core's. What is worth
 * asserting is what db/access.php actually grants, since a wrong archetype there hands the
 * ability to destroy files to every manager on the site.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class access_test extends advanced_testcase {
    /**
     * A manager can read the reports.
     *
     * @return void
     */
    public function test_manager_may_view(): void {
        $this->resetAfterTest();

        $context = context_system::instance();
        $manager = $this->create_user_with_role('manager');

        $this->assertTrue(has_capability('local/cleanup:view', $context, $manager));
    }

    /**
     * A manager may not delete. Deletion is granted to no archetype on purpose.
     *
     * @return void
     */
    public function test_manager_may_not_delete(): void {
        $this->resetAfterTest();

        $context = context_system::instance();
        $manager = $this->create_user_with_role('manager');

        $this->assertFalse(
            has_capability('local/cleanup:deletefiles', $context, $manager),
            'Deletion is irreversible and must be assigned to a role deliberately.'
        );
    }

    /**
     * An ordinary authenticated user has neither capability.
     *
     * @return void
     */
    public function test_plain_user_has_neither(): void {
        $this->resetAfterTest();

        $context = context_system::instance();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(has_capability('local/cleanup:view', $context, $user));
        $this->assertFalse(has_capability('local/cleanup:deletefiles', $context, $user));
    }

    /**
     * A teacher has neither: these are system-level capabilities, not course ones.
     *
     * @return void
     */
    public function test_teacher_has_neither(): void {
        $this->resetAfterTest();

        $context = context_system::instance();
        $teacher = $this->create_user_with_role('editingteacher');

        $this->assertFalse(has_capability('local/cleanup:view', $context, $teacher));
        $this->assertFalse(has_capability('local/cleanup:deletefiles', $context, $teacher));
    }

    /**
     * A site administrator keeps both, as they bypass capability checks entirely.
     *
     * @return void
     */
    public function test_site_administrator_has_both(): void {
        $this->resetAfterTest();

        $context = context_system::instance();
        $admin = get_admin();

        $this->assertTrue(has_capability('local/cleanup:view', $context, $admin));
        $this->assertTrue(has_capability('local/cleanup:deletefiles', $context, $admin));
    }

    /**
     * Create a user holding a role at the system context.
     *
     * @param string $shortname Role short name
     * @return \stdClass The created user
     */
    private function create_user_with_role(string $shortname): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);

        role_assign($roleid, $user->id, context_system::instance()->id);

        return $user;
    }
}
