<?php

namespace Argo22\Modules\Core\Api\Account;

use \Lightbulb\Json\Rpc2;
use \Nette\Security;
use \Nette\Utils\Validators;
use \Argo22\Modules\Core\Api\Constants;

/**
 * Class for handling API requests
 *
 * WARNING: these methods are callable directly from RPC server!
 */
class User extends \Nette\Object {
	/** @var ApiAuthenticator @inject **/
	var $authenticator;
	/** @var Validator @inject **/
	var $validator;
	/** @var Collection @inject **/
	var $collection;
	/** @var Session @inject **/
	var $apiSession;
	/** @var \Argo22\Modules\Core\Api\AccountNotification\Collection @inject **/
	var $accountNotification;
	/** @var \Argo22\Modules\Core\Email\Collection @inject **/
	var $emailCollection;
	/** @var \Argo22\Modules\Core\User\PasswordRecovery @inject **/
	var $recovery;
	/** @var \Nette\Application\LinkGenerator @inject **/
	var $linkGenerator;

	/**
	 * Returns login of the user
	 *
	 * @param  string	$user
	 * @param  string	$password
	 * @param  string	$facebook_id
	 * @return array
	 */
	public function login($user, $password = null, $facebook_id = null)
	{
		self::_checkInput($password, $facebook_id);
		try {
			// standard auth
			if (is_null($facebook_id)) {
				$identity = $this->authenticator
					->authenticate(array($user, $password));
			}
			// facebook auth
			else {
				$identity = $this->authenticator
					->authenticateFb(array($user, $facebook_id));
			}
		} catch (Security\AuthenticationException $e) {
			if ($e->getCode() == Security\IAuthenticator::IDENTITY_NOT_FOUND) {
				throw new Rpc2\RPCError(
					'Identity not found',
					Constants::RPC_ERROR_IDENTITY_NOT_FOUND
				);
			}
			throw new Rpc2\RPCError(
				'Credentials not valid',
				Constants::RPC_ERROR_INVALID_CREDENTIALS
			);
		}

		$hash = $this->apiSession->generateToken($identity->account_id);
		return array(
			'token' => $hash,
			'user' => $user,
		);
	}


	/**
	 * Validate input account data and create a new account in case they are valid.
	 * Implicitly log the valid account in afterwards
	 *
	 * @param  string	$email
	 * @param  string	$password
	 * @param  string	$facebook_id
	 * @param  string	$referral
	 * @param  string	$device
	 * @return array
	 */
	public function signup($email, $password = null, $facebook_id = null,
		$referral = null, $device = null
	) {
		$values = get_defined_vars();
		$values['registration_source']
			= \Argo22\Modules\Core\Api\Account\Model::SOURCE_APP;
		self::_checkInput($password, $facebook_id);

		// registering using facebook ID, setting 'random' long password
		$values['password'] = $password = is_null($password)
			? md5(time())
			: $password;

		// validate
		$result = $this->validator->validate(array(
			'email' => $email,
			'password' => array(
				'value' => $password,
				'login' => $email,
			),
		));
		// return error if occurred
		if ($result['status'] === 'error') {
			throw new Rpc2\RPCError($result['message'], $result['code']);
		}

		$device = !isset($values['device'])?:$values['device'];
		unset($values['device']);
		$account = $this->collection->createNew($values);
		// grant free60 subs
		$this->subscriptionsCollection
			->setFree($account->id, $account->country_code, $device);

		// login the new user
		return $this->login($email, $password, $facebook_id);
	}


	/**
	 * Does nothing for now
	 *
	 * @return array
	 */
	public function logout()
	{
		if ($this->apiSession->isLoggedIn()) {
			$this->apiSession->destroy();
			// send message
			return	array(
				'message' => 'Goodbye',
			);
		}

		// send message
		return	array(
			'message' => '???',
		);

	}


	/**
	 * Request password recovery for user identified by email
	 *
	 * @param  string	$email
	 * @return array
	 */
	public function resetpassword($email)
	{
		// fetch account and validate absence
		$account = $this->collection->getByEmail($email);
		if (is_null($account)) {
			throw new Rpc2\RPCError(
				'Identity not found',
				Constants::RPC_ERROR_IDENTITY_NOT_FOUND
			);
		}
		$expiration = $this->_getPassRecoExpirationSeconds();
		$recoveryHash = $this->recovery->createRequest($email, $expiration);

		$email = $this->_assemblePasswordRecoveryEmail($account, $recoveryHash);

		// insert account recovery email
		$this->emailCollection->insert($email);

		return array(
			'message' => 'Email with new password has been sent',
		);
	}


	/**
	 * Set expiration time of PR request
	 *
	 * @return int
	 */
	protected function _getPassRecoExpirationSeconds()
	{
		// week-long validity as default
		return 604800;
	}


