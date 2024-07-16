<?php
namespace local_odoomobileupdate\task;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot.'/auth/odoo/xmlrpc.php');
require_once($GLOBALS['CFG']->dirroot.'/auth/odoo/nonce_manager.php');
require_once($GLOBALS['CFG']->dirroot.'/cohort/lib.php');
require_once($GLOBALS['CFG']->dirroot.'/user/lib.php');
require_once($GLOBALS['CFG']->dirroot.'/auth/odoo/auth.php');

class update_mobile extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('updatemobile', 'local_odoomobileupdate');
    }

    public function execute() {
        global $CFG, $DB;
        
        $this->debug_to_log("Inicio de la tarea de actualización de móviles.");
        
        // Configuración de Odoo
        $odoo_url = get_config('auth/odoo', 'url');
        $odoo_db = get_config('auth/odoo', 'db');
        $odoo_user = get_config('auth/odoo', 'user');
        $odoo_password = get_config('auth/odoo', 'password');

        try {
            $uid = $this->odoo_authenticate($odoo_url, $odoo_db, $odoo_user, $odoo_password);
            if (!$uid) {
                $this->debug_to_log("Falló la autenticación con Odoo. UID no obtenido.");
                return;
            }

            $users_odoo = $this->get_users_needing_update($odoo_url, $odoo_db, $uid, $odoo_password);
            $this->debug_to_log("Usuarios obtenidos de Odoo: " . count($users_odoo));

            foreach ($users_odoo as $user_odoo) {
                $this->debug_to_log("Procesando usuario: " . print_r($user_odoo, true));
                if (isset($user_odoo['email']) && !empty($user_odoo['email'])) {
                    $this->update_user_login_method($user_odoo);
                }
            }
        } catch (\Exception $e) {
            $this->debug_to_log("Excepción capturada durante la ejecución de la tarea: " . $e->getMessage());
        }
        
        $this->debug_to_log("Fin de la tarea de actualización de móviles.");
    }

    private function odoo_authenticate($url, $db, $username, $password) {
        try {
            $uid = xmlrpc_request(
                $url . '/xmlrpc/2/common',
                'authenticate',
                array($db, $username, $password, array())
            );
            return $uid;
        } catch (\Exception $e) {
            $this->debug_to_log("Error en la autenticación de Odoo: " . $e->getMessage());
            return null;
        }
    }

    private function get_users_needing_update($url, $db, $uid, $password) {
        try {
            $model = 'res.users';
            $domain = [];
            $fields = ['login', 'email', 'mobile'];

            $ids = xmlrpc_request(
                $url . '/xmlrpc/2/object',
                'execute_kw',
                array($db, $uid, $password, $model, 'search', array($domain))
            );

            if ($ids) {
                return xmlrpc_request(
                    $url . '/xmlrpc/2/object',
                    'execute_kw',
                    array($db, $uid, $password, $model, 'read', array($ids), array('fields' => $fields))
                );
            }
            return [];
        } catch (\Exception $e) {
            $this->debug_to_log("Error obteniendo usuarios de Odoo: " . $e->getMessage());
            return [];
        }
    }

    private function update_user_login_method($user_odoo) {
    global $DB;

    try {
        $user_moodle = $DB->get_record('user', ['username' => $user_odoo['email']]);
        if ($user_moodle && isset($user_odoo['mobile']) && !empty($user_odoo['mobile'])) {
            // Limpieza del número de móvil para asegurar formato correcto
            $clean_mobile = $this->clean_mobile_number($user_odoo['mobile']);
            if ($clean_mobile && ($user_moodle->phone2 !== $clean_mobile)) {
                $user_moodle->phone2 = $clean_mobile;
                $DB->update_record('user', $user_moodle);
                $this->debug_to_log("Número de móvil actualizado para: " . $user_odoo['email']);
            }
        }
    } catch (\Exception $e) {
        $this->debug_to_log("Error actualizando el usuario: " . $e->getMessage());
        throw $e; // Or handle error appropriately
    }
}

    private function debug_to_log($data) {
        $file = $GLOBALS['CFG']->dirroot . '/local/odoomobileupdate/debug.log';
        $current = file_get_contents($file);
        $current .= date('[Y-m-d H:i:s] ') . print_r($data, true) . "\n";
        file_put_contents($file, $current);
    }
	
	private function clean_mobile_number($mobile) {
    // Elimina cualquier cosa que no sea un número o un guión
    return preg_replace('/[^0-9\-]+/', '', $mobile);
}
}
