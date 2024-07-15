<?php
require_once '../../config.php';
require_once './auth.php';
require_once './nonce_manager.php';
require_once($CFG->dirroot . '/cohort/lib.php');

// Filtro REFERER
if (preg_match('/(subdomain1before|subdomain2after).domain.xxx/', $_SERVER['HTTP_REFERER'])) {
    $odoo = preg_match('/subdomain1before/', $_SERVER['HTTP_REFERER']);
    $auth_user = required_param('email', PARAM_TEXT);
    $nonce = optional_param('nonce', '', PARAM_TEXT);
} else {
    redirect($_SERVER['HTTP_REFERER'] ?? $CFG->wwwroot);
}

if (!$odoo && !checkNonce('login', $nonce)) {
    redirect($CFG->wwwroot);
}

// Obtener el UID del administrador de Odoo
$uid = xmlrpc_request(
    get_config('auth/odoo', 'url') . '/xmlrpc/2/common',
    'authenticate',
    array(
        get_config('auth/odoo', 'db'),
        get_config('auth/odoo', 'user'),
        get_config('auth/odoo', 'password'),
        array()
    )
);

// Obtener el ID de usuario de Odoo del usuario que inicia sesión
$user_ids = xmlrpc_request(
    get_config('auth/odoo', 'url') . '/xmlrpc/2/object',
    'execute_kw',
    array(
        get_config('auth/odoo', 'db'),
        $uid,
        get_config('auth/odoo', 'password'),
        'res.users',
        'search',
        array(
            array(array('login', '=', $auth_user)),
        ),
    )
);

// Verificar si existe el usuario local y, en caso afirmativo, iniciar sesión
global $DB;
$user = $DB->get_record('user', array('email' => $auth_user));
if ($user && !empty($user_ids) && complete_user_login($user)) {
    // Actualizar el método de autenticación a 'odoo'
    $user->auth = 'odoo';
    $DB->update_record('user', $user);

    // Llamar a la función para verificar y actualizar la membresía del cohorte 'Delegat'
    $auth_plugin_odoo = new auth_plugin_odoo();
    $auth_plugin_odoo->verify_and_update_delegat_membership($auth_user);

    // Redirigir a la página principal
    redirect($CFG->wwwroot);
} else {
    // Si no existe un usuario local, intentar crearlo y luego iniciar sesión
    $newuser = create_user_record($auth_user, '', 'odoo');
    if($newuser) {
        complete_user_login($newuser);
        redirect($CFG->wwwroot);
    } else {
        // Falló la creación del usuario, redirigir a la página de error
        redirect($_SERVER['HTTP_REFERER'] ?? $CFG->wwwroot);
    }
}
?>