	/**
	 * Assemble pass reco email
	 *
	 * @param  Argo22\Modules\Core\Account\Model		$account
	 * @param  string									$hash
	 * @return Nette\Bridges\ApplicationLatte\Template
	 */
	protected function _assemblePasswordRecoveryEmail($account, $hash)
	{
		// compile email properties
		return array(
			'subject' => 'Password recovery request for: '. $account->email,
			'body' => "Visit "
				. $this->linkGenerator->link('PasswordRecovery:set', [$hash])
				. " to reset your password.",
			'created' => date('Y-m-d H:i:s'),
			'recipient_email' => $account->email,
			'sender_name' => "Password recovery system",
			'sender_email' => "no-reply@password-recovery.com",
			'purpose_flag' => 'recovery',
			'account_id' => $account->id,
			'is_html' => false,
		);
	}


	/**
	 * Inser/Update user details
	 *
	 * @param  string		$name
	 * @param  string		$birth
	 * @param  string		$gender
	 * @param  string		$town
	 * @param  string		$avatar
	 * @param  bool			$facebook_connected
	 * @param  string		$facebook_id
	 * @param  string		$email
	 * @return array
	 */
	public function updateprofile($name = null, $date_of_birth = null,
		$gender = null, $hometown = null, $avatar = null,
		$facebook_connected = null, $facebook_id = null, $email = null
	) {
		// get account
		$account = $this->apiSession->getUser();
		if (!$account) {
			throw new Rpc2\RPCError(
				'Profile updates not allowed for visitors',
				Constants::RPC_ERROR_NOT_ALLOWED_FOR_VISITORS
			);
		}
		// email - run as first, because validation can fail
		if (!is_null($email)) {
			// validate email for correct syntax and presence within DB
			$validants = array(
				'usernamechange' => array(
					'value' => $email,
					'account_id' => $account->id
				)
			);
			$result = $this->validator->validate($validants);

			// return error if occurred
			if ($result['status'] === 'error') {
				throw new Rpc2\RPCError($result['message'], $result['code']);
			}

			$account->update(array(
				'email' => $email,
			));
		}
		// account details update
		if ($facebook_connected === true && is_null($facebook_id)) {
			throw new Rpc2\RPCError(
				'When connecting to facebook, FB id has to be supplied',
				Constants::RPC_ERROR_INVALID_PARAMS_FORMAT
			);
		}

		// insert provided params
		$toInsert = array();
		$columns = array('name', 'date_of_birth', 'gender', 'hometown',
			'facebook_connected', 'facebook_id');
		foreach ($columns as $paramName) {
			if (!is_null($$paramName)) {
				$toInsert[$paramName] = $$paramName;
			}
		}
		// handle uploaded avatar
		if (!empty($avatar)) {
			$toInsert['avatar_url'] = $this->_processAvatar($account->id, $avatar);
		}

		$account->getRow()->update($toInsert);

		return true;
	}


	/**
	 * Get user details
	 *
	 * @return array
	 */
	public function findprofile($device_hash = null) {

		// get the logged account
		$account = $this->apiSession->getUser();
		if (!$account) {
			throw new Rpc2\RPCError(
				'Profile info not allowed for visitors',
				Constants::RPC_ERROR_NOT_ALLOWED_FOR_VISITORS
			);
		}

		$detail = $account->getProfile(array(
			'name',
			'email',
			'date_of_birth',
			'gender',
			'hometown',
			'avatar_url',
			'facebook_connected',
			'referral_code',
		)) + array('referral_link'
			=> $this->linkGenerator
				->link('Account:create', [$account->referral_code]));

		// format date only if present
		$detail['date_of_birth'] = empty($detail['date_of_birth'])
			? null
			: explode(' ', $detail['date_of_birth']->format('Y-m-d'))[0];

		// return full absolute image URL, only if present
		$detail['avatar_url'] = empty($detail['avatar_url'])
			? null
			: $this->_getBaseUrl() . $detail['avatar_url'];

		if (!is_null($device_hash)) {
			$device = $this->accountNotification->getOneFiltered(
				'active',
				array('hash' => $device_hash)
			);
			if (empty($device)) {
				$detail['notifications'] = null;
			} else {
				$detail['notifications'] = $device->active;
			}
		}

		return $detail;
	}


	/**
	 * Get application-wide base URL
	 *
	 * @return string
	 */
	protected function _getBaseUrl($trim = true)
	{
		return (!empty($_SERVER['HTTPS']) && strcasecmp($_SERVER['HTTPS'], 'off')
			? 'https://'
			: 'http://')
				. $_SERVER['HTTP_HOST'] . ($trim ? '' : '/');
	}


