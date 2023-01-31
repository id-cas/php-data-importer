<?php
$configDown = new FileIni(WORKING_DIR. '/sys_config/config.categories.download.ini', true);
$stateDown = new FileIni(WORKING_DIR. '/sys_state/state.categories.download.ini', true);

$log->write('process.categories.download: START');

// Инициализируем начальное состояние
$stateDown->set('operations.downloaded', 0);
$stateDown->set('errors.fatal', 0);
$stateDown->set('errors.process', 0);
$stateDown->set('date.start', date('Y-m-d H:i:s'));
$stateDown->set('date.end', '');


//// Получить иерархию каталога через API TME (плоская структура в порядке загрузки корень -> элемент -> дочерний элемент
//$res = $api->call('Products/GetCategories', ['Tree' => false]);

// Получить иерархию каталога через API TME с учетом иерархии
$res = $api->call('Products/GetCategories', ['Tree' => true]);

if(strtoupper($res['Status']) !== 'OK'){
	$state->set('errors.fatal', ($state->get('errors.fatal') + 1));
	$stateDown->set('errors.fatal', ($stateDown->get('errors.fatal') + 1));

	$state->set('process.lock', 0);
	$log->fatal('process.categories.download: Response <Status> is not <OK>');
}

// Директории файла
$inputPath = WORKING_DIR. $configDown->get('path.input_dir');
$inputFileName = $configDown->get('path.input_filename');
$inputFilePath = $inputPath. '/'. $inputFileName;

// Создадим директорию для файла, если ее нет
if(!is_dir($inputPath)){
	mkdir($inputPath, 0750, true);
}

// Закачивание файла
$log->write("process.categories.download: downloading <{$inputFileName}> ...");
file_put_contents($inputFilePath, json_encode($res['Data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$fileSize = filesize($inputFilePath);
$fileSizeKb = round($fileSize / 1024, 2);
$log->write("process.categories.download: downloaded file size {$fileSizeKb}Kb");

// Сообщим о завершении процесса
$log->write('process.categories.download: COMPLETED');
$state->set('process.state', 'ready');
$state->set('process.lock', 0);

// Результат исполнения
$stateDown->set('operations.downloaded', 1);
$stateDown->set('date.end', date('Y-m-d H:i:s'));

// Добавим состояние в общий отчет
$report->addContent("state.categories.download.ini\n\n". $stateDown->getContent());
