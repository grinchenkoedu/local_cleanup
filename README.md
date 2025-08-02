# Moodle Clean-up Plugin

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