	/**
	 * Get user email by certain unique identifiers, currently facebook_id
	 * Others like twitter ID, google acc/ID can be added easily in future
	 *
	 * @param  string|null	$facebook_id
	 * @return string|null
	 */
	public function getemailby($facebook_id = null)
	{
		$availableFilters = array('facebook_id');

		// find and assign first non-empty filter
		$filter = array();
		foreach ($availableFilters as $filterName) {
			if (!is_null($$filterName)) {
				$filter[$filterName] = $$filterName;
				break;
			}
		}
		// handle no filter supplied possibility
		if (count($filter) === 0) {
			throw new Rpc2\RPCError(
				'At least one filter param has to be supplied',
				Constants::RPC_ERROR_EMPTY_PARAM_VALUE
			);
		}
		$account = $this->collection->getOneFiltered(
			'email', $filter
		);
		// no record for the set filter => return null
		$return = array(key($filter) => current($filter));
		if (!$account) {
			return $return + array('email' => null);
		}
		// return provided filter key and matching email (= username)
		return $return + array('email' => $account->email);
	}


	/**
	 * Add/Update user device in order to be able to know where to send push
	 * notifications later.
	 * Implicitly activate. Optionally supply platform: 'android' or 'ios'
	 *
	 * @param  string	$hash
	 * @param  bool		$active
	 * @param  string	$platform
	 * @return string
	 */
	public function updatedevice($hash, $active = true, $platform = null)
	{
		// get the logged account
		$account = $this->apiSession->getUser();
		if (!$account) {
			throw new Rpc2\RPCError(
				'Notifications not allowed for visitors',
				Constants::RPC_ERROR_NOT_ALLOWED_FOR_VISITORS
			);
		}
		// convert strings of 'ios' or 'android' into 'is_apple' DB bool
		$writePlatform = null;
		if (!is_null($platform)) {
			switch ($platform) {
			case 'android':
				$writePlatform = false;
				break;
			case 'ios':
				$writePlatform = true;
				break;
			}
		}
		// does record having this hash exist?
		$devicePresent = $this->accountNotification->getOneFiltered(
			'*',
			array('hash' => $hash)
		);
		// update the record
		if ($devicePresent) {
			$devicePresent->update(array(
				'active' => $active,
				'is_apple' => $writePlatform,
				'account_id' => $account->id,
			));

			return true;
		}

		// insert a new record
		$this->accountNotification->insert(array(
			'account_id' => $account->id,
			'hash' => $hash,
			'active' => $active,
			'is_apple' => $writePlatform,
		));

		return true;
	}


	/**
	 * Process uploaded user avatar.
	 * Accept Base64 encoded image as an input, return image URL
	 *
	 * @param  int		$userId
	 * @param  string	$avatar
	 * @return string
	 */
	protected function _processAvatar($userId, $avatar)
	{
		if (preg_match('/^http/', $avatar)) {
			$imgString = file_get_contents($avatar);
		} else {
			$imgString = base64_decode($avatar);
		}
		if (!$imgString) {
			throw new Rpc2\RPCError(
				'Avatar has to be either a valid base64 string or valid URL',
				Constants::RPC_ERROR_INVALID_PARAMS_FORMAT
			);
		}
		$meta = array('name' => 'user', 'id' => $userId);

		return \Argo22\Modules\Core\Services\ImageHandler
			::saveImg($imgString, $meta);
	}


	/**
	 * Check input params for completeness. Basic prevalidation check
	 *
	 * @param  string	$password
	 * @param  string	$facebook_id
	 * @return void
	 */
	static protected function _checkInput($password, $facebook_id) {
		if (is_null($password) && is_null($facebook_id)) {
			throw new Rpc2\RPCError(
				'Either facebook id or password has to be supplied',
				Constants::RPC_ERROR_EMPTY_PARAM_VALUE
			);
		}
	}


	/**
	 * Set referral code for user
	 *
	 * @param  string	$referral
	 * @return string
	 */
	public function setreferralcode($referral)
	{
		// get the logged account
		$account = $this->apiSession->getUser();
		if (!$account) {
			throw new Rpc2\RPCError(
				'Setting referral code not allowed for visitors',
				Constants::RPC_ERROR_NOT_ALLOWED_FOR_VISITORS
			);
		}

		// uppercase referral code and try to fetch referrer account
		$referral = strtoupper($referral);
		$validCode = $this->collection->getOneFiltered('*', array(
			'referral_code' => $referral,
		));
		// referral code not valid or stale (referrer account deleted)
		if (!$validCode) {
			throw new Rpc2\RPCError(
				'Referrer account not found',
				Constants::RPC_ERROR_ITEM_NOT_FOUND
			);
		}
		// attempt to invite self
		if ($account->id === $validCode->id) {
			throw new Rpc2\RPCError(
				'An account can not invite self',
				Constants::RPC_ERROR_INVALID_PARAMS_FORMAT
			);
		}

		// bind the inviter
		$account->update(array(
			'inviter_account_id' => $validCode->id,
		));

		return true;
	}
}
