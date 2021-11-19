# Moodle clean-up plugin

Main features:
* helps to find big files to clean-up disk and database;
* auto-remove garbage files from the moodle files directory (configurable);
* auto-remove courses backups (configurable).
* auto-remove draft files (configurable).
* clean-up cache and temporary files.

The plugin tested on the real production Moodle system with lots of files (~2Tb) and huge database (>5000k records).
It's recommended to add additional indexes manually fot better performance.

## Requirements
* PHP => 7.0
* Moodle => 3.6

## How to use?

1. Install;
2. Navigate to administration menu;
3. Find the "Moodle clean-up" menu;
4. Open "Users files" to check files uploaded by users;
5. Open "Ghost files" to check garbage files, ready for automatic removal.

## Cron tasks
The auto clean-up functionality depends on the plugin tasks, please sure that crontab is configured to run moodle tasks.

Auto clean-up can be disabled in the plugin settings.

## Q&A

**Q**: I don't want to remove backup or draft files automatically, can I disable this feature?
**A**: Yes and no. You can set the lifetime setting to something like 10 years.