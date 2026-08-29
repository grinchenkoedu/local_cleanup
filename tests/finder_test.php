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
use stored_file;

/**
 * Tests for the file finder behind the files report.
 *
 * Every test scopes itself with a unique filename token, so it asserts against its own
 * fixtures rather than whatever else the test site happens to have in {files}.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\finder
 */
final class finder_test extends advanced_testcase {
    /**
     * Token making this test's fixtures distinguishable from any other file on the site.
     *
     * @var string
     */
    private $token;

    /**
     * Give each test its own filename token.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->token = 'tok' . random_string(12);
    }

    /**
     * Files above the size threshold are found and smaller ones are not.
     *
     * @return void
     */
    public function test_filters_by_size(): void {
        $this->resetAfterTest();

        $big = $this->create_file('large.txt', str_repeat('a', 1024 * 1024 + 1024));
        $this->create_file('small.txt', 'tiny');

        $found = $this->find(['filesize' => 1]);

        $this->assertSame([$big->get_id()], $found, 'Only the file over 1 MB should match.');
    }

    /**
     * count() must agree with the number of rows find() actually yields.
     *
     * The report prints one against the other, so a disagreement is visible to the user.
     *
     * @return void
     */
    public function test_count_agrees_with_the_rows_returned(): void {
        $this->resetAfterTest();

        $this->create_file('one.txt', 'first ' . $this->token);
        $this->create_file('two.txt', 'second ' . $this->token);
        $this->create_file('three.txt', 'third ' . $this->token);

        $filter = $this->filter();
        $finder = new finder($this->db());

        $this->assertCount(
            $finder->count($filter),
            $this->find(),
            'count() and find() must describe the same result set.'
        );
    }

    /**
     * Records sharing a content hash are each reported, because each is deleted separately.
     *
     * @return void
     */
    public function test_records_sharing_content_are_all_returned(): void {
        $this->resetAfterTest();

        $shared = 'shared payload ' . $this->token;
        $first = $this->create_file('copy_one.txt', $shared);
        $second = $this->create_file('copy_two.txt', $shared);

        $this->assertSame(
            $first->get_contenthash(),
            $second->get_contenthash(),
            'The fixtures must share a content hash for this test to mean anything.'
        );

        $found = $this->find();
        sort($found);
        $expected = [$first->get_id(), $second->get_id()];
        sort($expected);

        $this->assertSame($expected, $found, 'Both records reference the file pool and both are listed.');
    }

    /**
     * The filename filter matches on a substring.
     *
     * @return void
     */
    public function test_filters_by_filename(): void {
        $this->resetAfterTest();

        $wanted = $this->create_file('quarterly_report.txt', 'a ' . $this->token);
        $this->create_file('something_else.txt', 'b ' . $this->token);

        $this->assertSame(
            [$wanted->get_id()],
            $this->find(['name_like' => $this->token . '_quarterly'])
        );
        $this->assertSame(
            [$wanted->get_id()],
            $this->find(['name_like' => $this->token . '_QUARTERLY']),
            'The search is case-insensitive on every engine, not just the ones whose LIKE is.'
        );
    }

    /**
     * The component filter matches exactly.
     *
     * @return void
     */
    public function test_filters_by_component(): void {
        $this->resetAfterTest();

        $this->create_file('in_scope.txt', 'a ' . $this->token, 'local_cleanup');
        $this->create_file('out_of_scope.txt', 'b ' . $this->token, 'user');

        $found = $this->find(['component' => 'local_cleanup']);

        $this->assertCount(1, $found);
    }

    /**
     * Paging returns successive, non-overlapping pages.
     *
     * @return void
     */
    public function test_paging_does_not_repeat_or_skip_rows(): void {
        $this->resetAfterTest();

        for ($i = 0; $i < 5; $i++) {
            $this->create_file("page_$i.txt", "row $i " . $this->token);
        }

        $first = $this->find([], 2, 0);
        $second = $this->find([], 2, 2);
        $third = $this->find([], 2, 4);

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertCount(1, $third);
        $this->assertCount(
            5,
            array_unique(array_merge($first, $second, $third)),
            'Every row should appear on exactly one page.'
        );
    }

    /**
     * A filter matching nothing returns an empty result and a zero count.
     *
     * @return void
     */
    public function test_no_matches(): void {
        $this->resetAfterTest();

        $this->create_file('present.txt', 'a ' . $this->token);

        $filter = $this->filter(['name_like' => 'definitely_not_present']);
        $finder = new finder($this->db());

        $this->assertSame(0, $finder->count($filter));
        $this->assertSame([], $this->find(['name_like' => 'definitely_not_present']));
    }

