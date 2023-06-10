# Moodle clean-up plugin

Main features:
* helps to find big files to clean-up disk and database;
* auto-remove garbage files from the moodle files directory (configurable);
* auto-remove draft files (configurable);
* auto-remove temporary files;
* statistics and batch files removal.

The plugin tested on the real production Moodle system with millions of files (~2Tb) and a huge database (>5000k records).
For better performance add additional indexes manually.

## Minimal requirements
* PHP >= 7.0
* Moodle 3.4 or later (only 3.x tested)

## How to use?

1. Install (copy files to `/local/cleanup` and update Moodle);
2. Navigate to administration menu;
3. Find the "Clean-up" menu;
4. Open "Files" to check files uploaded by users;
5. Open "Ghost files" to check "lost and found" files, ready for automatic removal.

## Cron tasks
The auto clean-up functionality depends on the plugin tasks, please make sure that the crontab is configured to run moodle tasks.

Auto clean-up can be disabled in the plugin settings.

## Q&A
**Q**: I don't want to remove backup or draft files automatically, can I disable this feature?
**A**: Yes and no. You can set the lifetime setting to something like 10 years.