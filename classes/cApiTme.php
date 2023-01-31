<?php

/**
 * Class ApiTme
 * Source: https://github.com/tme-dev/TME-API
 */

class ApiTme {
	private $token;
	private $appSecret;

	public function __construct($ops){
		$this->mode = !isset($ops['mode']) ? 'file_get_contents' : 'curl';

		$this->token = $ops['token'];
		$this->appSecret = $ops['app_secret'];

		if(isset($ops['country'])) $this->country = $ops['country'];
		if(isset($ops['language'])) $this->language = $ops['language'];
		if(isset($ops['currency'])) $this->currency = $ops['currency'];
	}

	private function curlGetContents($url, $params) {
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_VERBOSE, 1);
		curl_setopt($curl, CURLOPT_HEADER, 1);

		$response = curl_exec($curl);
		$header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		$body = substr($response, $header_size);

		// if ($show_header) {
		// $header = substr($response, 0, $header_size);
		// print_r($header);
		// }

		return $body;
	}

	private function fileGetContents($url, $params) {
		$query = http_build_query($params);
		$ops = [
			'http' =>[
				'method'  => 'POST',
				'header'  => 'Content-type: application/x-www-form-urlencoded',
				'content' => $query,
				'ignore_errors' => true
			],
		];
		return file_get_contents($url, false, stream_context_create($ops));
	}

	public function call($action, array $params){
		$params['Country'] = isset($params['country']) ? $params['country'] : $this->country;
		$params['Language'] = isset($params['language']) ? $params['language'] : $this->language;
		$params['Currency'] = isset($params['currency']) ? $params['currency'] : $this->currency;

		$params['Token'] = $this->token;
		$params['ApiSignature'] = $this->getSignature($action, $params, $this->appSecret);

		if($this->mode === 'file_get_contents'){
			$response = $this->fileGetContents($this->getUrl($action), $params);
		}
		else {
			$response = $this->curlGetContents($this->getUrl($action), $params);
		}

		return json_decode($response, true);
	}

	private function getSignature($action, array $parameters, $appSecret){
		$parameters = $this->sortSignatureParams($parameters);

		$queryString = http_build_query($parameters, null, '&', PHP_QUERY_RFC3986);
		$signatureBase = strtoupper('POST') .
			'&' . rawurlencode($this->getUrl($action)) . '&' . rawurlencode($queryString);

		return base64_encode($this->hash_hmac('sha1', $signatureBase, $appSecret, true));
	}

	private function getUrl($action){
		return 'https://api.tme.eu/' . $action . '.json';
	}

	private function sortSignatureParams(array $params){
		ksort($params);

		foreach ($params as &$value) {
			if (is_array($value)) {
				$value = $this->sortSignatureParams($value);
			}
		}

		return $params;
	}

	private function hash_hmac($algo, $data, $key, $raw_output = false)
	{
		$algo = strtolower($algo);
		$pack = 'H' . strlen($algo('test'));
		$size = 64;
		$opad = str_repeat(chr(0x5C), $size);
		$ipad = str_repeat(chr(0x36), $size);

		if (strlen($key) > $size) {
			$key = str_pad(pack($pack, $algo($key)), $size, chr(0x00));
		} else {
			$key = str_pad($key, $size, chr(0x00));
		}

		for ($i = 0; $i < strlen($key) - 1; $i ++) {
			$opad[$i] = $opad[$i] ^ $key[$i];
			$ipad[$i] = $ipad[$i] ^ $key[$i];
		}

		$output = $algo($opad . pack($pack, $algo($ipad . $data)));

		return ($raw_output) ? pack($pack, $output) : $output;
	}
}