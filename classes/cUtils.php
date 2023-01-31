<?php

/**
 * Class Utils
 */
class Utils {

	public function dump($data){
		$res = '';
		if(is_array($data)){
			$res = json_encode($data);
		}
		else {
			$res = $data;
		}

		echo "{$res}\n";
	}

	public function stringToSeconds($string){
		$fail = 99999999;
		$secondsPerUnit = ["s" => 1, "m" => 60, "h" => 3600, "d" => 86400, "w" => 604800];

		preg_match('/^([0|\d]+)([s|m|h|d|w]?)$/', $string, $matches);
		if(!(isset($matches[0]) && isset($matches[1]) && isset($matches[2]) && !empty($matches[0]) && is_numeric($matches[1]) && !empty($matches[2]))){
			echo var_dump($matches);
			return $fail;
		}

		return $matches[1] * $secondsPerUnit[$matches[2]];
	}
}