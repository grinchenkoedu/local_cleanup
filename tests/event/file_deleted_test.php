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

namespace local_cleanup\event;

use advanced_testcase;
use coding_exception;
use context_system;

/**
 * Tests for the file deletion event.
 *
 * remove.php is a page script and cannot be driven from PHPUnit, so what is asserted here is
 * the event definition itself: that it validates what its description needs, survives a round
 * trip through the event system, and keeps hold of the record it describes after that record
 * has gone.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\event\file_deleted
 */
final class file_deleted_test extends advanced_testcase {
    /**
     * A well-formed event carries its details through the event system.
     *
     * @return void
     */
    public function test_event_is_triggered_with_its_details(): void {
        $this->resetAfterTest();

        $sink = $this->redirectEvents();
        $this->create_event()->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);

        $event = reset($events);

        $this->assertInstanceOf(file_deleted::class, $event);
        $this->assertSame('files', $event->objecttable);
        $this->assertSame(42, $event->objectid);
        $this->assertSame('d', $event->crud);
        $this->assertSame('essay.txt', $event->other['filename']);
        $this->assertStringContainsString('essay.txt', $event->get_description());
        $this->assertStringContainsString('assignsubmission_file', $event->get_description());
    }

    /**
     * The snapshot survives the record it describes, which is the point of taking one.
     *
     * @return void
     */
    public function test_the_deleted_record_is_still_readable_from_the_event(): void {
        global $DB;

        $this->resetAfterTest();

        $file = get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'local_cleanup',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'essay.txt',
        ], 'content to be destroyed');

        $id = (int)$file->get_id();
        $record = $DB->get_record('files', ['id' => $id], '*', MUST_EXIST);

        $event = $this->create_event($id);
        $event->add_record_snapshot('files', $record);

        $file->delete();

        $this->assertFalse(
            $DB->record_exists('files', ['id' => $id]),
            'The record must really be gone for this test to mean anything.'
        );
        $this->assertSame(
            'essay.txt',
            $event->get_record_snapshot('files', $id)->filename,
            'An observer must still be able to see what was removed.'
        );
    }

    /**
     * The event refuses to be built without what its description reads.
     *
     * @return void
     */
    public function test_missing_details_are_rejected(): void {
        $this->resetAfterTest();

        $this->expectException(coding_exception::class);

        file_deleted::create([
            'context' => context_system::instance(),
            'objectid' => 42,
            'other' => [
                'filename' => 'essay.txt',
                // Deliberately missing filesize, component and contenthash.
            ],
        ]);
    }

    /**
     * Build a valid event.
     *
     * @param int $objectid Id of the file the event describes
     * @return file_deleted The event, not yet triggered
     */
    private function create_event(int $objectid = 42): file_deleted {
        return file_deleted::create([
            'context' => context_system::instance(),
            'objectid' => $objectid,
            'other' => [
                'filename' => 'essay.txt',
                'filesize' => 1024,
                'component' => 'assignsubmission_file',
                'contenthash' => str_repeat('a', 40),
            ],
        ]);
    }
}
