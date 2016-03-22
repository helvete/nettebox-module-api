<?php

namespace Argo22\Modules\Core\Api;

use Nette;
use Lightbulb;
use Nette\Application\Responses;
use Tracy\Debugger;
use App\Models\Api;
use Lightbulb\Json\Rpc2;

class Presenter extends Nette\Application\UI\Presenter
{
	/** @var \Argo22\Modules\Core\Api\ApiVersion @inject **/
	var $apiVersion;

	/**
	 * @var string
	 */
	protected $_version;

	/**
	 * @var \Argo22\Modules\Core\Api\Account\Model
	 */
	protected $_account;

	/**
	 * Debug method for logging issued DB queries. Useful for debugging API
	 * TODO: incorporate to log based on environment
	 */
	public function logQuery(Nette\Database\Connection $connection, $result) {
		$soubor = fopen("../log/queryLog", "a");
		fwrite($soubor, date('Y-m-d H:i:s') . ": " .$result->getQueryString() . "\n");
		fclose($soubor);
	}


	/**
	 * Creates user token
	 *
	 * @return void
	 */
	public function actionDefault($version = '0.0.0')
	{
		// set version as property to make it available
		// from within self::handleVersions()
		$this->_version = $version;

		\Nette\Diagnostics\Debugger::$bar = false;

		// Get server
		$server = new Rpc2\Server;
		$server->supressOutput();

		// Set mobile app specific response headers
		$this->_setResponseHeaders($this->getHttpResponse());

		// handle OPTIONS request
		$request = $this->getHttpRequest();
		if ($request->isMethod('OPTIONS')) {
			$this->sendResponse(new Responses\TextResponse(''));
			$json = array(
				'jsonrpc' => '2.0',
				'result' => array(),
				'id' => null
			);
			$this->sendResponse(new Responses\JsonResponse($json));
		}

		$this->_bindApiModels($server);

		$this->_beforeCall($server);

		// When running the request, we need to check whether there has been anything returned. If not,
		// then we have to send an empty text response, as Nette\...\JsonResponse throws exception
		// on empty responses.
		try {
			$response = $server->handle();
			$this->_postprocessResponse($response);
		} catch (\Exception $e) {
			// catch them all - unexpected exceptions
			\Tracy\Debugger::log($e);

			$error = new Rpc2\RPCError(
				$e->getMessage(),
				Rpc2\RPCError::INTERNAL_ERROR
			);

			$response = $server->transformError($error);
		}

		if ( ! empty($response) || is_array($response)) { // is_array in order to send []
			$this->sendResponse(new Responses\JsonResponse($response));
		}
		else {
			$this->sendResponse(new Responses\TextResponse(''));
		}
	}

	/**
	 * Bind API model classes to allow their usage through the API
	 *
	 * @param  Lightbulb\Json\Rpc2\Server	$server
	 * @return void
	 */
	protected function _bindApiModels(&$server)
	{
		$server->user = $this->context->createService('apiUser');
		$server->echo = function() { return func_get_args();};
	}


	/**
	 * Bind processes to be donme before the actual API request processing.
	 * Various kinds of validation, etc.
	 *
	 * @param  Lightbulb\Json\Rpc2\Server	$server
	 * @return void
	 */
	protected function _beforeCall(&$server)
	{
		$server->onBeforeCall[] = array($this, 'checkToken');
		$server->onBeforeCall[] = array($this, 'verifyUserActivity');
		$server->onBeforeCall[] = array($this, 'handleVersions');
	}


	/**
	 * Postprocess response object
	 *
	 * @param  stdClass	$response
	 * @return void
	 */
	protected function _postprocessResponse(&$response) {}


	/**
	 * Handle API versions
	 *
	 * @param  Rpc2\Server	$server
	 * @param  stdClass		$request
	 * @return void
	 */
	public function handleVersions($server, &$request)
	{
		// find out whether current version needs any overrides
		$overrideDetails = $this->apiVersion->getOverrides($this->_version);
		if (empty($overrideDetails)) {
			return;
		}
		// handle case of already deprecated current version
		$this->_handleDeprecated($overrideDetails['deprecate_at']);

		// match model
		list($model, $method) = explode('.', $request->method);
		if (!isset($overrideDetails['override'][$model])) {
			return;
		}
		// in case the model.method override exists for this request, reroute it
		if (in_array($method, $overrideDetails['override'][$model])) {
			$request->method = "$model.$method{$overrideDetails['suffix']}";
		}
	}


	/**
	 * Handle mobile application version deprecation
	 *
	 * @param  \DateTime	$deprecateDateTime
	 * @return void
	 */
	public function _handleDeprecated(\DateTime $deprecateDateTime)
	{
		$now = new \DateTime();
		if ($deprecateDateTime > $now) {
			return;
		}
		throw new Lightbulb\Json\Rpc2\RPCError(
			'Application version deprecated',
			Api\Constants::RPC_ERROR_APP_VERSION_DEPRECATED
		);
	}


