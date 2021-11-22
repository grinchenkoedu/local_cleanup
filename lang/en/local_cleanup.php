<?php

$string['pluginname'] = 'Moodle clean-up';
$string['title'] = 'Clean-up';
$string['userfiles'] = 'Users files';
$string['ghostfiles'] = 'Ghost files';

$string['ghosttotalheader'] = 'Total files found: {$a->files}, total size: {$a->size}Gb, next clean-up: {$a->cleanup_date}';
$string['nothingtoshow'] = 'Nothing to show';
$string['removeconfirm'] = 'You about to remove file "{$a->name}" with id "{$a->id}". Are you sure?';
$string['fileremoved'] = 'File "{$a->name}" removed, {$a->size}Mb cleaned';
$string['failtoremove'] = 'Failed to remove file "{$a->name}"';
$string['settingspage'] = 'Clean-up settings';
$string['itemsperpage'] = 'Items per page';
$string['itemsperpagedesc'] = 'Affects performance';
$string['backuplifetime'] = 'Backup files lifetime';
$string['backuplifetimedesc'] = 'Time in seconds';
$string['draftlifetime'] = 'Backup files lifetime';
$string['draftlifetimedesc'] = 'Time in seconds';
$string['autoremove'] = 'Auto remove outdated files';
$string['autoremovedesc'] = 'Remove outdated files found on the filesystem on next clean-up';
$string['alsowillberemoved'] = 'Also will be removed ({$a->contenthash}) : ';
