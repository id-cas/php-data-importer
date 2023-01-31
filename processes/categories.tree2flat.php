<?php
/**
 * Плоский варианиант от API TME не имеет правильной последовательности для загрузки древа каталога,
 * поэтому мы самостоятельно конвертируем древо в плоский аналог.
 *
 */

$configTree = new FileIni(WORKING_DIR. '/sys_config/config.categories.tree2flat.ini', true);
$stateTree = new FileIni(WORKING_DIR. '/sys_state/state.categories.tree2flat.ini', true);

$log->write('process.categories.tree2flat: START');

// Инициализируем начальное состояние
$stateTree->set('operations.downloaded', 0);
$stateTree->set('errors.fatal', 0);
$stateTree->set('errors.process', 0);
$stateTree->set('date.start', date('Y-m-d H:i:s'));
$stateTree->set('date.end', '');

// Директории файла
$inputPath = WORKING_DIR. $configTree->get('path.input_dir');
$inputFileName = $configTree->get('path.input_filename');
$inputFilePath = $inputPath. '/'. $inputFileName;

$outputPath = WORKING_DIR. $configTree->get('path.output_dir');
$outputFileName = $configTree->get('path.output_filename');
$outputFilePath = $outputPath. '/'. $outputFileName;

$zipPath = WORKING_DIR. $configTree->get('path.zip_dir');


// Прочитаем дерево каталога
if(!file_exists($inputFilePath)){
	throw new Exception("Error: There is no input file <{$jsonFilePath}>");
}
$jsonString = file_get_contents($inputFilePath);
$json = json_decode($jsonString, true);
$tree = $json['CategoryTree'];
$log->write('process.categories.tree2flat: Tree was read from file.');

// Конвертируем структуру каталога в плоскую
$flatTree = new Tree2Flat($tree);
$flatStructure = $flatTree->convert();
$log->write('process.categories.tree2flat: Tree was converted to flat structure.');

// Если возникли ошибки: дубли в структуре каталога
$flatConversionErrorsCount = $flatTree->getErrorsCount();
if($flatConversionErrorsCount){
	$stateTree->set('errors.process', $flatConversionErrorsCount);
}

// Создадим директорию для файла, если ее нет
if(!is_dir($outputPath)){
	mkdir($outputPath, 0750, true);
}

// Сохранение "плоской" структуры дерева
file_put_contents($outputFilePath, json_encode($flatStructure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$log->write('process.categories.tree2flat: Flat file was saved.');

// Переместим исходный файл с деревом в архив
if(!is_dir($zipPath)){
	mkdir($zipPath, 0750, true);
}


$inputFilePath = $inputPath. '/'. $inputFileName;
$zipFilePath = $zipPath. '/'. $inputFileName. '.'.  date('Ymd-His', time()). '.zip';
$zip = new ZipArchive();
if ($zip->open($zipFilePath, ZipArchive::CREATE)!==TRUE) {
	throw new Exception("Error: Unable to create ZIP-file <{$zipFilePath}>");
}
$zip->addFile($inputFilePath, $inputFileName);
$zip->close();
$log->write('process.categories.tree2flat: Flat file was added to ZIP.');

// Удалим исходный файл
unlink($inputFilePath);

// Сообщим о завершении процесса
$log->write('process.categories.tree2flat: COMPLETED');
$state->set('process.state', 'ready');
$state->set('process.lock', 0);


// Результат исполнения
$stateTree->set('operations.converted', count($flatStructure['CategoryTree']));
$stateTree->set('date.end', date('Y-m-d H:i:s'));

// Добавим состояние в общий отчет
$report->addContent("state.categories.tree2flat.ini\n\n". $stateTree->getContent());
