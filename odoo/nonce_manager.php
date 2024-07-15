<?php

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');
}

function debugNonce()
{
    echo '<pre>';
    var_dump($_SESSION['nonce']);
    echo '</pre>';
}


function createNonce($action)
{
    if (!isset($_SESSION['nonce'])) {
        $_SESSION['nonce'] = array();
    }
    if (!isset($_SESSION['nonce'][$action])) {
        $_SESSION['nonce'][$action] = [];
    }
    $nonce = md5(uniqid(rand() . '-' . $action, true));
    $_SESSION['nonce'][$action][] = $nonce;
    return $nonce;
}


function checkNonce($action, $nonce)
{
    if (!isset($_SESSION['nonce']) ||
        !isset($_SESSION['nonce'][$action]) ||
        !in_array($nonce, $_SESSION['nonce'][$action])) {
        return false;
    }
    $_SESSION['nonce'][$action] = array_diff($_SESSION['nonce'][$action], [$nonce]);
    return true;
}
