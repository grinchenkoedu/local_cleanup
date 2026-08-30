# Changelog

All notable changes to the Moodle Clean-up Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [3.0] - 2026-08-30

The release that turns a working internal tool into a plugin. Nothing about what it deletes has
been loosened; most of the work went into making the scope of each deletion visible before it
happens, and into proving it with tests.

### Breaking
Two changes break code. Nothing an operator had working in 2.x stops working — for what to check
after upgrading, see *Upgrade notes* below.

- **Settings moved from `$CFG->cleanup_*` to the plugin's own `local_cleanup/` namespace** and
  were renamed. The upgrade migrates every existing value and clears the old one, so a site keeps
  its configuration, but anything reading `$CFG->cleanup_backup_timeout_days` and the rest
  directly must now go through `\local_cleanup\config`
- **The clean-up step classes were renamed and moved** from `classes/steps/CamelCase.php` to
  `classes/step/snake_case.php`, matching Moodle's own naming. Any code referencing them by
  class name must be updated

### Upgrade notes
Four things to check after upgrading from 2.x. None of them is a break; three are behaviour
changes that are easy to mistake for one.

- **Managers can now open the reports.** 2.x admitted only site administrators
  (`is_siteadmin()`). 3.0 grants `local/cleanup:view` to the `manager` archetype, so managers see
  both reports and the file owners' names. This is a widening — revoke it if it is not wanted
- **Only site administrators can delete.** `local/cleanup:deletefiles` is granted to no
  archetype, because deletion is irreversible; administrators keep it by holding everything.
  Grant it deliberately if somebody else needs it
- **Unlinked files are not removed for the first `ghostgracedays`.** The two new columns are not
  backfilled, so the first scan after the upgrade counts as the first of the two sightings that
  must agree, and the list sits still for a week. Working as intended, and easy to read as a
  regression
- **Any `$CFG->cleanup_*` lines left in `config.php` are now dead.** The upgrade clears the
  database config but cannot edit `config.php`. Delete them, or they will mislead the next
  person who reads the file

### Security
- Added a privacy provider. The plugin stores no personal data, but it had never said so, and
  the site's data registry listed it as not implemented
- The report tables escape every database value they render. `table_sql` does not escape cell
  contents, so each column that shows a stored value does it explicitly
- Spreadsheet exports neutralise formula injection: a file name or user name beginning `=`,
  `+`, `-` or `@` is prefixed so a spreadsheet shows it instead of running it

### Fixed
- The files report never worked on PostgreSQL. `finder` selected `f.*`, the user name fields
  and `u.deleted` while grouping by `f.contenthash` alone, which PostgreSQL rejects, so every
  search raised a database error. Records sharing a content hash are now listed separately,
  as each is deleted separately, which also makes the total agree with the rows shown
- Paging had no ordering, so a row could appear on two pages or on none. The report is now
  ordered by size, largest first, and is sortable
- The pager's total was invented. `files.php` capped it with `pow(10, 3) * ($page + 1)` because
  the count query and the select query were different SQL and had never agreed
- A deprecated implicit float-to-int conversion in the batch timing wrote a PHP notice on
  every batch under PHP 8.1 and above, and failed outright on Moodle 5.0
- The unlinked files page crashed when its scheduled task record was missing:
  `get_scheduled_task()` returns `false`, and `->get_next_run_time()` on `false` took the page
  down. The totals now show without a date
- The deleted-user filter could be switched on but never off. It was a plain checkbox, which
  submits nothing when cleared, so the value persisted across requests
- The filename filter matched case-sensitively on PostgreSQL and case-insensitively on MySQL.
  It now uses `sql_like()` and behaves the same on both

### Added
- **A dry run everywhere.** Every clean-up step implements `report()` alongside `execute()`, and
  the new `cli/cleanup.php` reports unless given `--execute`, so what a run would remove can be
  seen before it removes it. With `autoremove` off the scheduled task reports too, rather than
  doing nothing
- **A per-run ceiling.** `maxrecordsperrun` stops any one step removing more than a set number
  of records in a single run, so a long backlog is worked through over several nights instead
  of overrunning the cron window. Zero, the default, means no limit
- **A grace period before an unlinked file is removed.** Two scans a configurable interval apart
  must agree that a file is unreferenced. Content uploaded between scans can deduplicate onto a
  hash an earlier scan recorded as unlinked, and that file used to be destroyed
- A `\local_cleanup\event\file_deleted` event, so deletions appear in the site log with the
  file name, size and the user who removed it
- Both reports are `table_sql`: sorting, honest paging, and CSV or Excel download
- The unlinked files report shows when a file was first seen unlinked, with an *awaiting a
  second scan* badge, so it is clear why a listed file has not been removed yet
- The component filter is built from the components that actually own files on the site,
  replacing four hardcoded entries
- `cli/usage_statistics.php` and `cli/reinit_modules_cleanup.php` take proper arguments through
  `cli_get_params()`
- A plugin icon, and a summary block rendered from a mustache template
- **The plugin's first test suite** — 103 PHPUnit tests covering the file search, every
  clean-up step, the configuration accessors, the upgrade path, the capabilities, the report
  tables, the filter form and the privacy provider, plus Behat coverage of both reports and
  their access checks
- CI runs PHPUnit, Behat, Mustache, Grunt, copy/paste and mess detection across PHP 8.1, 8.3
  and 8.4 on Moodle 4.1, 4.5 and 5.0, and against MySQL as well as PostgreSQL. The second
  database engine exists because the PostgreSQL defect above survived two years of
  single-engine CI

### Changed
- **The cleanable component list is a setting, and it starts empty.** 2.x hardcoded
  `assignsubmission_file` and `backup`, and ran them only when `autoremove` was on. The upgrade
  seeds exactly that pair for a site that had it on, so behaviour is preserved; a site that had
  it off deleted no component files before and deletes none now.
  `config::CLEANABLE_COMPONENTS` is a fixed allowlist, so a stray database value cannot widen
  what may be deleted
- Settings have descriptions explaining what each one widens or narrows, and the ones that
  widen what may be deleted default to off
- `README.md` is written for the operator: capabilities, the dry-run workflow, cron, and the
  indexes this plugin adds to the core `files` table

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
| 3.0     | 4.1 – 5.0 | 7.4+ | ✅ Current |
| 2.3     | 4.1+   | 7.4+ | 📦 Archived |
| 2.1-2.2 | 4.1+   | 7.4+ | 📦 Archived |
| 2.0     | 4.0+   | 7.4+ | 📦 Archived |
| 1.x     | 3.9+   | 7.2+ | ❌ EOL |
