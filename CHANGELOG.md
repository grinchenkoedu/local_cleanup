# Changelog

All notable changes to the Moodle Clean-up Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Fixed
- The files report never worked on PostgreSQL. `finder` selected `f.*`, the user name fields
  and `u.deleted` while grouping by `f.contenthash` alone, which PostgreSQL rejects, so every
  search raised a database error. Records sharing a content hash are now listed separately,
  as each is deleted separately, which also makes the total agree with the rows shown
- Paging had no ordering, so a row could appear on two pages or on none. The report is now
  ordered by size, largest first
- A deprecated implicit float-to-int conversion in the batch timing wrote a PHP notice on
  every batch under PHP 8.1 and above, and failed outright on Moodle 5.0

### Added
- The plugin's first test suite: PHPUnit coverage of the file search and of every clean-up
  step except course modules, plus Behat coverage of both reports and their access checks
- CI now runs PHPUnit, Behat, Mustache, Grunt, copy/paste and mess detection, and tests
  against MySQL as well as PostgreSQL

## [2.3] - 2026-08-28

### Security
- Fixed a path traversal in `download.php`: the `path` parameter was concatenated onto
  `$CFG->dataroot` as `PARAM_TEXT`, allowing arbitrary files to be read from disk. The
  parameter is now `PARAM_PATH` and the resolved path must sit inside `dataroot/filedir`
- Added `require_sesskey()` to file removal, which previously deleted on a plain GET
- Restricted the `redirect` parameter of `remove.php` to a local URL
- Escaped all database values rendered into the files and unlinked-files tables;
  `html_writer::table()` and `html_writer::link()` do not escape their contents

### Fixed
- Removing a file from the web UI deleted **every** `files` record sharing its content hash.
  Moodle deduplicates file content, so deleting one large backup also removed every other
  record pointing at the same bytes. Removal now goes through `stored_file::delete()`, which
  deletes a single record and moves the content to the trash directory only when nothing else
  references it
- Outdated `.mbz` backups were unlinked without checking whether another record shared the
  same content hash, breaking those records. The check the draft-file branch already performed
  now applies to backups too
- `FilesCheckout` ran even when auto-remove was disabled, so sites that had deliberately left
  the setting off still had backups and draft files deleted by the scheduled task
- Unlinked files recorded by the scan task were deleted without re-checking that they were
  still unreferenced. A file uploaded between the scan and the clean-up whose content
  deduplicated onto a recorded hash was destroyed
- Defined the missing `unknowncontext` language string, which rendered as
  `[[unknowncontext]]`
- Guarded the unlinked-files total against a null `SUM()` on an empty table
- Closed a leaked recordset in the unlinked-files clean-up step

### Changed
- An outdated backup whose content is still shared by another record is now retained rather
  than removed, so this release reclaims less disk than 2.2 did. Correctness first; the
  migration to the File API follows in 3.0

### Removed
- The redundant `component` index this plugin added to the core `files` table. Core already
  ships `component-filearea-contextid-itemid`, whose leftmost column is `component`, so the
  same queries are served either way. It is dropped on upgrade where it exists, and the 2023
  upgrade step no longer creates it, so a site upgrading from 1.x does not build an index over
  the whole `files` table only to drop it again in the same run. `component_filesize` and
  `component_timecreated` remain for now and are documented in `README.md`
- Six unused language strings: `batchremovaldone`, `directorylifetime`,
  `directorylifetimedesc`, `removeconfirm`, `title`, `userfiles`

## [2.2] - 2025-08-07

### Added
- Moodle Plugin CI workflow for automated code quality and standards checking

### Changed
- Updated code style to match Moodle standards

## [2.1] - 2025-08-02

### Fixed
- Check whether table `logstore_lanalytics_log` exists and skip its cleanup if not existent

## [2.0] - 2024-07-05

### Added
- Logs clean-up
- Grades clean-up
- Course modules clean-up
- CLI script for fixing stuck course module deletions (`cli/reinit_modules_cleanup.php`)
- Statistics and usage reporting via CLI (`cli/usage_statistics.php`)
- Batch file removal operations

### Changed
- Improved database cleanup, implemented dedicated clean-up steps
- Improved performance for large file operations

### Removed
- Statistics and batch removal web UI

## [1.4] - 2024-12-07

### Changed
- Compatibility improvements for Moodle 4.1 LTS

## [1.3] - 2023-06-10

### Added
- Initial plugin release
- Files clean-up functionality
- Files clean-up management (web UI)

## Compatibility

| Version | Moodle | PHP | Status |
|---------|--------|-----|--------|
| 2.3     | 4.1+   | 7.4+ | ✅ Current |
| 2.1-2.2 | 4.1+   | 7.4+ | 📦 Archived |
| 2.0     | 4.0+   | 7.4+ | 📦 Archived |
| 1.x     | 3.9+   | 7.2+ | ❌ EOL |
