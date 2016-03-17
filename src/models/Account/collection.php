<?php

namespace Argo22\Modules\Core\Account;

class Collection extends \Argo22\Core\DataModel\Collection
	implements \Argo22\Modules\Core\User\IPasswordRecovery
{
	/** @var \App\Models\AccountSubscription\Collection @inject */
	var $accSubs;
	/** @var \App\Services\GeoIp @inject */
	var $geoIp;

	/**
	 * Create new mobile account
	 *
	 * @param  array	$values
	 * @param  bool		$free60
	 * @return model
	 */
	public function createNew($values, $free60 = false)
	{
		// store referring user if present and possible
		if (!empty($values['referral'])) {
			$referrer = $this->getOneFiltered('*', array(
				'referral_code' => $values['referral'],
			));
			if ($referrer) {
				$values['inviter_account_id'] = $referrer->id;
			}
		}
		$device = !isset($values['device'])?:$values['device'];
		unset($values['device']);
		unset($values['referral']);

		$values['created'] = new \DateTime();
		$values['country_code']
			= $this->geoIp->getCountryCodeForIp($_SERVER['REMOTE_ADDR']);
		$values['facebook_connected'] = isset($values['facebook_id'])
			? (bool)$values['facebook_id']
			: false;
		$model = parent::insert($values);
		$this->_generateReferralCode($model);
		// set password by dedicated method, so it's hashed before its first use
		$model->setPassword($values['password']);

		// set free subscription - anniversary 60
		if ($free60) {
			$this->accSubs->setFree($model->id, $values['country_code'], $device);
		}

		return $model;
	}


	/**
	 * Get user model by email
	 * Required by IPasswordRecovery
	 *
	 * @param  string								$email
	 * @return Argo22\Modules\Core\Account\Model|false
	 */
	public function getByEmail($email)
	{
		return $this->getOneFiltered('*', array('email' => $email));
	}


	/**
	 * Get user model by hash
	 * Required by IPasswordRecovery
	 *
	 * @param  string								$hash
	 * @return Argo22\Modules\Core\Account\Model|false
	 */
	public function getByHash($hash)
	{
		// failsafe check
		if (empty($hash)) {
			return false;
		}
		return $this->getOneFiltered('*', array('recovery_hash' => $hash));
	}


	/**
	 * Create new password recovery request
	 * Required by IPasswordRecovery
	 *
	 * @param  string	$email
	 * @param  string	$hash
	 * @param  string	$expiresAt
	 * @return void
	 */
	public function setRequest($email, $hash, $expiresAt)
	{
		$item = $this->getOneFiltered('*', array('email' => $email));
		$item->update(array(
			'recovery_hash' => $hash,
			'recovery_expires_at' => $expiresAt,
		));
	}


	/**
	 * Create new password recovery request
	 * Required by IPasswordRecovery
	 *
	 * @param  string	$hash
	 * @param  string	$password
	 * @return void
	 */
	public function setPassword($hash, $password)
	{
		$item = $this->getByHash($hash);
		$this->_handleAbsence($item);
		$item->setPassword($password);
	}


	/**
	 * Clear password recovery request
	 * Required by IPasswordRecovery
	 *
	 * @param  string	$hash
	 * @return void
	 */
	public function clearRequest($hash)
	{
		$item = $this->getByHash($hash);
		$this->_handleAbsence($item);
		$item->update(array(
			'recovery_hash' => null,
			'recovery_expires_at' => null,
		));
	}


	/**
	 * Get string reprezentation of time, when password recovery request expires
	 * Required by IPasswordRecovery
	 *
	 * @param  string	$hash
	 * @return string
	 */
	public function getExpiresAt($hash)
	{
		$item = $this->getByHash($hash);
		$this->_handleAbsence($item);

		return $item->recovery_expires_at;
	}


	/**
	 * Throw exception in case there is no record on the input
	 *
	 * @param  Argo22\Modules\Core\Account\Model|false	$item
	 * @return string
	 */
	private function _handleAbsence($item)
	{
		if ($item === false) {
			throw new \Exception('Account not found!');
		}
	}


	/**
	 * Get and store unique referral code for a user
	 *
	 * @param  \Argo22\Modules\Core\Account\Model	$user
	 * @return void
	 */
	protected function _generateReferralCode($account)
	{
		$code = self::_generateUniqueCode($account->id);
		$account->update(array('referral_code' => $code));
	}


	/**
	 * Generate 7 digits long unique id per integer
	 * while omitting B8G6I1l0OQDS5Z2 chars
	 *
	 * @param  int		$int
	 * @return string
	 */
	static private function _generateUniqueCode($int)
	{
		$id = (string)$int;
		$bind = array('3', '4', '7', '9', 'V', 'W', 'X', 'Y',);
		$coded = '';
		foreach (str_split(decoct($id)) as $char) {
			$coded .= $bind[$char];
		}

		return substr(
			str_shuffle(str_repeat("ACEFHJKLMNPRTU", 5)),
			0,
			7 - strlen($coded)
		) . $coded;
	}
}
