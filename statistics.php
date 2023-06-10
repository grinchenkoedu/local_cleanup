<?php
/**
 * @global moodle_page $PAGE
 * @global moodle_database $DB
 * @global stdClass $USER
 * @global stdClass $CFG
 * @global renderer_base $OUTPUT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

use local_cleanup\finder;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/statistics.php');
$PAGE->set_title(get_string('statistics'));
$PAGE->set_heading(get_string('statistics'));
$PAGE->set_pagelayout('admin');

require_login();

$is_admin = is_siteadmin();
$finder = new finder($DB, $USER->id, $is_admin);
$components = [
    'assignsubmission_file' => true,
    'backup' => false,
];

$table = new html_table();
$table->head = [
    get_string('component', 'cache'),
    get_string('files'),
    get_string('size'),
    get_string('actions')
];

$table->size = ['40%', '25%', '25%', '10%'];

foreach ($components as $component => $batch_removal) {
    $stats = $finder->stats($component);
    $table->data[] = [
        get_string($component, 'local_cleanup'),
        $stats->count,
        sprintf(
            '%.1f ' . get_string('sizegb'),
            $stats->size / pow(1024, 3)
        ),
        '-',
    ];

    foreach (['-1 year', '-2 years'] as $until) {
        $removalLink = html_writer::link(
            new moodle_url(
                '/local/cleanup/batch_removal.php',
                [
                    'component' => $component,
                    'until' => $until
                ]
            ),
            get_string('remove'),
            [
                'style' => 'color:red',
            ]
        );
        $stats_until = $finder->stats($component, $until);

        $table->data[] = [
            sprintf(
                '%s, %s %s',
                get_string($component, 'local_cleanup'),
                get_string('to'),
                date('Y-m-d', strtotime($until))
            ),
            $stats_until->count,
            sprintf(
                '%.1f ' . get_string('sizegb'),
                $stats_until->size / pow(1024, 3)
            ),
            $batch_removal && $is_admin ? $removalLink : '-',
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('statistics'));

echo html_writer::table($table);

echo $OUTPUT->footer();
