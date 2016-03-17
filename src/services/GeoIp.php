<?php
namespace Argo22\Modules\Core\Api;

class GeoIp {

	const DB_LOC_COUNTRY = '/data/GeoLite2-Country.mmdb';
	const VOCABULARY_LOC = '/data/isoCountryCodes.php';

	static private $_geoIpInstance;
	static private $_vocabulary;

	/**
	 * Query GeoLite2 database to find country code based on IP address
	 * Returns two chars long country code based on ISO 3166
	 *
	 * @param  string	$ip
	 * @return string
	 */
	public function getCountryCodeForIp($ip)
	{
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			throw new \Exception("Supplied IP address ($ip) is invalid");
		}
		if (is_null(self::$_geoIpInstance)) {
			self::$_geoIpInstance
				= new \GeoIp2\Database\Reader(WWW_DIR .'/../'. self::DB_LOC_COUNTRY);
		}
		try {
			$record = self::$_geoIpInstance->country($ip);
		} catch (\Exception $e) {
			return null;
		}

		return $record->country->isoCode;
	}


	/**
	 * Get human readable english country name for its ISO code
	 *
	 * @param  string	$code
	 * @return string
	 */
	public function getCountryNameForIsoCode($code)
	{
		if (is_null(self::$_vocabulary)) {
			ob_start();
			self::$_vocabulary = include(WWW_DIR . '/../' . self::VOCABULARY_LOC);
			ob_end_clean();
		}
		if (is_null($code) || !array_key_exists($code, self::$_vocabulary)) {
			return null;
		}

		return self::$_vocabulary[$code];
	}
}

