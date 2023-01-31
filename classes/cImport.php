<?php

class Import {
	public $log;
	public $config;
	public $state;

	public $api;
	public $tme;

	private $inputFileName = 'tme.csv';
	private $inputDir;
	private $outputDir;

	private $csvPart = [];
	private $validColsCount = 0;

	public function __construct($ops) {
		$this->log = $ops['log'];
		$this->api = $ops['api'];
		$this->tme = $ops['tme'];
		$this->config = $ops['config'];
		$this->state = $ops['state'];

		// Установим путь для сохранения изображений
		$this->tme->products->setDownloadImageDir(WORKING_DIR. $this->config->get('path.images_dir'), $this->config->get('path.images_dir_chmod'));
	}

	public function getLog(){
		return $this->log;
	}

	public function getApi(){
		return $this->api;
	}

	/**
	 * Подготавливает 2D массив
	 *
	 * @param $start_pos
	 * @param $end_pos
	 */
	public function prepareCsvPart($start_pos, $end_pos){
		$f = fopen($this->getInputFilePath(), 'r');

		for($i = 0; ($line = fgetcsv($f, 1024, ';')) && $i < $end_pos; $i++) {
			if($i >= $start_pos && $i < $end_pos) {
				$this->csvPart[$i] = $line;
			}
		}

		fclose($f);
		return $this->csvPart;

	}

	public function getCsvRow($rowNum){
		return $this->csvPart[$rowNum];
	}

	/** Получить количество строк в CSV-файле */
	public function getCsvTotalLines(){
		$csvFilePath = $this->getInputFilePath();

		if(!file_exists($csvFilePath)){
			return false;
		}

		$fp = new SplFileObject($csvFilePath, 'r');
		$fp->seek(PHP_INT_MAX);
		$lineCount =  $fp->key() + 1;
		$fp->rewind();

		return $lineCount;
	}

	/** Устанавливает валидное число столбцов для текущего загружаемого CSV-файла */
	public function setValidColsCount($cnt){
		$this->validColsCount = $cnt;
	}

	/** Проверка валидного кол-ва столбцов в считанной строке из CSV */
	public function validateColsCount($row){
		return ($this->validColsCount === count($row));
	}

	/**
	 * Устновим файл который будет обработан в процессе импорта данных
	 * @param $fileName
	 */
	public function setInputFileName($inputFileName){
		$this->inputFileName = $inputFileName;
	}

//	public function getinputFileName(){
//		return $this->inputFileName;
//	}

	public function getInputFilePath(){
		return $this->inputDir. '/'. $this->inputFileName;
	}

	/**
	 * Устнавливает входящую диреткорию откуда берется файл выгрузки
	 * @param $inputDir
	 */
	public function setInputDirectory($inputDir){
		$this->inputDir = $inputDir;
	}

	/**
	 * Устанавливает директори куда будет перемещен файл
	 * @param $outputDir
	 */
	public function setOutputDirectory($outputDir){
		$this->outputDir = $outputDir;
	}

	/**
	 * Перемещаяет файл с данными для импорта в директорию отработанных файлов, и архивирует его
	 */
	public function moveZipCsvFile(){
		$inputFilePath = $this->inputDir. '/'. $this->inputFileName;
		$outputFilePath = $this->outputDir. '/'. $this->inputFileName;
		$zipFilePath = $outputFilePath. '.'.  date('Ymd-His', time()). '.zip';

		// Проверим директории
		if(!file_exists($inputFilePath)){
			throw new Exception("Error: There is no input file <{$inputFilePath}>");
		}

		if(!is_dir($this->outputDir)){
			mkdir($this->outputDir, 0750, true);
		}

		// Создаем архивную копию файла в выходной директории
		$zip = new ZipArchive();
		if ($zip->open($zipFilePath, ZipArchive::CREATE)!==TRUE) {
			throw new Exception("Error: Unable to create ZIP-file <{$zipFilePath}>");
		}

		$zip->addFile($inputFilePath, $this->inputFileName);
		$zip->close();

		// Удалим исходный файл
		unlink($inputFilePath);
	}

	public function downloadImage($cdnImagePath){
		return $this->tme->products->downloadImage($cdnImagePath);
	}

	/**
	 * Запрос данных по API пакетом
	 */
	public function bulkApi($command, $params, $api, &$posStart, &$posLast, &$result){
		// Настройки соединения
		$apiRepeatReqCnt = isset($api['req_attempts']) ? $api['req_attempts'] : 1;
		$apiTimeout = isset($api['req_timeout']) ? $api['req_timeout'] : 0;

		// Запросим по API характеристики товара
		$res = [];
		$reqValidAttemptCnt = 0;
		// Повторяем пока не получим валидный ответ, не превысим число попыток
		while((!isset($res['Status']) || mb_strtoupper($res['Status']) !== 'OK') && $reqValidAttemptCnt < $apiRepeatReqCnt){
			$res = $this->getApi()->call($command, $params);

			$reqValidAttemptCnt++;

			if(mb_strtoupper($res['Status']) !== 'OK'){
				$this->log->write('Error: Get API data attempt <'. $reqValidAttemptCnt. ' from '. $apiRepeatReqCnt. '>');
			}

			if($apiTimeout !== 0){
				sleep($apiTimeout);
			}
		}


		if(mb_strtoupper($res['Status']) !== 'OK'){
			$this->log->write('Error: TME API return status NON OK for bulk for items from <'. $posStart. ' to '. $posLast. '>');
			$result['errors']++;
			return false;
		}

		return $res;
	}
}