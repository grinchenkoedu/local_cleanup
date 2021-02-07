<?php

$tasks = [
    [
        'classname' => 'local_cleanup\task\scan',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '5',
        'day' => '*',
        'dayofweek' => '1',
        'month' => '*',
    ],
    [
        'classname' => 'local_cleanup\task\cleanup',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '3',
        'day' => '*',
        'dayofweek' => '1',
        'month' => '*',
    ],
];
