<?php

namespace Argo22\Modules\Core\Account;

class Model extends \Argo22\Core\DataModel\Model {

	/**
	 * Constants
	 */
	const STATE_NEW = 'NEW';
	const STATE_WAITING_FOR_ACTIVATION = 'WAITING_FOR_ACTIVATION';
	const STATE_ACTIVE = 'ACTIVE';

	const SOURCE_WEB = 'WEB';
	const SOURCE_APP = 'APP';


	/**
	 * Switch user to state self::WAITING_FOR_ACTIVATION
	 *
	 * @return void
	 */
	public function setActivationRequest()
	{
		if ($this->state !== self::STATE_NEW) {
			throw new \Exception('Invalid flow, only users in state of '
				. self::STATE_NEW
				.' are allowed to be sent an activation email.'
			);
		}
		$now = new \DateTime();
		$nowMysql = $now->format('Y-m-d H:i:s');
		$this->update(array(
			'state' => self::STATE_WAITING_FOR_ACTIVATION,
			'activation_hash' => $this->getActivationHash(),
			'activation_email_sent' => $nowMysql,
		));
	}


	/**
	 * Switch user to self::ACTIVE state
	 *
	 * @return void
	 */
	public function activate()
	{
		if ($this->state !== self::STATE_WAITING_FOR_ACTIVATION) {
			throw new \Exception('Invalid flow, only users in state of '
				. self::STATE_WAITING_FOR_ACTIVATION
				.' are allowed to be activated.'
			);
		}
		$this->update(array(
			'state' => self::STATE_ACTIVE,
		));
	}


	/**
	 * Get profile information
	 *
	 * @param  array	$params
	 * @return array
	 */
	public function getProfile($params = array())
	{
		$data = array();
		foreach ($params as $colName) {
			$data[$colName] = $this->$colName;
		}

		return $data;
	}


	public function getIdentity() {

		$data = array(
			'email' => $this->email,
			'account_id' => $this->id,
		);

		return new \Nette\Security\Identity($this->id, array(), $data);
	}


	/**
	 * Check if the $plain matches hashed password.
	 * By default also updates hashing ALG if needed
	 *
	 * @param  string  $plain
	 * @param  boolean $rehash indicates if the password should be rehashed if needed
	 * @return bool
	 */
	public function checkPassword($plain, $rehash = true){
		$hashed = $this->password;

		// if hash doesn't starts with $ symbol, assume, it's plain text
		if (substr($hashed, 0, 1) !== '$') {
			$this->setPassword($hashed);
			$hashed = $this->password;
		}

		$result = password_verify($plain, $hashed);

		if ($result === false || $rehash !== true) {
			return $result;
		}

		// $result from hereafter must be true
		if (password_needs_rehash($hashed, self::_getPasswordAlg())) {
			$this->setPassword($plain);
		}

		return $result;
	}


	public function setPassword($plain){
		$this->update(array(
			'password' => password_hash($plain, self::_getPasswordAlg())
		));
	}


	/**
	 * Returns password alg
	 *
	 * @return string
	 */
	static protected function _getPasswordAlg()
	{
		return defined('PASSWORD_DEFAULT') ? PASSWORD_DEFAULT : PASSWORD_BCRYPT;
	}


	/**
	 * Returns password alg
	 *
	 * @return string
	 */
	public function getActivationHash()
	{
		return md5($this->id . $this->created->format('Y#m#d#H#i#s'));
	}
}
