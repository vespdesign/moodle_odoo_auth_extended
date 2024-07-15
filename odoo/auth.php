<?php

/**
 * Abbility to login to Moodle using Odoo's login and password
 *
 * @package    auth
 * @subpackage odoo
 * @copyright  Laboratorium EE, www.laboratorium.ee
 * @author     Ludwik Trammer ludwik.trammer@laboratorium.ee
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}
global $CFG;
require_once $CFG->libdir . '/authlib.php';
require_once $CFG->libdir . '/datalib.php';
require_once($CFG->dirroot . '/cohort/lib.php');
require_once 'xmlrpc.php';
require_once 'nonce_manager.php';


class auth_plugin_odoo extends auth_plugin_base
{

    /**
     * Constructor.
     */
    function auth_plugin_odoo()
    {
        $this->authtype = 'odoo';
        $this->roleauth = 'auth_odoo';
        $this->errorlogtag = '[AUTH odoo] ';

        set_config('field_updatelocal_firstname', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_lastname', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_city', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_email', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_country', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_institution', 'onlogin', 'auth/odoo');
    }

    /**
     * Performs an Odoo "read" query.
     *
     * @param integer $uid user id of the admin user
     * @param string $model The model to query
     * @param array $ids An array of ids of objects to be retrived.
     * @param array $fields An array of names of fields to be retived from the objects.
     * @return bool An array of retrived objects.
     */
    function odoo_read($uid, $model, $ids, $fields)
    {
        $objs = xmlrpc_request(
            get_config('auth/odoo', 'url') . '/xmlrpc/2/object',
            'execute_kw',
            array(
                get_config('auth/odoo', 'db'),
                $uid,
                get_config('auth/odoo', 'password'),
                $model,
                'read',
                array($ids),
                array(
                    'fields' => $fields
                )
            )
        );
        return $objs;
    }

    /**
     * Authenticates user against the selected authentication provide (Google, Facebook...)
     *
     * @param string $username The username (with system magic quotes)
     * @param string $password The password (with system magic quotes)
     * @return bool Authentication success or failure.
     */
    function user_login($username, $password) {
    global $DB;

    $odoo_referer = preg_match('/odoo.metgesdecatalunya.cat/', $_SERVER['HTTP_REFERER']);
    if ($odoo_referer) {
        $user_id = $this->get_odoo_user_id($username);
    } else {
        $user_id = xmlrpc_request(
            get_config('auth/odoo', 'url') . '/xmlrpc/2/common',
            'authenticate',
            array(
                get_config('auth/odoo', 'db'),
                $username,
                $password,
                array()
            )
        );
    }

    if ($user_id && is_numeric($user_id)) {
		global $DB;
            // Obtener el registro del usuario de Moodle por su correo electrónico
            $user = $DB->get_record('user', array('email' => $username));
           if ($user) {
                $userinfo = $this->get_userinfo($username);
                if ($userinfo) {
                    foreach ($userinfo as $key => $value) {
                        if (!empty($value) && $key !== 'email') {
                            $user->{$key} = utf8_encode($value);
                        }
                    }
                }
			// Verificar y actualizar la membresía del usuario en el cohorte 'Delegat'
			$this->verify_and_update_delegat_membership($username);
			// Actualizar el registro del usuario
            $DB->update_record('user', $user);
            // Completar el proceso de inicio de sesión
            $nonce = createNonce('login');
            $url = new moodle_url('/auth/odoo/sso.php', array('email' => $username, 'nonce' => $nonce));
            redirect($url);
		   }
            return true;
        }
        return false;
    }
	
	
    // Testing purposes
    private function get_odoo_user_id($username)
    {
        /* Get admin user's id */
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

        /* Get logged-in user's id */
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
                    array(array('login', '=', $username)),
                ),
            )
        );
        return $user_ids[0] ?? null;
    }

    /**
     * Returns true if this authentication plugin is 'internal'.
     *
     * @return bool
     */
    function is_internal()
    {
        return false;
    }

	function get_contact_id_by_username($username) {
	/* Get admin user's id */
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
	$response = xmlrpc_request(
        get_config('auth/odoo', 'url') . '/xmlrpc/2/object',
        'execute_kw',
		array(
			get_config('auth/odoo', 'db'),
			$uid,
			get_config('auth/odoo', 'password'),
			'res.partner',
			'search',
			array(
                    array(array('email', '=', $username)),
					)
				)	
			);
    // Procesar la respuesta y devolver el ID de contacto
    return $response[0] ?? null;
}
	// Función para verificar si un usuario es un delegado en Odoo
	function check_if_user_is_delegat($contact_id) {
    /* Get admin user's id */
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

    // Realizar la solicitud XML-RPC a Odoo
    $response = xmlrpc_request(
        get_config('auth/odoo', 'url') . '/xmlrpc/2/object',
        'execute_kw',
        array(
            get_config('auth/odoo', 'db'),
            $uid,
            get_config('auth/odoo', 'password'),
            'res.partner',
            'read',
            array(array($contact_id)),
            array('fields' => array('x_delegat'))
        )
    );

    // Procesar la respuesta y devolver el estado de delegado
    return !empty($response) && !empty($response[0]) && $response[0]['x_delegat'];
}
	public function verify_and_update_delegat_membership($username) {
        global $DB;

        // Obtener el ID de contacto de Odoo correspondiente al correo electrónico
        $odoo_contact_id = $this->get_contact_id_by_username($username);

        if ($odoo_contact_id !== false) {
            // Verificar si el usuario es un delegado en Odoo
            $is_delegat = $this->check_if_user_is_delegat($odoo_contact_id);

            // Obtener el ID del cohorte 'Delegat'
            $cohort = $DB->get_record('cohort', array('idnumber' => 'Delegat'));
            if ($cohort) {
                $user = $DB->get_record('user', array('username' => $username));
                if ($user) {
                    // Comprobar si el usuario ya es miembro del cohorte
                    $member = $DB->get_record('cohort_members', array('cohortid' => $cohort->id, 'userid' => $user->id));

                    if ($is_delegat && !$member) {
                        // Si el usuario es delegado y aún no es miembro del cohorte, añadirlo
                        cohort_add_member($cohort->id, $user->id);
                    } elseif (!$is_delegat && $member) {
                        // Si el usuario ya no es delegado y es miembro del cohorte, quitarlo
                        cohort_remove_member($cohort->id, $user->id);
                    }
                }
            }
        }
    }
	
	//Funcion para añadir usuarios a cohortes
	function add_user_to_cohorte($username, $cohort_name) {
    global $DB;
    $cohort = $DB->get_record('cohort', array('idnumber' => $cohort_name));
    if ($cohort) {
        $user = $DB->get_record('user', array('username' => $username));
        if ($user) {
            cohort_add_member($cohort->id, $user->id);
        }
    }
}

    function get_userinfo($username)
    {
        $userinfo = array();

        /* Get admin user's id */
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

        /* Get logged-in user's id */
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
                    array(array('login', '=', $username)),
                ),
            )
        );
        /* Get user info */
        if ($user_ids) {
            $users = $this->odoo_read(
                $uid,
                'res.users',
                $user_ids,
                array(
                    'name',
                    'email',
                    'city',
                )
            );
            $user = $users[0];
            if (preg_match('/,/', $user['name'])) {
                [$lastname, $name] = explode(', ', $user['name']);
            } elseif (preg_match('/ /', $user['name'])) {
                [$name, $lastname] = explode(' ', $user['name']);
            } else {
                $name = $user['name'];
                $lastname = '';
            }
            /* Basic fields */
            $userinfo['firstname'] = $name ?? "";
            $userinfo['lastname'] = $lastname ?? "";
            $userinfo['email'] = $user['email'];

            /* Non-standard fields */
            if (isset($user['city']) && $user['city']) {
                $userinfo['city'] = $user['city'];
            } elseif (isset($user['city_gov']) && $user['city_gov']) {
                $userinfo['city'] = $user['city_gov'];
            }

            /* get country code */
            $country_id = null;
            if (isset($user['country']) && $user['country']) {
                $country_id = $user['country'][0];
            } elseif (isset($user['country_gov']) && $user['country_gov']) {
                $country_id = $user['country_gov'][0];
            }
            if ($country_id) {
                $countries = $this->odoo_read(
                    $uid,
                    'res.country',
                    array($country_id),
                    array('code')
                );
                $userinfo['country'] = strtoupper($countries[0]['code']);
            }

            /* Get organizations */
            $organization_ids = array();
            if (isset($user['coordinated_org']) && $user['coordinated_org']) {
                $organization_ids = array_merge($organization_ids, $user['coordinated_org']);
            }
            if (isset($user['organizations']) && $user['organizations']) {
                $organization_ids = array_merge($organization_ids, $user['organizations']);
            }
            $organization_ids = array_unique($organization_ids);

            if ($organization_ids) {
                $organization_objs = $this->odoo_read(
                    $uid,
                    'organization',
                    $organization_ids,
                    array('name')
                );

                $organizations = array();
                foreach ($organization_objs as $organization) {
                    $organizations[] = $organization['name'];
                }
                $userinfo['institution'] = implode(', ', $organizations);
            }
        }
        return $userinfo;
    }


    function config_form($config, $err, $user_fields)
    {
        global $OUTPUT;

        include "settings.php";
    }

    /**
     * Processes and stores configuration data for this authentication plugin.
     */
    function process_config($config)
    {
        // set to defaults if undefined
        if (!isset ($config->db)) {
            $config->db = '';
        }
        if (!isset ($config->url)) {
            $config->url = '';
        }
        if (!isset ($config->password)) {
            $config->password = '';
        }
        if (!isset ($config->user)) {
            $config->user = '';
        }

        // save settings
        set_config('db', $config->db, 'auth/odoo');
        set_config('url', $config->url, 'auth/odoo');
        set_config('password', $config->password, 'auth/odoo');
        set_config('user', $config->user, 'auth/odoo');

        return true;
    }
}
