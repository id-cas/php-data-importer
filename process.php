<?php
ini_set('memory_limit', '2048M');

require_once '../../standalone.php';
require_once 'classes/cFileIni.php';
require_once 'classes/cUtils.php';
require_once 'classes/cLogger.php';
require_once 'classes/cReport.php';
// Новое расположение \classes\components\tme\class.php
// Прописываем запуск класса здесь \classes\system\autoload\autoload.custom.php
// require_once 'classes/cApiTme.php';
require_once 'classes/cMail.php';
require_once 'classes/cTree2Flat.php';
require_once 'classes/cImportProducts.php';
require_once 'classes/cImportCategories.php';
require_once 'classes/cProductsLoadParams.php';
require_once 'classes/cProductsLoadPricesAndStocks.php';

require_once 'classes/ProgressBar/Manager.php';
require_once 'classes/ProgressBar/Registry.php';

/** Инициализация */
if (!defined('WORKING_DIR')) {
	define('WORKING_DIR', dirname(__FILE__));
}

$state = new FileIni(WORKING_DIR. '/sys_state/state.ini', true);
$config = new FileIni(WORKING_DIR. '/sys_config/config.ini', true);
$log = new Logger(WORKING_DIR. '/log');
$report = new Report(WORKING_DIR. '/report');
$utils = new Utils();

// >>> UMI custom classes
$tme = new Tme();
$api = $tme->getApi([
	'token' => $config->get('api.token'),
	'app_secret' => $config->get('api.app_secret'),
	'country' => $config->get('api.country'),
	'language' => $config->get('api.language'),
	'currency' => $config->get('api.currency'),
]);
// <<< UMI custom classes


/** Проверка возможности запуска очереди процессов (отслеживание начала импорта каталога) */
$processStartTime = $config->get('processes.start_time');		// В какое время запускать последовательность
$processStartPeriod = $config->get('processes.start_period'); 	// С какой периодичностью запускать последовательность процессов
$processStartPeriodTimeStamp = empty($processStartPeriod) ? 24 * 60 * 60 : $utils->stringToSeconds($processStartPeriod);
$processesLastTime = $state->get('processes.last_time');
if(!empty($processesLastTime)){
	$startDate = date('Y/m/d'). ' '. $processStartTime;

	$processStartTimeStamp = strtotime($startDate) + $processStartPeriodTimeStamp;
	$processesLastTimeStamp = strtotime($processesLastTime);

	$curTimeStamp = time();
	if(!($processStartTimeStamp < $curTimeStamp && $processStartTimeStamp > $processesLastTimeStamp)){
		exit("Warning: waiting valid start date time: <{$startDate}> +1 day");
	}
}

// Блокировка процесса (если был запущен другим инстансом крона)
$processLocked = !!$state->get('process.lock');
if($processLocked === true){
	// Приложение уже запущено, прервем обработку
	exit("Warning: app already started\n");
}

/** Запустим обработку */
// Блокируем доступ к процессу
$state->set('process.lock', 1);

// Определим текущий актуальный процесс из списка последовательно исполняемых процессов
$processName = $state->get('process.name');
$processState = $state->get('process.state');
$processSequence = $config->get('processes.sequence');

if(empty($processSequence)){
	$state->set('errors.fatal', ($state->get('errors.fatal') + 1));
	$state->set('process.lock', 0);
	$log->fatal('There is no actual process in <config.ini> process list.');
}

if($processState !== 'busy'){
	if(empty($processName)){
		// Первый запуск процесса
		$process =  array_shift($processSequence);

		// Обнулим счетчики ошибок
		$state->set('errors.fatal', 0);
		$state->set('errors.process', 0);

		// Установим новый уникальный идентификатор сессии
		// последовательности процессов
		$state->set('processes.session_id', '#'. date('Ymd-His'));

		// Очистим файл отчета
		$report->clean();
	}
	else {
		// Любое последующее переключение на новый процесс из последовательности.
		// Актуально и для последнего запуска, когда в очереди нет никаких процессов.
		foreach($processSequence as $process){
			$process = array_shift($processSequence);
			if($process === $processName){
				$process =  array_shift($processSequence);
				break;
			}
		}

		// Т.к. произошла замена процесса, запишем в отчет последнее состояние state.ini
		// $report->write(file_get_contents(WORKING_DIR. '/sys_state/state.ini'));
		// $report->write("\n*****************************************\n");
	}
	$processName = !empty($process) ? $process : '';
	$state->set('process.name', $processName);
}

try {
	/** Процессы последовательного исполнения */
	// Будем инклудить составные файлы, чтобы не перегружать представление
	// основного цикла работы процесса. Все переменные и передаются из текущего,
	// родительского скрипта
	if(!empty($processName)){
		$state->set('process.state', 'busy');
		include_once "$processName";
	}
}
catch (Exception $e){
	// Прервать исполнение и разблокировать процесс для последующей попытки запуска.
	// Нельзя запускать последующий процесс пока не завершится текущий.
	$state->set('process.lock', 0);
	$state->set('errors.fatal', ($state->get('errors.fatal') + 1));
	$log->fatal($e->getMessage());
}


// Разблокируем доступ к приложению
$state->set('process.lock', 0);


/** Завершение */
// Если обработка полностью завершилась === нет актуальных процессов
if(empty($processName)){
	// Деактуализируем текущие процессы в состоянии
	$state->set('process.name', '');

	// Обнулим счетчики ошибок
	$state->set('errors.fatal', 0);
	$state->set('errors.process', 0);

	// Время завершения последовательности процессов
	$state->set('processes.last_time', date('Y/m/d H:i:s'));

	// Отправим уведомление о полном завершении процессов импорта
	$mail = new Mail([
		'to'		=> $config->get('mail.to'),
		'from'		=> $config->get('mail.from'),
		'reply'		=> $config->get('mail.reply'),
		'subject'	=> $config->get('mail.subject')
	]);
	$mail->send($report->getContent());
}
