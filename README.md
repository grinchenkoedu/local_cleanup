# Moodle clean-up plugin

Main features:
* big files lookup and removal
* trash files auto-removal (files not related to any moodle entity, configurable)
* draft files auto-removal (configurable)
* temporary files auto-removal
* submissions disk usage statistics and batch removal

## Requirements
Moodle 4.1.x (probably works with newer versions but not tested!)

## How to use?
1. Install (copy files to `/local/cleanup` and update Moodle)
2. Open the "Administration" menu
3. Open the "Clean-up" menu
4. Open "Files" to review and search uploaded files
5. Open "Ghost files" to check "lost and found" files, ready for auto removal
6. Open "Statistics" to check total size of submissions and initiate batch removal

## Cron tasks
The auto clean-up functionality depends on the plugin tasks, please ensure that the crontab is configured to run moodle tasks:
```
* * * * * /usr/bin/run-one /usr/bin/php $MOODLE_DIR/admin/cli/cron.php --execute
```
Auto clean-up can be disabled in the plugin settings.

## Course modules clean-up
When you stuck with the course modules removal ("deletion in progress" issue), that maybe related to missing or corrupted 
removal adhoc tasks.

First ensure that you properly set up the adhoc cron job e.g.:
```
* * * * * /usr/bin/run-one /usr/bin/php $MOODLE_DIR/admin/cli/adhoc_task.php --execute
```

To re-init the clean-up process run the following script:
```bash
php $MOODLE_DIR/local/cleanup/cli/reinit_modules_cleanup.php
```
The script will remove exising removal tasks and create new for every course module selected for removal.

Additionally enable course module deletion in the plugin settings. Check cron logs for additional info.

## Q&A
**Q**: I don't want to remove backup or draft files automatically, can I disable this feature?
**A**: Yes and no. You can set the lifetime setting to something like 10 years.