<?php
namespace Argo22\Modules\Core\Api\Account;

use \Nette\Utils\Validators;

class Validator extends \Nette\Object{
	/** @var Collection */
	protected $collection;

	const PASSWORD_MIN_LENGTH = 6;

	public function __construct(Collection $collection)
	{
		$this->collection = $collection;
	}

	/**
	 * Performs validation
	 * directs all items to be validated to appropriate methods base on a name
	 * 'email' -> self::_validateEmail, etc
	 * Accepts array of items to validat in this structure:
	 *	$validants = array(
	 *		'email' => $email,
	 *		'password' => array(
	 *			'value' => $password,
	 *			'login' => $email,
	 *		),
	 *	);
	 * The key is a name of item to validate and a value or values are data to
	 * be validated (or validated by) - depends on individual val. method
	 *
	 * @param  array	$validants
	 * @return array
	 */
	public function validate(array $validants)
	{
		$return = array('status' => 'OK');
		foreach ($validants as $disc => $input) {
			$valMetName = "_validate" . ucfirst($disc);
			if ( ! method_exists($this, $valMetName)) {
				throw new \Exception("Validation of '$disc' not implemented.");
			}
			$result = $this->$valMetName($input);
			if ($result['status'] === 'error') {
				return $result;
			}
		}

		return $return;
	}


	/**
	 * Validate email
	 *
	 * @param  string	$input
	 * @return array
	 */
	private function _validateEmail($input)
	{
		// validate syntax
		$result = Validators::isEmail($input);
		if ( ! $result) {
			return $this->_err(
				"'$input' is not a valid email address.",
				\App\Models\Api\Constants::RPC_ERROR_INVALID_EMAIL
			);
		}
		// validate email address has not yet been added
		if ($this->collection->getByEmail($input)) {
			return $this->_err(
				"User with the provided email already exists.",
				\App\Models\Api\Constants::RPC_ERROR_USERNAME_TAKEN
			);
		}

		return array('status' => 'OK');
	}


	/**
	 * Validate selected username. Current one should be allowed whereas
	 * other already taken should not
	 *
	 * @param  string	$input
	 * @return array
	 */
	private function _validateUsernamechange($input)
	{
		// input param check
		if (empty($input['value']) || empty($input['account_id'])) {
			throw new \Exception("Incorrect input syntax.");
		}
		// validate syntax
		$result = Validators::isEmail($input['value']);
		if ( ! $result) {
			return $this->_err(
				"'{$input['value']}' is not a valid email address.",
				\App\Models\Api\Constants::RPC_ERROR_INVALID_EMAIL
			);
		}
		// validate username not to be taken by anyone except current user
		$taken = $this->collection->getTable()
			->where('email', $input['value'])
			->where("id != {$input['account_id']}")
			->count();

		if ($taken) {
			return $this->_err(
				"User with the provided email already exists.",
				\App\Models\Api\Constants::RPC_ERROR_USERNAME_TAKEN
			);
		}

		return array('status' => 'OK');
	}


	/**
	 * Validate password
	 * Input is array of an actual password value and a value of login string.
	 * It is so to easily validate possible equality of passwd with login
	 * while preserving maintainable validation structure
	 *
	 * @param  array	$input
	 * @return array
	 */
	private function _validatePassword(array $input)
	{
		// input param check
		if (empty($input['value']) || empty($input['login'])) {
			throw new \Exception("Incorrect input syntax.");
		}
		// handle case numeric password is supplied
		$input['value'] = (string)$input['value'];

		// validate password length
		$len = self::PASSWORD_MIN_LENGTH;
		$result = Validators::is($input['value'], "string:{$len}..");
		if ( ! $result) {
			return $this->_err(
				"Password has to be at least $len letters long.",
				\App\Models\Api\Constants::RPC_ERROR_INVALID_PASSWORD
			);
		}
		// validate possible equality with login string (=email)
		if ($input['value'] === $input['login']) {
			return $this->_err(
				"Password and login must not be the same.",
				\App\Models\Api\Constants::RPC_ERROR_INVALID_PASSWORD
			);
		}

		return array('status' => 'OK');
	}


	/**
	 * Shorthand method for returning error validation array
	 *
	 * @param  string	$message
	 * @param  string	$code
	 * @return array
	 */
	private function _err($string, $code)
	{
		return array(
			'status' => 'error',
			'code' => $code,
			'message' => $string,
		);
	}
}

