<?php
/**
 * The Warden: User authorization library for fuelphp.
 * Handles user login and logout, as well as secure password hashing.
 *
 * @package    Warden
 * @subpackage Warden
 * @version    0.5
 * @author     Andrew Wayne <lifeandcoding@gmail.com>
 * @license    MIT License
 * @copyright  (c) 2011 Andrew Wayne
 */
namespace Warden;

/**
 * Warden_Driver
 *
 * @package    Warden
 * @subpackage Warden
 */
class Warden_Driver
{
    /**
     * Configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Current logged in user
     *
     * @var \Warden\Model_User
     */
    protected $user;

    /**
     * Creates a new driver instance, loading the session and storing config.
     *
     * @param  array $config configuration
     *
     * @return void
     */
    public function __construct(array $config)
    {
        // Store config
        $this->config = $config;
    }

    /**
     * Checks if a session is active.
     *
     * @param  string $role The role name (optional)
     *
     * @return bool
     */
    public function logged_in($role = null)
    {
        $auth_token = \Session::get('authenticity_token');

        if (!empty($auth_token) &&
            (is_null($this->user) || $this->user->authentication_token != $auth_token))
        {
            $this->user = null;

            $user = Model_User::find('first', array(
                'where' => array('authentication_token' => $auth_token)
            ));

            if (!is_null($user)) {
                $this->user = $user;
            }
        }

        return $this->has_access($role ? $role : $this->config['default_role'], $this->user);
    }

    /**
     * Verify Acl access
     *
     * @param mixed              $role The role name to check
     * @param \Warden\Model_User $user The user to check against, if no user is given (null)
     *                                 it will check against the currently logged in user
     *
     * @return bool
     */
    public function has_access($role, Model_User $user = null)
    {
        $status   = !!$user;

        if (!empty($role) && $status) {
            $role = (is_array($role) ? $role : array($role));

            $diff = array_udiff($role, $user->roles, function ($r, $ur) {
                // check for a role object
                $r = (is_object($r) ? $r->name : $r);
                // compare each given role against the user's roles
                return $r != $ur->name;
            });

            // empty = true
            $status = empty($diff);
        }

        return $status;
    }

    /**
     * Logs a user in.
     *
     * @param string $username_or_email
     * @param string $password
     * @param bool   $remember
     *
     * @return  bool
     */
    public function authenticate_user($username_or_email, $password, $remember)
    {
        if (($user = Model_User::authenticate($username_or_email)) &&
            Warden::instance()->has_password($user, $password))
        {
            if ($remember === true) {
                // Set token data
                $user->remember_token = Warden::generate_token();

                // Set the remember-me cookie
                \Cookie::set('remember_token',
                             $user->remember_token,
                             $this->config['lifetime'],
                             null, null, null, true);
            }

            $this->complete_login($user);

            return true;
        }

        // Login failed
        return false;
    }

    /**
     * Logs a user in using Http based authentication
     *
     * @return  bool
     */
    public function http_authenticate_user()
    {
        if (!$this->config['http_authenticatable']['in_use']) {
            return false;
        }

        $method = "_http_{$this->config['http_authenticatable']['method']}";
        return $this->{$method}(\Response::forge(null, 401));
    }

    /**
     * Gets the currently logged in user from the session.
     * Returns FALSE if no user is currently logged in.
     *
     * @return  mixed
     */
    public function current_user()
    {
        if ($this->logged_in(null)) {
            return $this->user;
        }

        return false;
    }

    /**
     * Forces a user to be logged in, without specifying a password.
     *
     * @param string $username_or_email
     *
     * @return bool
     */
    public function force_login($username_or_email)
    {
        $user = Model_User::authenticate($username_or_email);
        return $user && $this->complete_login($user);
    }

