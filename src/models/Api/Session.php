<?php

namespace Argo22\Modules\Core\Api\Account;

use \Nette\Utils;
use \Nette\Utils\Strings;

/**
 * Class for handling API sessions via tokens.
 * This class isn't directly accessible by API calls.
 * Upon successful authorization, account instance is stored within class
 */
class Session extends \Nette\Object {
	/** @var Collection @inject **/
	var $collection;

	private $_account = null;

	private $_database;

	/**
	 * Sets table to be used for user
	 *
	 * @param Nette\Database\Selection $table
	 */
	public function __construct(\Nette\Database\Context $database)
	{
		$this->_database = $database;
	}


	/**
	 * Getter for the table instance
	 *
	 * @return \Nette\Database\Table\Selection
	 */
	public function getTable()
	{
		return $this->_database->table('account_api_session');
	}


	/**
	 * Getter for user
	 *
	 * @return Model|null
	 */
	public function getUser()
	{
		return $this->_account;
	}


	/**
	 * Returns whether sessions has valid user
	 *
	 * @return bool
	 */
	public function isLoggedIn()
	{
		return is_null($this->user) ? false : true;
	}


	/**
	 * Creates and returns token for the user
	 *
	 * @param  integer $accId
	 * @return string
	 */
	public function generateToken($accId)
	{
		$user = $this->collection->getById($accId);

		if (is_null($user)) {
			throw new \Exception("Account id '{$accId}' not valid");
		}

		$token = Strings::random(64);

		$this->table->insert(array(
			'account_id' => $accId,
			'token' => $token,
			'created' =>  new \DateTime(),
		));

		return $token;
	}


	/**
	 * Returns user model for given token. If user is found, it is also stored
	 * in read-only $this->user
	 *
	 * @param  string $token
	 * @return Model|null
	 */
	public function loadFrom($token)
	{
		$d = $this->table;
		$d = $d->where('token', $token)
			->select('account_id')
			->fetch();

		if ( $d !== false) {
			return $this->_account = $this->collection->getById($d->account_id);
		}

		return null;
	}


	/**
	 * Destroy all sessions for given user
	 *
	 * @return null
	 */
	public function destroy()
	{
		$d = $this->table;
		$d = $d->where('account_id', $this->_account->id)
			->delete();

		return null;
	}
}
