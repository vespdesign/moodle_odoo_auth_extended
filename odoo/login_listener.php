<?php
function login_failed ($e) {
    $pass = $_REQUEST['password'];
    $user = $_REQUEST['username'];

    $odoo_auth = new auth_plugin_odoo();
    $odoo_auth->user_login($user, $pass);
}