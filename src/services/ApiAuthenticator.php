<?php
namespace Argo22\Modules\Core\Api\Account;

#use \Nette\Utils\Strings;
use \Nette\Security;

class ApiAuthenticator extends \Nette\Object
{
	/** @var Collection */
	protected $collection;

	public function __construct(Collection $collection)
	{
		$this->collection = $collection;
	}


	/**
	 * Performs an authentication
	 * @param  array
	 * @param  bool
	 * @return Identity
	 * @throws AuthenticationException
	 */
	public function authenticate(array $credentials)
	{
		list($email, $password) = $credentials;
		$account = $this->collection->getByEmail($email);
		if ( ! $account) {
			throw new Security\AuthenticationException(
				"User having email '$email' not found.",
				Security\IAuthenticator::IDENTITY_NOT_FOUND
			);
		}

		if ( ! $account->checkPassword($password)) {
			throw new Security\AuthenticationException(
				'Invalid password.',
				Security\IAuthenticator::INVALID_CREDENTIAL
			);
		}

		return $account->getIdentity();
	}


	/**
	 * Performs an authentication by FB id
	 *
	 * @param  array
	 * @return Identity
	 * @throws AuthenticationException
	 */
	public function authenticateFb(array $credentials)
	{
		list($email, $facebookId) = $credentials;
		$account = $this->collection->getByEmail($email);
		if ( ! $account) {
			throw new Security\AuthenticationException(
				"User having email '$email' not found.",
				Security\IAuthenticator::IDENTITY_NOT_FOUND
			);
		}

		if ($account->facebook_id !== $facebookId) {
			throw new Security\AuthenticationException(
				'Invalid Facebook id.',
				Security\IAuthenticator::INVALID_CREDENTIAL
			);
		}

		return $account->getIdentity();
	}
}