    /**
     * stats() reports the number and total size of a component's files.
     *
     * @return void
     */
    public function test_stats_for_a_component(): void {
        $this->resetAfterTest();

        $component = 'assignsubmission_file';
        $finder = new finder($this->db());
        $before = $finder->stats($component);

        $first = $this->create_file('sub_one.txt', 'aaaa ' . $this->token, $component);
        $second = $this->create_file('sub_two.txt', 'bbbbbb ' . $this->token, $component);

        $after = $finder->stats($component);

        $this->assertSame(2, (int)$after->count - (int)$before->count);
        $this->assertSame(
            $first->get_filesize() + $second->get_filesize(),
            (int)$after->size - (int)$before->size
        );
    }

    /**
     * The owner filter matches a full name given in either order, and the author field.
     *
     * @return void
     */
    public function test_filters_by_owner(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user([
            'firstname' => 'Zoe',
            'lastname' => 'Quimby',
        ]);
        $other = $this->getDataGenerator()->create_user([
            'firstname' => 'Alan',
            'lastname' => 'Turing',
        ]);

        $wanted = $this->create_file('hers.txt', 'a ' . $this->token, 'local_cleanup', $owner->id);
        $this->create_file('his.txt', 'b ' . $this->token, 'local_cleanup', $other->id);

        $this->assertSame(
            [$wanted->get_id()],
            $this->find(['user_like' => 'Zoe Quimby']),
            'A full name in first-last order should match.'
        );
        $this->assertSame(
            [$wanted->get_id()],
            $this->find(['user_like' => 'Quimby Zoe']),
            'The same name in last-first order should match too.'
        );
    }

    /**
     * The deleted-owner filter keeps only files owned by a user marked deleted.
     *
     * The join to {user} is a LEFT JOIN, so this filter also excludes files with no owner at
     * all. That is the behaviour the report relies on; it is asserted here so a change to the
     * join does not pass unnoticed.
     *
     * @return void
     */
    public function test_filters_by_deleted_owner(): void {
        global $DB;

        $this->resetAfterTest();

        $gone = $this->getDataGenerator()->create_user();
        $present = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);

        $wanted = $this->create_file('orphan.txt', 'a ' . $this->token, 'local_cleanup', $gone->id);
        $this->create_file('owned.txt', 'b ' . $this->token, 'local_cleanup', $present->id);
        $this->create_file('ownerless.txt', 'c ' . $this->token, 'local_cleanup', null);

        $this->assertSame(
            [$wanted->get_id()],
            $this->find(['user_deleted' => '1']),
            'Only the file owned by the deleted user should be listed.'
        );
    }

    /**
     * Run a search and return the matching file ids.
     *
     * @param array $overrides Filter values to override the defaults with
     * @param int $limit Maximum rows
     * @param int $offset Rows to skip
     * @return int[] Ids of the matching records
     */
    private function find(array $overrides = [], int $limit = 100, int $offset = 0): array {
        $records = (new finder($this->db()))->find($limit, $offset, $this->filter($overrides));

        $ids = [];

        foreach ($records as $record) {
            $ids[] = (int)$record->id;
        }

        $records->close();

        return $ids;
    }

    /**
     * Build a filter scoped to this test's fixtures.
     *
     * @param array $overrides Filter values to override the defaults with
     * @return array Filter for finder
     */
    private function filter(array $overrides = []): array {
        return $overrides + [
            'filesize' => 0,
            'name_like' => $this->token,
            'user_like' => '',
            'component' => '',
            'user_deleted' => '',
        ];
    }

    /**
     * Create a file record for testing.
     *
     * @param string $filename Name of the file
     * @param string $content Content, which determines the size and the content hash
     * @param string $component Owning component
     * @param int|null $userid Owner of the file, if any
     * @return stored_file The created file
     */
    private function create_file(
        string $filename,
        string $content,
        string $component = 'local_cleanup',
        ?int $userid = null
    ): stored_file {
        $filerecord = [
            'contextid' => context_system::instance()->id,
            'component' => $component,
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $this->token . '_' . $filename,
            'userid' => $userid,
        ];

        return get_file_storage()->create_file_from_string($filerecord, $content);
    }

    /**
     * Get the database, which the finder takes as a dependency.
     *
     * @return \moodle_database
     */
    private function db(): \moodle_database {
        global $DB;

        return $DB;
    }
}
