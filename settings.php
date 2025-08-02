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
            'cleanup_backup_timeout_days',
            get_string('backuplifetime', 'local_cleanup'),
            get_string('backuplifetimedesc', 'local_cleanup'),
            30,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'cleanup_draft_timeout',
            get_string('draftlifetime', 'local_cleanup'),
            get_string('draftlifetimedesc', 'local_cleanup'),
            30,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'cleanup_logs_timeout_days',
            get_string('logslifetime', 'local_cleanup'),
            get_string('logslifetimedesc', 'local_cleanup'),
            500,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'cleanup_component_files_days',
            get_string('componentfileslifetime', 'local_cleanup'),
            get_string('componentfileslifetimedesc', 'local_cleanup'),
            180,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'cleanup_grades_days',
            get_string('gradeslifetime', 'local_cleanup'),
            get_string('gradeslifetimedesc', 'local_cleanup'),
            500,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'cleanup_course_modules_days',
            get_string('coursemoduleslifetime', 'local_cleanup'),
            get_string('coursemoduleslifetimedesc', 'local_cleanup'),
            7,
            PARAM_INT
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'cleanup_run_autoremove',
            get_string('autoremove', 'local_cleanup'),
            get_string('autoremovedesc', 'local_cleanup'),
            0 //disabled by default.
        )
    );
}
