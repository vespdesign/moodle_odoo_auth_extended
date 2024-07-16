<?php
namespace auth_odoo\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/auth/odoo/xmlrpc.php');
require_once($CFG->dirroot . '/auth/odoo/nonce_manager.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/auth/odoo/auth.php');

class update_cohort_membership extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('updatecohortmembership', 'auth_odoo');
    }

    public function execute() {
        global $CFG, $DB;

        // Configuración de Odoo
        $odoo_url = get_config('auth/odoo', 'url');
        $odoo_db = get_config('auth/odoo', 'db');
        $odoo_user = get_config('auth/odoo', 'user');
        $odoo_password = get_config('auth/odoo', 'password');

        try {
            $uid = $this->odoo_authenticate($odoo_url, $odoo_db, $odoo_user, $odoo_password);
            $users_odoo = $this->get_users_needing_update($odoo_url, $odoo_db, $uid, $odoo_password);

            $auth_plugin_odoo = new \auth_plugin_odoo();
            foreach ($users_odoo as $user_odoo) {
                if (isset($user_odoo['email']) && !empty($user_odoo['email'])) {
                    $auth_plugin_odoo->verify_and_update_delegat_membership($user_odoo['email']);
                }
            }
        } catch (\Exception $e) {
            \core\notification::error("Error al ejecutar la tarea programada: " . $e->getMessage());
        }
    }

    private function odoo_authenticate($url, $db, $username, $password) {
        $uid = xmlrpc_request(
            $url . '/xmlrpc/2/common',
            'authenticate',
            array($db, $username, $password, array())
        );
        if (!$uid) {
            throw new \Exception("No se pudo autenticar con Odoo.");
        }
        return $uid;
    }

    private function get_users_needing_update($url, $db, $uid, $password) {
        $model = 'res.users'; // Ajusta según tu modelo en Odoo
        $domain = []; // Criterio para seleccionar usuarios
        $fields = ['login', 'email']; // Campos requeridos de cada usuario

        $ids = xmlrpc_request(
            $url . '/xmlrpc/2/object',
            'execute_kw',
            array($db, $uid, $password, $model, 'search', array($domain))
        );

        if (!$ids) {
            return [];
        }

        $users = xmlrpc_request(
            $url . '/xmlrpc/2/object',
            'execute_kw',
            array($db, $uid, $password, $model, 'read', array($ids), array('fields' => $fields))
        );
        return $users ?: [];
    }
}
?>