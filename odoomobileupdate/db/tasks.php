<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_odoomobileupdate\task\update_mobile',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '0',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ],
];
