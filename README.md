# Moodle Clean-up Plugin

[![Moodle Plugin CI](https://github.com/grinchenkoedu/local_cleanup/actions/workflows/ci.yml/badge.svg)](https://github.com/grinchenkoedu/local_cleanup/actions/workflows/ci.yml)
[![Release](https://github.com/grinchenkoedu/local_cleanup/actions/workflows/release.yml/badge.svg)](https://github.com/grinchenkoedu/local_cleanup/actions/workflows/release.yml)
[![Latest release](https://img.shields.io/github/v/release/grinchenkoedu/local_cleanup?sort=semver)](https://github.com/grinchenkoedu/local_cleanup/releases)
[![Moodle](https://img.shields.io/badge/Moodle-4.1%20%7C%204.5%20%7C%205.0-orange)](https://moodle.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%20%7C%208.x-777bb4)](https://www.php.net)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)](LICENSE)

Finds and removes what an overloaded Moodle install has accumulated: oversized files, files the
database no longer references, outdated backups and drafts, and stale grade history and logs.

**It deletes things, so it defaults to telling you what it would delete.** Automatic removal is
off until you switch it on, the list of components it may touch starts empty, and the CLI
reports unless you pass `--execute`.

## Contents

- [Requirements](#requirements) · [Installation](#installation) · [Access](#access)
- [Seeing what would be removed](#seeing-what-would-be-removed) · [What automatic clean-up removes](#what-automatic-clean-up-removes) · [Settings](#settings)
- [Cron](#cron) · [Command line](#command-line) · [Database indexes](#database-indexes)
- [Stuck course modules](#stuck-course-modules) · [Stranded activities](#stranded-activities)

## Requirements

* Moodle 4.1 – 5.0
* PHP 7.4 or newer — the floor Moodle 4.1 itself sets

Continuous integration runs the full suite on PHP 8.1, 8.3 and 8.4 against Moodle 4.1, 4.5 and
5.0, on PostgreSQL and MySQL. PHP 7.4 gets a syntax check and a guard against 8.x-only
functions, because `moodle-plugin-ci` needs PHP 8 to run and so the suite itself cannot execute
there. If you run 7.4, the plugin is built for it and checked for it, but the tests that prove
behaviour run one version up.

## Installation

1. Copy the plugin to `local/cleanup` in your Moodle installation
2. Run the Moodle upgrade
3. Grant `local/cleanup:deletefiles` to whoever should be allowed to delete — see
   [Access](#access). Nobody has it by default
4. Review the settings at *Site administration → Plugins → Local plugins → Clean-up*
5. The reports are at *Site administration → Clean-up → Files* and *→ Unlinked files*

### Upgrading from 2.x

Copy the new files over and run the Moodle upgrade as usual. Your configuration carries over —
the `$CFG->cleanup_*` settings are migrated into the plugin's own namespace and the old values
removed, and a site that had auto-remove on keeps backups and assignment submissions ticked,
matching the pair 2.x hardcoded. Four things are worth checking afterwards:

1. **Managers can now open the reports.** 2.x admitted only site administrators. 3.0 grants
   `local/cleanup:view` to the `manager` archetype, so managers see both reports and the file
   owners' names. Revoke it if that is not what you want.
2. **Only site administrators can delete.** `local/cleanup:deletefiles` is granted to no
   archetype; administrators keep it because they hold everything. Grant it deliberately if
   somebody else needs it.
3. **Unlinked files will not be removed for the first `ghostgracedays`.** Existing rows are not
   backfilled, so the first scan after the upgrade counts as the first of the two sightings that
   have to agree, and the list sits still for a week. That is the point — see
   [the grace period](#what-automatic-clean-up-removes).
4. **Delete any `$CFG->cleanup_*` lines from `config.php`.** The upgrade migrates the value and
   clears the database config, but it cannot edit `config.php`; whatever is left there is dead.

Then run `php local/cleanup/cli/cleanup.php` to see what the new version would remove before you
let it act. The full list of changes is in [CHANGELOG.md](CHANGELOG.md#30---2026-08-30).

## Access

Two capabilities control the plugin, defined in `db/access.php`:

| Capability | Granted to by default | Allows |
|---|---|---|
| `local/cleanup:view` | `manager` | Opening both reports, and downloading their contents |
| `local/cleanup:deletefiles` | **nobody** | Deleting a file from the files report |

Deletion is granted to no role out of the box on purpose: it removes content irreversibly, so it
has to be assigned deliberately at *Site administration → Users → Permissions → Define roles*. A
site administrator has both regardless.

## Seeing what would be removed

Every clean-up step can report instead of act, and that is the default. Run this first, on any
site where you have not run the plugin before:

```sh
php local/cleanup/cli/cleanup.php
```

It prints what each step would remove and touches nothing. When the numbers look right:

```sh
php local/cleanup/cli/cleanup.php --execute
```

The scheduled task behaves the same way: with `autoremove` off it writes what it would have
removed to the cron log and removes nothing, so you can leave it running for a few nights and
read the output before committing to it.

## What automatic clean-up removes

Automatic removal is off by default. Switching it on enables the grade, log, unlinked-file and
outdated backup/draft steps.

**It deletes no component files until you choose a component.** The setting is a checkbox list:

| Component | Recommended | Note |
|---|---|---|
| Course and activity backups | yes | Regenerable, and usually the largest single consumer |
| Assignment file submissions | no | Student work |
| Assignment feedback files | no | Marker feedback |
| Recycle bin contents | no | Leaves recycle bin entries pointing at nothing |

Only backups are recommended, because they are the one entry that can be recreated. For the
others, the file is removed but the owning activity's own records stay behind, until the file
API migration in a later release.

Sites upgrading from 2.3 or earlier that already had automatic removal enabled keep the previous
behaviour: the upgrade ticks backups and assignment submissions, matching the pair that used to
be hardcoded. Sites with it disabled start with nothing ticked.

Two safeguards are worth knowing about:

- **Unlinked files need two scans to agree.** A file is only removed once two scans, at least
  `ghostgracedays` apart, both find nothing referencing it. Content uploaded between scans can
  deduplicate onto a hash an earlier scan recorded as unlinked, and without the grace period
  that file is destroyed.
- **A removed file goes to the trash directory** when nothing else references its content, so
  core's own trash clean-up task gives you a further recovery window. Shorten
  `file_trash_cleanup_task` at your peril.

## Settings

At *Site administration → Plugins → Local plugins → Clean-up*.

| Setting | Default | What it does |
|---|---|---|
| `autoremove` | off | Master switch. Off means the scheduled task reports and removes nothing |
| `componentfiles` | *(empty)* | Which components may lose files. Nothing is removed until you choose |
| `componentfileslifetimedays` | 180 | Age before a chosen component's files are removed |
| `backuplifetimedays` | 30 | Age before an outdated `.mbz` backup is removed |
| `draftlifetimedays` | 30 | Age before an abandoned draft file is removed |
| `logslifetimedays` | 500 | Age before a log entry is removed |
| `gradeslifetimedays` | 500 | Age before grade history is removed |
| `coursemoduleslifetimedays` | 7 | How long a failing module deletion is left before being forced |
| `ghostgracedays` | 7 | Interval the two scans must span before an unlinked file is removed |
| `maxrecordsperrun` | 0 | Ceiling per step per run. Zero means no limit |
| `itemsperpage` | 50 | Rows per page in the reports |

`maxrecordsperrun` is the one to reach for on a first run against a large backlog: it caps what
any single step removes in one night, so the work is spread over several runs instead of
overrunning the cron window. Whatever is left is picked up next time.

## Cron

Automatic clean-up relies on Moodle cron being configured:

```
* * * * * /usr/bin/run-one /usr/bin/php $MOODLE_DIR/admin/cli/cron.php --execute
```

> [!IMPORTANT]
> For large databases, it is *strongly recommended* to run the clean-up during off-peak hours as
> dedicated cron jobs. When doing that, disable the corresponding tasks in the Moodle scheduled
> tasks admin page so they do not also run inside the ordinary cron window.

Running a task by hand:

```sh
# Scan the file pool for unlinked files. Records what it finds; removes nothing.
php admin/cli/scheduled_task.php --execute="local_cleanup\task\scan"
# Run the clean-up steps. Honours the autoremove setting.
php admin/cli/scheduled_task.php --execute="local_cleanup\task\cleanup"
```

### Recommended core maintenance tasks

Worth running alongside this plugin:

```sh
php admin/cli/scheduled_task.php --execute="core\task\context_cleanup_task"
php admin/cli/scheduled_task.php --execute="core\task\file_temp_cleanup_task"
php admin/cli/scheduled_task.php --execute="core\task\file_trash_cleanup_task"
# Other database issues
php admin/cli/fix_course_sequence.php
php admin/cli/fix_deleted_users.php
php admin/cli/fix_orphaned_calendar_events.php
php admin/cli/fix_orphaned_question_categories.php
php admin/cli/check_database_schema.php
```

## Command line

| Script | What it does |
|---|---|
| `cli/cleanup.php` | Reports what every step would remove. `--execute` (`-e`) to actually remove it |
| `cli/usage_statistics.php` | File counts and sizes per component, and by age |
| `cli/reinit_modules_cleanup.php` | Rebuilds removal tasks for course modules stuck mid-deletion. Explains itself and stops unless given `--force` (`-f`) |
| `cli/fix_orphaned_instances.php` | Removes activities left with no course module. Reports unless given `--execute` (`-e`) |

All three take `--help` (`-h`).

## Database indexes

Earlier versions of this plugin added three indexes to the core `files` table from
`db/upgrade.php`. A plugin must not own indexes on a core table — `check_database_schema()`
reports them, so `php admin/cli/check_database_schema.php` flags every site running the plugin
with `Unexpected index`.

Version 2.3 dropped `component`. That one was redundant: core's `files` table already defines
`component-filearea-contextid-itemid`, whose leftmost column is `component`, so the same queries
are served either way and dropping it cost nothing.

Two remain, and **3.0 keeps them**:

| Index | Fields | Used by |
|---|---|---|
| `component_filesize` | `component, filesize` | the files report |
| `component_timecreated` | `component, timecreated` | component files clean-up, usage statistics |

An earlier plan was to drop them in 3.0 and have the plugin query its own indexed table instead.
Measuring a production site settled it the other way: nothing in core indexes `filesize`, so
`component_filesize` is what lets the report's count scan a secondary index rather than the whole
`files` table, and the queries it serves already return in milliseconds. A shadow table would
have added write cost and a synchronisation bug surface to replace something that is not slow.

If you would rather have the schema match core exactly, dropping them is safe — the plugin will
work, and on a small site you will not notice. Removing and restoring them:

```sql
DROP INDEX mdl_file_comfil_ix ON mdl_files;
DROP INDEX mdl_file_comtim_ix ON mdl_files;

CREATE INDEX mdl_file_comfil_ix ON mdl_files (component, filesize);
CREATE INDEX mdl_file_comtim_ix ON mdl_files (component, timecreated);
```

Substitute your own `$CFG->prefix` for `mdl_` if it differs. PostgreSQL wants
`DROP INDEX mdl_file_comfil_ix;` without the table name.

## Stuck course modules

Course modules stuck in *deletion in progress* usually mean the adhoc task that should have
removed them is missing or failing.

First make sure adhoc tasks are actually running:

```
* * * * * /usr/bin/run-one /usr/bin/php $MOODLE_DIR/admin/cli/adhoc_task.php --execute
```

If they are, and modules are still stuck:

```sh
# Explains what it would do, and stops.
php $MOODLE_DIR/local/cleanup/cli/reinit_modules_cleanup.php
# Actually does it.
php $MOODLE_DIR/local/cleanup/cli/reinit_modules_cleanup.php --force
```

This removes the existing removal tasks and creates fresh ones for every course module marked
for deletion. The clean-up task will also force through modules whose removal task has been
failing for longer than `coursemoduleslifetimedays`.

## Stranded activities

The opposite problem, and a noisier one. When a deletion fails part-way the course module can go
while the activity's own row stays, and an activity nothing points at is unreachable from the
site but still visible to cron:

```
Scheduled task failed: ... (mod_assign\task\cron_task), Can not find data record in database table.
... 'instance' => '126355', 'modulename' => 'assign',
```

The task fails on the first row it cannot resolve, and keeps failing: a failed task's
`lastruntime` is not advanced, so the window it searches only widens. Everything after it in that
task is skipped too — for assignments that means the `assignsubmission_*` and `assignfeedback_*`
crons as well.

Nothing in core clears this. `fix_course_sequence.php` is the closest, and it only reconciles
`course_sections.sequence` against `course_modules`; it never opens a module's own table.

```sh
# Counts and names them, per module. Removes nothing.
php $MOODLE_DIR/local/cleanup/cli/fix_orphaned_instances.php
# Narrow it: one module, one course, or one activity.
php $MOODLE_DIR/local/cleanup/cli/fix_orphaned_instances.php --modules=assign
php $MOODLE_DIR/local/cleanup/cli/fix_orphaned_instances.php --courseid=1234
php $MOODLE_DIR/local/cleanup/cli/fix_orphaned_instances.php --modules=assign --instanceid=126355 --execute
```

`--courseid` works for a course that has already been deleted, because the activity row keeps the
id of the course it belonged to. `--limit=N` stops a run after N removals, for working through a
large backlog over several nights; the report always counts the lot.

What it does depends on whether that course survived:

- **The course still exists.** The course module is put back — hidden, and marked for deletion
  from the moment it exists — and core's own `course_delete_module()` removes the activity with
  its grades, files, calendar events, tags and context. If anything fails part-way, what is left
  is a stuck course module, which the section above deals with.
- **The course has gone too.** Core cannot be used at all: every module's `delete_instance()`
  finds the activity through the course module and the course. The activity row and its calendar
  entries are deleted directly, and the module's own child rows — `assign_submission` and the
  like — are left behind. They reference an activity that no longer exists, which breaks nothing.

Activities modified within the last `--days` (default 7) are left alone. Having no course module
yet is a normal state part-way through creating an activity or restoring a course, and that grace
period is what keeps the repair away from one.

This is a repair, not maintenance. It runs when you run it; nothing here is added to cron.

## Licence

GPL v3 or later. See [LICENSE](LICENSE).