    /**
     * Logs a user in, based on stored credentials, typically cookies.
     *
     * @return bool
     */
    public function auto_login()
    {
        if (($token = \Cookie::get('remember_token'))) {
            $user = Model_User::find('first', array(
                'where' => array('remember_token' => $token)
            ));

            if (!is_null($user)) {
                // Complete the login with the found data
                $this->complete_login($user);

                // Automatic login was successful
                return true;
            }
        }

        return false;
    }

    /**
     * Log a user out.
     *
     * @param bool $destroy Whether to completely destroy the session
     *
     * @return bool
     */
    public function logout($destroy)
    {
        $this->user = null;

        // Delete the session identifier for the user
        \Session::delete('authenticity_token');

        // Delete http server variables
        if ($this->config['http_authenticatable']['in_use']) {
            unset($_SERVER['PHP_AUTH_USER'],
                  $_SERVER['PHP_AUTH_PW'],
                  $_SERVER['PHP_AUTH_DIGEST']);
        }

        if (\Cookie::get('remember_token')) {
            // Delete the remember-me cookie to prevent re-login
            \Cookie::delete('remember_token');
        }

        if ($destroy === true) {
            // Destroy the session completely
           \Session::destroy();
        } else {
            // Regenerate session_id
            \Session::instance()->rotate();
        }

        // Double check
        return !$this->logged_in(null);
    }

    /**
     * Completes a login by assigning the user to the session key.
     *
     * @param \Warden\Model_User $user
     *
     * @return bool
     */
    protected function complete_login(Model_User $user)
    {
        // Create and set new authentication token
        $user->authentication_token = Warden::generate_token();

        if ($this->config['trackable'] === true) {
            $user->update_tracked_fields();
        } else {
            $user->save(false);
        }

        \Session::set('authenticity_token', $user->authentication_token);
        \Session::instance()->rotate();

        $this->user = $user;

        return true;
    }

    /**
    * Handler for HTTP Basic Authentication
    *
    * @return void
    */
    private function _http_basic(\Response $response)
    {
        $users = $this->config['http_authenticatable']['users'];
        $username = \Input::server('PHP_AUTH_USER');
        $password = \Input::server('PHP_AUTH_PW');

        if (!isset($users[$username]) || $users[$username] !== $password) {
            $response->set_header('WWW-Authenticate', "Basic realm=\"{$this->config['http_authenticatable']['realm']}\"");
            $response->send_headers();
            exit($this->config['http_authenticatable']['failure_text']);
        }

        return array('username' => $username, 'password' => $password);
    }

    /**
    * Handler for HTTP Digest Authentication
    *
    * @return void
    */
    private function _http_digest(\Response $response)
    {
        $realm = $this->config['http_authenticatable']['realm'];

        $data = array(
            'username' => null, 'nonce' => null, 'nc' => null,
            'cnonce'   => null, 'qop'  => null, 'uri' => null,
            'response' => null
        );

        foreach (explode(',', \Input::server('PHP_AUTH_DIGEST')) as $string) {
            $parts = explode('=', trim($string), 2) + array('', '');
            $data[$parts[0]] = trim($parts[1], '"');
        }

        $users = $this->config['http_authenticatable']['users'];
        $password = !empty($users[$data['username']]) ? $users[$data['username']] : null;

        $user = md5("{$data['username']}:{$realm}:{$password}");
        $nonce = "{$data['nonce']}:{$data['nc']}:{$data['cnonce']}:{$data['qop']}";
        $req = md5(\Input::server('REQUEST_METHOD').':'.$data['uri']);
        $hash = md5("{$user}:{$nonce}:{$req}");

        if (!$data['username'] || $hash !== $data['response']) {
            \Session::set('http_authenticated', true);

            $nonce = uniqid();
            $opaque = md5($realm);

            $header_value = "Digest realm=\"{$realm}\",qop=\"auth\", nonce=\"{$nonce}\",opaque=\"{$opaque}\"";
            $response->set_header('WWW-Authenticate', $header_value);
            $response->send_headers();
            exit($this->config['http_authenticatable']['failure_text']);
        }

        return array('username' => $data['username'], 'password' => $password);
    }
}