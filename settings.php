<?php
/**
 * @var bool $hassiteconfig
 * @var admin_root $ADMIN
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $ADMIN->add(
        'root',
        new admin_category('local_cleanup', get_string('pluginname', 'local_cleanup'))
    );

    $ADMIN->add(
        'local_cleanup',
        new admin_externalpage(
            'local_cleanup_userfiles',
            get_string('files'),
            new moodle_url('/local/cleanup/files.php')
        )
    );

    $ADMIN->add(
        'local_cleanup',
        new admin_externalpage(
            'local_cleanup_ghostfiles',
            get_string('ghostfiles', 'local_cleanup'),
            new moodle_url('/local/cleanup/ghost.php')
        )
    );

    $ADMIN->add(
        'local_cleanup',
        new admin_externalpage(
            'local_cleanup_statistics',
            get_string('statistics'),
            new moodle_url('/local/cleanup/statistics.php')
        )
    );

    $settings = new admin_settingpage(
        'local_cleanup_admin',
        get_string('settingspage', 'local_cleanup')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(
        new admin_setting_configtext(
            'cleanup_items_per_page',
            get_string('itemsperpage', 'local_cleanup'),
            get_string('itemsperpagedesc', 'local_cleanup'),
            local_cleanup\finder::LIMIT_DEFAULT,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'cleanup_backup_timeout',
            get_string('backuplifetime', 'local_cleanup'),
            get_string('backuplifetimedesc', 'local_cleanup'),
            local_cleanup\task\cleanup::SECONDS_IN_MONTH,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'cleanup_draft_timeout',
            get_string('draftlifetime', 'local_cleanup'),
            get_string('draftlifetimedesc', 'local_cleanup'),
            local_cleanup\task\cleanup::SECONDS_IN_MONTH,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'cleanup_run_autoremove',
            get_string('autoremove', 'local_cleanup'),
            get_string('autoremovedesc', 'local_cleanup'),
            1 //enabled by default.
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'cleanup_delete_course_modules',
            get_string('deletecoursemodules', 'local_cleanup'),
            get_string('deletecoursemodulesdesc', 'local_cleanup'),
            0 //disabled by default.
        )
    );
}