	/**
	 * Checks whether request posses valid token
	 *
	 * @param  Rpc2\Server $server
	 * @param  stdClass $request
	 * @throws	Lightbulb\Json\Rpc2\RPCError
	 * @return void
	 */
	public function checkToken($server, $request)
	{
		// for unknown methods do nothing
		if ( ! isset($request->method)) {
			return;
		}

		$token = isset($request->token)
			? $request->token
			: false;

		if ($token === false) {
			// we don't check token in case it cannot be supplied
			if (in_array($request->method, $this->_methodsWithoutAuth())) {
				return;
			}

			throw new Lightbulb\Json\Rpc2\RPCError(
				'Token is required',
				Api\Constants::RPC_ERROR_MISSING_TOKEN
			);
		}

		// we need to allow unauthorized users with specific token
		if ($token == 'visitor') {
			return;
		}

		// ! getService not createService so API models can get the same instance
		$session = $this->context->getService('apiSession');
		$this->_account = $session->loadFrom($token);

		if ( ! $session->isLoggedIn()) {
			throw new Lightbulb\Json\Rpc2\RPCError(
				'Token not valid',
				Api\Constants::RPC_ERROR_INVALID_TOKEN
			);
		}
		// if there is a valid logged user, log activity
		if (!is_null($this->_account)) {
			$this->_account->update(array('last_seen' => new \DateTime()));
		}
	}


	/**
	 * Verify user activity
	 *
	 * @param  Rpc2\Server $server
	 * @param  stdClass $request
	 * @return void
	 */
	public function verifyUserActivity($server, $request)
	{
		// we don't check account expiration time for certain methods
		if (in_array($request->method, $this->_methodsForExpired())) {
			return;
		}

		// get account data from the request
		$token = isset($request->token)
			? $request->token
			: false;
		$accountNode = isset($request->params->user)
			? $request->params->user
			: false;
		$emailNode = isset($request->params->email)
			? $request->params->email
			: $accountNode;

		// try to fetch it from api session
		$account = $this->context->getService('apiSession')->getUser();
		if (empty($account)) {

			// or by supplied email
			$account = $this->context
				->getByType('\Argo22\Modules\Core\Api\Account\Collection')
				->getOneFiltered('*', array('email' => $emailNode));

			// handle unknown account by API error
			if (empty($account)) {
				throw new Rpc2\RPCError(
					'User not found by supplied email',
					Api\Constants::RPC_ERROR_IDENTITY_NOT_FOUND
				);
			}
		}
		// get account state and act upon it
		if ($account->state
			!== \Argo22\Modules\Core\Api\Account\Model::STATE_WAITING_FOR_ACTIVATION
		) {
			// release in case account is either NEW or ACTIVE
			return;
		}

		// verify acc expiration time
		$config = $this->context->getByType('\App\Services\Config');
		$period = $config->accActivationExpirationSeconds;
		$now = new \DateTime();
		$wfaSince = $account->activation_email_sent;
		$validUntil = $wfaSince->add(new \DateInterval("PT{$period}S"));

		// throw API error on expired acc
		if ($validUntil < $now) {
			$json = array(
				'jsonrpc' => '2.0',
				'error' => array(
					'code' => Api\Constants::RPC_ERROR_USER_ACCOUNT_EXPIRED,
					'message' => 'User account validity expired',
					'data' => array('email' => $account->email),
				),
				'id' => $request->id,
			);

			// send response and terminate the dirty way
			// FIXME: Rewrite Lightbulb\Json\Rpc2\RPCError to be able to add custom error data
			$response = new Responses\JsonResponse($json);
			$response->send($this->getHttpRequest(), $this->getHttpResponse());
			exit();
		}
	}


	/**
	 * Return array of API methods that don't require token authentication
	 *
	 * @return array
	 */
	protected function _methodsWithoutAuth()
	{
		return array(
			'user.login',
			'user.signup',
			'user.resetpassword',
			'user.getemailby',
		);
	}


	/**
	 * Return array of API methods that don't have to pass account expiration
	 * verification
	 *
	 * @return array
	 */
	protected function _methodsForExpired()
	{
		return array(
			'user.signup',
		);
	}


	/**
	 * Set response headers for the mobile application to trust the server
	 *
	 * @param  Nette\Http\Response	$response
	 * @return void
	 */
	protected function _setResponseHeaders(Nette\Http\Response $response)
	{
		$responseHeaders = array(
			'Access-Control-Allow-Origin' => '*',
			'Access-Control-Allow-Headers' => 'accept, content-type',
			'Content-Type' => 'application/json',
		);
		foreach($responseHeaders as $name => $value) {
			$response->setHeader($name, $value);
		}
	}
}
