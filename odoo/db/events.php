<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_login_failed',
        'callback' => 'login_failed',
        'includefile' => 'auth/odoo/login_listener.php',
    ],
];