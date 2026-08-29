# Moodle Clean-up Plugin

[![Moodle Plugin CI](https://github.com/grinchenkoedu/local_cleanup/workflows/Moodle%20Plugin%20CI/badge.svg)](https://github.com/grinchenkoedu/local_cleanup/actions)
[![Release](https://github.com/grinchenkoedu/local_cleanup/workflows/Release/badge.svg)](https://github.com/grinchenkoedu/local_cleanup/actions)

A comprehensive Moodle plugin that manages and optimizes file storage and the database by automatically identifying and 
removing unnecessary files and records.

## Key Features

* UI to find and remove large files
* Automatic removal of orphaned files (files not associated with any Moodle entity, configurable)
* Automatic removal of old submissions and backups (configurable)
* Automatic grades history clean-up (configurable)
* Automatic logs clean-up (configurable)

## Requirements

* Moodle 4.1.x or newer (compatibility with newer versions not fully tested)

## Installation and Usage

1. Install the plugin by copying all files to the `/local/cleanup` directory in your Moodle installation
2. Run the Moodle upgrade process
3. Ensure correct plugin settings: auto-remove, logs and files lifetime
4. Access the plugin pages through:
   * Administration → Clean-up → Files - to review and manage uploaded files
   * Administration → Clean-up → Unlinked files - to identify and manage orphaned files

## Database Indexes

Earlier versions of this plugin added three indexes to the core `files` table from
`db/upgrade.php`. A plugin must not own indexes on a core table — `check_database_schema()`
reports them, so `php admin/cli/check_database_schema.php` flags every site running the plugin
with `Unexpected index`.

Version 2.3 drops `component`. That one is redundant: core's `files` table already defines
`component-filearea-contextid-itemid`, whose leftmost column is `component`, so the same
queries are served either way and dropping it costs nothing.

Two are left in place for now, because they carry real weight on a large site and nothing has
replaced them yet:

| Index | Fields | Used by |
|---|---|---|
| `component_filesize` | `component, filesize` | the files report, when a component filter is set |
| `component_timecreated` | `component, timecreated` | component files clean-up, usage statistics |

Version 3.0 removes both, once the plugin maintains its own indexed table to query instead. If
you still want them after that upgrade, a DBA can add them back deliberately — accepting that
the site's schema will then differ from core, and that `check_database_schema.php` will say so:

```sql
CREATE INDEX mdl_file_comfil_ix ON mdl_files (component, filesize);
CREATE INDEX mdl_file_comtim_ix ON mdl_files (component, timecreated);
```

Substitute your own `$CFG->prefix` for `mdl_` if it differs.

## Access

Two capabilities control the plugin, defined in `db/access.php`:

| Capability | Granted to by default | Allows |
|---|---|---|
| `local/cleanup:view` | `manager` | Opening both reports |
| `local/cleanup:deletefiles` | **nobody** | Deleting a file from the files report |

Deletion is granted to no role out of the box on purpose: it removes content irreversibly, so
it has to be assigned deliberately at Site administration → Users → Permissions → Define roles.
A site administrator has both regardless.

## What Automatic Clean-up Removes

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

Sites upgrading from 2.3 or earlier that already had automatic removal enabled keep the
previous behaviour: the upgrade ticks backups and assignment submissions, matching the pair
that used to be hardcoded. Sites with it disabled start with nothing ticked.

## Cron Tasks

The automatic clean-up functionality relies on properly configured Moodle cron tasks. Ensure your crontab includes:

```
* * * * * /usr/bin/run-one /usr/bin/php $MOODLE_DIR/admin/cli/cron.php --execute
```

> [!IMPORTANT]
> For large databases, it is *strongly recommended* to run the cleanup during off-peak hours as dedicated cron jobs. 
> When using this approach, make sure to disable the corresponding tasks in the Moodle scheduled tasks (admin panel).

### Manual Start
```sh
# To scan the file directory for orphaned files (no removal)
php admin/cli/scheduled_task.php --execute="local_cleanup\task\scan"
# To execute database and files clean-up
php admin/cli/scheduled_task.php --execute="local_cleanup\task\cleanup"
```

### Recommended Moodle Built-in Maintenance Tasks

For optimal system maintenance, consider running these built-in Moodle tasks:

```sh
# Look for more clean-up tasks in the cron configuration
php admin/cli/scheduled_task.php --execute="core\task\context_cleanup_task"
php admin/cli/scheduled_task.php --execute="core\task\file_temp_cleanup_task"
php admin/cli/scheduled_task.php --execute="core\task\file_trash_cleanup_task"
# To fix other database issues
php admin/cli/fix_course_sequence.php
php admin/cli/fix_deleted_users.php
php admin/cli/fix_orphaned_calendar_events.php
php admin/cli/fix_orphaned_question_categories.php
php admin/cli/check_database_schema.php
```

## Course Module Clean-up

If you encounter issues with course modules stuck in "deletion in progress" state, this may be related to missing or corrupted removal tasks.

### Prerequisites

Ensure you have properly configured the adhoc task cron job:

```
* * * * * /usr/bin/run-one /usr/bin/php $MOODLE_DIR/admin/cli/adhoc_task.php --execute
```

### Manual fix

Run the following script to reinitialize the clean-up process:
```bash
php $MOODLE_DIR/local/cleanup/cli/reinit_modules_cleanup.php
```
This script removes existing removal tasks and creates new ones for each course module marked for deletion.
