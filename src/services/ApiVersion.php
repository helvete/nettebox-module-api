<?php
namespace Argo22\Modules\Core\Api;

class ApiVersion extends \Nette\Object {

	/**
	 * @var	array
	 */
	private $_versions;

	/**
	 * Class construct
	 */
	public function __construct(array $versions)
	{
		$this->_versions = self::_parseConfig($versions);
	}


	/**
	 * Parse data from config into more suitable format
	 *
	 * @param  array	$versions
	 * @return array
	 */
	static protected function _parseConfig($versions)
	{
		$return = array();
		foreach ($versions as $version) {
			if (!empty($version)) {
				$methods = array();
				foreach ($version['methods'] as $combination) {
					list($model, $method) = explode('.', $combination);
					$methods[$model][] = $method;
				}
				$return[$version['version']] = array(
					'deprecate_at' => new \DateTime($version['threshold']),
					'override' => $methods,
					'suffix' => preg_replace('/\./', '', $version['version']),
				);
			}
		}

		return $return;
	}


	/**
	 * Get overrides list for queried version
	 *
	 * @param  string	$version
	 * @return array
	 */
	public function getOverrides($version)
	{
		if (!preg_match('/^[0-9]{1,}\.[0-9]{1,}\.[0-9]{1,}$/', $version)) {
			throw new \Exception("Incompatible version identifier '$version'");
		}
		// get versions
		$def = array_keys($this->_versions);

		// use natsort to sort version numbers, get values to reindex the array
		natsort($def);
		$def = array_values($def);

		// split version number to be able to compare each part
		$qvSeq = explode('.', $version);
		foreach ($def as $verThreshold){
			$vtSeq = explode('.', $verThreshold);

			for ($i = 0; $i < 3; $i++) {
				// processed version higher than definition, try next defined ver
				if ($qvSeq[$i] > $vtSeq[$i]) {
					continue 2;
				}
				// processed version is the same, try next. return if the last is the same
				if ($qvSeq[$i] === $vtSeq[$i]) {
					if ($i === 2) {
						return $this->_versions[$verThreshold];
					}
					continue 1;
				}
				// processed version is lower, return immediately
				if ($qvSeq[$i] < $vtSeq[$i]) {
					return $this->_versions[$verThreshold];
				}
			}
		}

		return array();
	}
}
