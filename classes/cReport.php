<?php

class Report {
	private $fh;
	private $path;

	public function __construct($path){
		$this->path = $path. '/'. date("Ymd"). '.txt';
	}

	public function clean(){
		$fh = fopen($this->path, 'w');
		fclose($fh);
	}

	public function addContent($data){
		$this->fh = fopen($this->path, "a");
		fwrite($this->fh, $data. "\n*****************************************\n");
		fclose($this->fh);
	}

	public function getContent(){
		return file_get_contents($this->path);
	}
}