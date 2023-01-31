<?php

/**
 * Class FileIni
 * Упрощеает чтение/запись в INI-файл
 */
class FileIni {
	private $iniFilePath, $processSections;

	public function __construct($iniFilePath, $processSections = false){
		if(!file_exists($iniFilePath)){
			throw new Exception("Error: There is no ini file <{$iniFilePath}>");
		}

		$this->iniFilePath = $iniFilePath;
		$this->processSections = $processSections;
	}

	public function getContent(){
		return file_get_contents($this->iniFilePath);
	}

	private function parse_ini_file_multi($file, $process_sections = false, $scanner_mode = INI_SCANNER_NORMAL) {
		$explode_str = '.';
		$escape_char = "'";
		// load ini file the normal way
		$data = parse_ini_file($file, $process_sections, $scanner_mode);
		if (!$process_sections) {
			$data = array($data);
		}
		foreach ($data as $section_key => $section) {
			// loop inside the section
			foreach ($section as $key => $value) {
				if (strpos($key, $explode_str)) {
					if (substr($key, 0, 1) !== $escape_char) {
						// key has a dot. Explode on it, then parse each subkeys
						// and set value at the right place thanks to references
						$sub_keys = explode($explode_str, $key);
						$subs =& $data[$section_key];
						foreach ($sub_keys as $sub_key) {
							if (!isset($subs[$sub_key])) {
								$subs[$sub_key] = [];
							}
							$subs =& $subs[$sub_key];
						}
						// set the value at the right place
						$subs = $value;
						// unset the dotted key, we don't need it anymore
						unset($data[$section_key][$key]);
					}
					// we have escaped the key, so we keep dots as they are
					else {
						$new_key = trim($key, $escape_char);
						$data[$section_key][$new_key] = $value;
						unset($data[$section_key][$key]);
					}
				}
			}
		}
		if (!$process_sections) {
			$data = $data[0];
		}
		return $data;
	}

	public function get($param){
		$fileData = $this->parse_ini_file_multi($this->iniFilePath, $this->processSections);

		$paramsList = explode('.', $param);
		foreach($paramsList as $key){
			if(!isset($fileData[$key])){
				$res = null;
				break;
			}

			$res = $fileData[$key];
			$fileData = $res;
		}

		return $res;
	}

	public function set($param, $val){
		// $param - это строка типа 'main.foobar', которую нужно преобразовать в реальный массив
		// $config['main']['foobar'] = 'baz';

		$paramsList = explode('.', $param);

		$params = $this->parse_ini_file_multi($this->iniFilePath, $this->processSections);

		$pointer = '$params';
		while(count($paramsList)){
			$key = array_shift($paramsList);

			$pointer .= "['$key']";

			$exists = true;
			eval("\$exists = isset($pointer);");
			if(!$exists){
				$prevPointer = preg_replace('/\[\'\w+\'\]$/', '', $pointer);

				$isArr = true;
				eval("\$isArr = is_array($prevPointer);");
				if(!$isArr){
					$str = "$prevPointer=[];";
					eval("$str");
				}
			}
		}

		if(is_string($val)){
			$str = "$pointer='$val';";
		}
		else {
			$str = "$pointer=$val;";
		}
		eval("$str");


		$data = [];
		foreach ($params as $key => $val) {
			if (is_array($val)) {
				$data[] = "[$key]";
				foreach ($val as $skey => $sval) {
					if (is_array($sval)) {
						foreach ($sval as $_skey => $_sval) {
							if (is_numeric($_skey)) {
								$data[] = $skey.'[] = '.(is_numeric($_sval) ? $_sval : (ctype_upper($_sval) ? $_sval : '"'.$_sval.'"'));
							} else {
								$data[] = $skey.'['.$_skey.'] = '.(is_numeric($_sval) ? $_sval : (ctype_upper($_sval) ? $_sval : '"'.$_sval.'"'));
							}
						}
					} else {
						$data[] = $skey.' = '.(is_numeric($sval) ? $sval : (ctype_upper($sval) ? $sval : '"'.$sval.'"'));
					}
				}
			} else {
				$data[] = $key.' = '.(is_numeric($val) ? $val : (ctype_upper($val) ? $val : '"'.$val.'"'));
			}
			// empty line
			$data[] = null;
		}

		// open file pointer, init flock options
		$fp = fopen($this->iniFilePath, 'w');
		$retries = 0;
		$max_retries = 100;

		if (!$fp) {
			return false;
		}

		// loop until get lock, or reach max retries
		do {
			if ($retries > 0) {
				usleep(rand(1, 5000));
			}
			$retries += 1;
		} while (!flock($fp, LOCK_EX) && $retries <= $max_retries);

		// couldn't get the lock
		if ($retries == $max_retries) {
			return false;
		}

		// got lock, write data
		fwrite($fp, implode(PHP_EOL, $data).PHP_EOL);

		// release lock
		flock($fp, LOCK_UN);
		fclose($fp);

		return true;
	}

	
}