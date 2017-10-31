<?php defined('BASEPATH') OR exit('No direct script access allowed');

use OAuth2\Request;
use OAuth2\Response;

/*
 * Authorization endpoint.
 * 
 * @see http://openid.net/specs/openid-connect-implicit-1_0.html
 */
class Authorize extends Auth_Controller
{
    public function __construct()
    {
        parent::__construct();
        // Allows CORS.
        $this->output->set_header('Access-Control-Allow-Origin: *');
        $this->output->set_header('Access-Control-Allow-Methods: GET');

        // OAuth 2.0 Server.
        $this->load->library('OAuth2_server');
    }

    /**
     * The user is directed here by the client in order to authorize the client app
     * to access his/her data.
     */
    public function index()
    {
        $request = Request::createFromGlobals();
        $response = new Response();

        // Validates the authorize request. If it is invalid, redirects back to the client with the errors.
        if (!$this->oauth2_server->server->validateAuthorizeRequest($request, $response)) {
            $this->oauth2_server->server->getResponse()->send();
            die();
        }

        // Stores the request.
        $this->session->set_flashdata('request', $request);

        // Silent renew.
        // The Authorization Server MUST NOT display any authentication or consent user interface pages.
        $prompt = $request->query('prompt');
        if ($prompt && $prompt == 'none') {
            $this->authorize_post(TRUE);
        }

        // Authenticates End-User.
        // http://openid.net/specs/openid-connect-implicit-1_0.html#Authenticates
        if (!$this->ion_auth->logged_in()) {
            // Stores the request.
            $request_url = 'connect/authorize' . '?' . $_SERVER['QUERY_STRING'];
            $this->session->set_flashdata('request_url', $request_url);
            // Redirects to login.
            redirect('account/login', 'refresh');
        }

        // Obtains End-User Consent/Authorization.
        // http://openid.net/specs/openid-connect-implicit-1_0.html#Consent.
        $scopes = $this->oauth2_server->server->getStorage('scope')->supportedScopes;

        $this->data['title'] = $this->title;
        $this->data['client_id'] = $request->query('client_id');
        $this->data['scopes'] = $scopes;

        $this->load->view('connect/authorize', $this->data);
    }

    public function authorize_post($no_prompt = FALSE)
    {
		// Gets the request.
        $request = $this->session->flashdata('request');
        $response = new Response();

        $is_authorized = isset($_POST['authorize']) || $no_prompt;
        $user_id = $this->ion_auth->get_user_id();

        $this->oauth2_server->server->handleAuthorizeRequest($request, $response, $is_authorized, $user_id);

        // Session management:
        // http://openid.net/specs/openid-connect-session-1_0.html#CreatingUpdatingSessions
        // "opbs" is the cookie generated by Ion Auth 2 for user session,
        // ("remember_cookie_name" in config),
        // alternatively it would be possible to generate a base64 token: "$this->base64url_encode(random_bytes(64));"
        // and manage it manually.
        $browser_state = get_cookie('opbs');
        $session_state = $this->calculate_session_state($request, $browser_state);

        $header = $response->getHttpHeader('Location');
        $header = $header . '&session_state=' . $session_state;
        $response->setHttpHeader('Location', $header);

        $response->send();
    }

    private function calculate_session_state($request, $browser_state)
    {
        $client_id = $request->query('client_id');
        $origin = $request->query('redirect_uri');
        $salt = $this->base64url_encode(random_bytes(16));
        $hash = hash('sha256', sprintf('%s%s%s%s', $client_id, $origin, $browser_state, $salt));
        return sprintf('%s.%s', $hash, $salt);
    }

    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
