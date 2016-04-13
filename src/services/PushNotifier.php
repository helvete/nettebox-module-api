<?php
namespace Argo22\Modules\Core\Api;

class PushNotifier extends \Nette\Object {

	/**
	 * Google servers API access key
	 *
	 * @var string
	 */
	private $_googleData;

	/**
	 * Apple service certificates
	 *
	 * @var array
	 */
	private $_appleData = array();

	/**
	 * Shall apple sandbox mode be tried in case of unsuccessful
	 * notification dispatch?
	 *
	 * @var bool
	 */
	private $_tryAppleSandbox = false;

	/**
	 * Push notification constants
	 */
	const APPLE_DEV = \ApnsPHP_Abstract::ENVIRONMENT_SANDBOX;
	const APPLE_PROD = \ApnsPHP_Abstract::ENVIRONMENT_PRODUCTION;
	const APPLE_ENTRUST = 'entrust';
	const GOOGLE_API_URL = 'https://android.googleapis.com/gcm/send';

	/**
	 * Class construct
	 * - init using options structure like this:
	 * $options = [
	 *	'googleData' => '<googleApiKey>',
	 *	'appleData' => [
	 *		\App\Services\PushNotifier::APPLE_DEV => '</absolute/path/to/certificate>',
	 *		\App\Services\PushNotifier::APPLE_PROD => '</absolute/path/to/certificate>',
	 *		\App\Services\PushNotifier::APPLE_ENTRUST => '</absolute/path/to/certificate>',
	 *	],
	 * ];
	 */
	public function __construct($options = array(), $trySandbox = false)
	{
		foreach ($options as $dataName => $dataValue) {
			$propName =  "_$dataName";
			if (property_exists($this, $propName)) {
				$this->$propName = $dataValue;
				continue;
			}
			throw new \Exception("Unknown property 'self::$propName'");
		}

		// whether to attempt to send the notification using sandbox API
		// in case production API fails
		$this->_tryAppleSandbox = $trySandbox;
	}


	/**
	 * Send the notifications
	 * accepts notifications in format of array like this:
	 * [
	 *	'ios' => [
	 *		'<device_hash>' => [
	 *			'message' => string,
	 *			'dialogMessage' => string,
	 *			'title' => string,
	 *			'dialogTitle' => string,
	 *			.....
	 *		],
	 *	],
	 *	'android' => [
	 *		'<device_hash>' => [
	 *			'message' => string,
	 *			'dialogMessage' => string,
	 *			'title' => string,
	 *			'dialogTitle' => string,
	 *			.....
	 *		],
	 *	],
	 * ]
	 *
	 * @param  array	$notifications
	 * @return void
	 */
	public function send($notifications)
	{
		$this->_checkConfigSettings();
		foreach (array('android', 'ios') as $pf) {
			if (!isset($notifications[$pf])) {
				continue;
			}
			$log[$pf] = $this->{"send".ucfirst($pf)}($notifications[$pf]);
		}

		return $log;
	}


	/**
	 * Send pack of android messages
	 *
	 * @param  array	$notifications
	 */
	public function sendAndroid($notifications)
	{
		$this->_checkConfigSettings();
		$headers = array(
			'Authorization: key=' . $this->_googleData,
			'Content-Type: application/json'
		);

		$id = time();
		$log = array();
		foreach ($notifications as $hash => $data) {
			$data['notId'] = ++$id;

			$fields = array(
				'registration_ids' => array($hash),
				'data' => $data,
			);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, self::GOOGLE_API_URL);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
			$result = curl_exec($ch);
			curl_close($ch);

			$log[$hash] = $result;
		}

		return $log;
	}


	/**
	 * Send pack of ios messages, optionally fallback to sandbox mode
	 *
	 * @param  array	$notifications
	 * @param  int		$mode
	 * @return array
	 */
	public function sendIos($notifications, $mode = self::APPLE_PROD)
	{
		$this->_checkConfigSettings();

		// doublecheck emptiness - cannot send 0 notifications
		if (count($notifications) < 1) {
			return array();
		}

		// init push lib
		$push = new \ApnsPHP_Push($mode, $this->_appleData[$mode]);
		$push->setRootCertificationAuthority($this->_appleData[self::APPLE_ENTRUST]);

		// the library emits log message to stdout, therefore utilize output buffering
		ob_start();
		$push->connect();
		$log = array();
		foreach ($notifications as $hash => $data) {
			$msg = $data;
			try {
				$message = new \ApnsPHP_Message($hash);
			} catch(\Exception $e) {
				$log['invalid_token'][$hash] = $e->getMessage();
				continue;
			}
			$message->setText($msg['message']);
			unset($msg['message']);
			// important; do not change if not sure what this influences
			$message->setBadge(0);

			array_map(
				array($message, 'setCustomProperty'),
				array_keys($msg),
				array_values($msg)
			);
			$push->add($message);
		}
		// handle message init/addition error and therefore empty queue
		if (count($push->getQueue(false)) > 0) {
			$push->send();
		}
		$push->disconnect();
		$log[$mode][$hash] = ob_get_clean();

		$aErrorQueue = $push->getErrors();

		// sandbox mode fallback: is attempted only in case of prod error (and if allowed)
		if (!empty($aErrorQueue) && $mode === self::APPLE_PROD && $this->_tryAppleSandbox) {
			$resend = array();
			foreach ($aErrorQueue as $i => $data) {
				if (isset($data['ERRORS'][0]['statusMessage'])
					&& $data['ERRORS'][0]['statusMessage'] === 'Invalid token'
				) {
					$hash = $data['MESSAGE']->getRecipient();
					$resend[$hash] = $notifications[$hash];
				}
			}
			return $log + $this->sendIos($resend, self::APPLE_DEV);
		}

		return $log;
	}


	/**
	 * Set google connection data
	 *	- key
	 *
	 * @param  string	$key
	 * @retunr self
	 */
	public function setGoogleData($key)
	{
		$this->_googleData = $key;

		return $this;
	}


	/**
	 * Set apple connection data
	 *	[
	 *		self::APPLE_DEV => path_to_dev_cert,
	 *		self::APPLE_PROD => path_to_prod_cert,
	 *		self::APPLE_ENTRUST => path_to_entrust_cert,
	 *	]
	 *
	 * @param  string	$key
	 * @retunr self
	 */
	public function setAppleData(array $data)
	{
		$this->_appleData = $data;

		return $this;
	}


	/**
	 * Pre-send config settings check
	 *
	 * @return void
	 */
	private function _checkConfigSettings()
	{
		// android
		if (is_null($this->_googleData)) {
			throw new \Exception('Google API key not set!');
		}
		// ios
		if (empty($this->_appleData)
			|| empty($this->_appleData[self::APPLE_PROD])
			|| empty($this->_appleData[self::APPLE_DEV])
			|| empty($this->_appleData[self::APPLE_ENTRUST])
			|| !file_exists($this->_appleData[self::APPLE_PROD])
			|| !file_exists($this->_appleData[self::APPLE_DEV])
			|| !file_exists($this->_appleData[self::APPLE_ENTRUST])
		) {
			throw new \Exception('Apple API connection data not set or '
				. 'certificate files inaccessible');
		}
	}
}
