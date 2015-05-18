<?php
/**
 * ILIAS REST Plugin for the ILIAS LMS
 *
 * Authors: D.Schaefer, S.Schneider and T. Hufschmidt <(schaefer|schneider|hufschmidt)@hrz.uni-marburg.de>
 * 2014-2015
 */
namespace RESTController\core\auth;

// This allows us to use shortcuts instead of full quantifier
use \RESTController\libs as Libs;
use \RESTController\libs\Exceptions as LibExceptions;
use \RESTController\core\auth\Exceptions as AuthExceptions;
// Requires <$app = \RESTController\RESTController::getInstance()>


// Group as version-1 implementation
$app->group('/v1', function () use ($app) {


    // Group as oauth2 implementation
    $app->group('/oauth2', function () use ($app) {


        /**
         * Route: /v1/oauth2/auth
         * Description:
         *  (RCF6749) Authorization Endpoint, used by the following grant-types:
         *   - authorization code grant
         *   - implicit grant type flows
         *  See http://tools.ietf.org/html/rfc6749
         * Method: POST
         * Auth:
         * Parameters:
         * Response:
         */
        $app->post('/auth', function () use ($app) {
            try {
                // Fetch request data
                $request = $app->request();

                // Mandatory parameters
                $api_key = $request->getParam('api_key', null, true);
                $redirect_uri = $request->getParam('redirect_uri', null, true);
                $response_type = $request->getParam('response_type', null, true);

                // Optional parameters (will be checked by model)
                $username = $request->getParam('username');
                $password = $request->getParam('password');
                $authenticity_token = $request->getParam('authenticity_token');

                // Proccess with OAuth2-Model
                $model = new OAuth2Auth($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);
                $result = $model->allGrantTypes($api_key, $redirect_uri, $username, $password, $response_type, $authenticity_token);

                // Process results (send response)
                if ($result['status'] == 'showLogin') {
                    $render = new OAuth2Misc($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);
                    $render->render('REST OAuth - Login für Tokengenerierung', 'oauth2loginform.php', $result['data']);
                }
                elseif ($result['status'] == 'showPermission') {
                    $render = new OAuth2Misc($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);
                    $render->render('REST OAuth - Client autorisieren', 'oauth2grantpermissionform.php', $result['data']);
                }
                elseif ($result['status'] == 'redirect')
                    $app->redirect($result['data']);
            }
            catch (AuthExceptions\ResponseType $e) {
                $app->halt(400, $e->getMessage(), $e::ID);
            }
            catch (AuthExceptions\LoginFailed $e) {
                $app->halt(401, $e->getMessage(), $e::ID);
            }
            catch (AuthExceptions\TokenInvalid $e) {
                $app->halt(401, $e->getMessage(), $e::ID);
            }
            catch (LibExceptions\MissingParameter $e) {
                $app->halt(422, $e->getMessage(), $e::ID);
            }
        });


        /**
         * Route: /v1/oauth2/auth
         * Description:
         *  Authorization Endpoint, this part covers only the first section of the auth
         *  flow and is included here, s.t. clients can initiate the "authorization or
         *  implicit grant flow" with a GET request.
         *  The flow after calling "oauth2loginform" continues with the POST
         *  version of "oauth2/auth".
         * Method: GET
         * Auth:
         * Parameters:
         * Response:
         */
        $app->get('/auth', function () use ($app) {
            try {
                // Fetch request data
                $request = $app->request();

                // Mandatory parameters
                $api_key = $request->getParam('client_id');
                if (is_null($api_key))
                    $api_key = $request->getParam('api_key', null, true);
                $redirect_uri = $request->getParam('redirect_uri', null, true);
                $response_type = $request->getParam('response_type', null, true);

                // Render oauth2login.php for authorization code and implicit grant type flow
                if ($response_type != 'code' && $response_type != 'token')
                    throw new Exceptions\ResponseType('Parameter <response_type> need to match "code" or "token".');

                // Display login form
                $render = new OAuth2Misc($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);
                $render->render(
                    'REST OAuth - Login für Tokengenerierung',
                    'oauth2loginform.php',
                    array(
                        'api_key' => $api_key,
                        'redirect_uri' => $redirect_uri,
                        'response_type' => $response_type
                    )
                );
            }
            catch (AuthExceptions\ResponseType $e) {
                $app->halt(400, $e->getMessage(), $e::ID);
            }
            catch (LibExceptions\MissingParameter $e) {
                $app->halt(422, $e->getMessage(), $e::ID);
            }
        });


        /**
         * Route: /v1/oauth2/token
         * Description:
         *  Token Endpoint, supported grant types:
         *   - Resource Owner (User),
         *   - Client Credentials and
         *   - Authorization Code Grant
         *  See http://tools.ietf.org/html/rfc6749
         * Method: POST
         * Auth:
         * Parameters:
         * Response:
         */
        $app->post('/token', function () use ($app) {
            try {
                // Get Request & OAuth-Model objects
                $request =  $app->request();
                $model = new OAuth2Token($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);

                // Resource Owner (User) grant type
                if ($request->getParam('grant_type') == 'password') {
                    // Fetch request data
                    $api_key = $request->getParam('api_key', null, true);
                    $user = $request->getParam('username', null, true);
                    $password = $request->getParam('password', null, true);

                    // Invoke OAuth2-Model with data
                    $result = $model->userCredentials($api_key, $user, $password);
                    $app->success($result);
                }
                // grant type
                elseif ($request->getParam('grant_type') == 'client_credentials') {
                    // Fetch request data
                    $api_key = $request->getParam('api_key', null, true);
                    $api_secret = $request->getParam('api_secret', null, true);

                    // Invoke OAuth2-Model with data
                    $result = $model->clientCredentials($api_key, $api_secret);
                    $app->success($result);
                }
                // grant type
                elseif ($request->getParam('grant_type') == 'authorization_code') {
                    // Fetch request data (POST-Form instead of body)
                    $api_key = $_POST['api_key'];

                    // Fetch request data
                    $code = $request->getParam('code', null, true);
                    $api_secret = $request->getParam('api_secret', null, true);
                    $redirect_uri = $request->getParam('redirect_uri');

                    // Invoke OAuth2-Model with data
                    $result = $model->authorizationCode($api_key, $api_secret, $code, $redirect_uri);
                    $app->success($result);
                }
                // grant type
                elseif ($request->getParam('grant_type') == 'refresh_token') {
                    // Fetch request data
                    $refresh_token = $request->getParam('refresh_token', null, true);

                    // Invoke OAuth2-Model with data
                    $result = $model->refresh2Bearer($refresh_token);
                    $app->success($result);
                }
                // Wrong grant-type
                else
                    throw new Exceptions\ResponseType('Parameter <grant_type> need to match "password", "client_credentials", "authorization_code" or "refresh_token".');
            }
            catch (AuthExceptions\ResponseType $e) {
                $app->halt(400, $e->getMessage(), $e::ID);
            }
            catch (AuthExceptions\LoginFailed $e) {
                $app->halt(401, $e->getMessage(), $e::ID);
            }
            catch (AuthExceptions\TokenInvalid $e) {
                $app->halt(401, $e->getMessage(), $e::ID);
            }
            catch (LibExceptions\MissingParameter $e) {
                $app->halt(422, $e->getMessage(), $e::ID);
            }
        });


        /**
         * Route: /v1/oauth2/refresh
         * Description:
         *  Refresh Endpoint, This endpoint allows for exchanging a bearer
         *  token with a long-lasting refresh token.
         *  Note: A client needs the appropriate permission to use this endpoint.
         * Method:
         * Auth:
         * Parameters:
         * Response:
         */
        $app->get('/refresh', '\RESTController\libs\AuthMiddleware::authenticate', function () use ($app) {
            // Fetch token
            $bearerToken = Libs\AuthMiddleware::getToken($app);

            // Create new refresh token
            $model = new OAuth2($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);
            $result = $model->getRefreshToken($bearerToken);

            // !!! Try-Catch & success
        });


        /**
         * Route: /v1/oauth2/tokeninfo
         * Description:
         *  Token-info route, Tokens obtained via the implicit code grant
         *  MUST by validated by the Javascript client to prevent the
         *  "confused deputy problem".
         * Method:
         * Auth:
         * Parameters:
         * Response:
         */
        $app->get('/tokeninfo', function () use ($app) {
            try {
                // Fetch request object
                // NOTE: Token is also fetched from header as well as body!
                $request = $app->request();

                // Generate token-information
                $model = new OAuth2Misc($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);
                $result = $model->tokenInfo($request);

                // Return status-data
                $app->success($result);
            }
            catch (Exceptions\TokenInvalid $e) {
                $app->halt(401, $e->getMessage(), $e::ID);
            }
        });


    // Enf-Of /oauth2-group
    });


    /**
     * Route: /v1/ilauth/rtoken2bearer
     * Description:
     *  Allows for exchanging an ilias session with a bearer token.
     *  This is used for administration purposes.
     * Method:
     * Auth:
     * Parameters:
     * Response:
     */
    $app->post('/ilauth/rtoken2bearer', function () use ($app) {
        try {
            // Fetch parameters
            $request = $app->request();
            $api_key = $request->getParam('api_key', null, true);
            $user_id = $request->getParam('user_id', null, true);
            $rtoken = $request->getParam('rtoken', null, true);
            $session_id = $request->getParam('session_id', null, true);

            // Convert userId, rToken and sessionId to bearer-token (using api-key)
            $model = new OAuth2($app, $GLOBALS['ilDB'], $GLOBALS['ilPluginAdmin']);
            $result = $model->rToken2Bearer($api_key, $user_id, $rtoken, $session_id);

            // Return status-data
            $app->success($result);
        }
        catch (LibExceptions\TokenInvalid $e) {
            $app->halt(401, $e->getMessage(), $e::ID);
        }
        catch (LibExceptions\MissingParameter $e) {
            $app->halt(422, $e->getMessage(), $e::ID);
        }
    });


// Enf-Of /v1-group
});
