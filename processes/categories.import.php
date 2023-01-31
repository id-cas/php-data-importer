<?php
$configCat = new FileIni(WORKING_DIR. '/sys_config/config.categories.import.ini', true);
$stateCat = new FileIni(WORKING_DIR. '/sys_state/state.categories.import.ini', true);

// Инициализируем импорт
$import = new ImportCategories([
	'log' => $log,
	'config' => $configCat,
	'state' => $state,
	'api' => $api,
//	'umi' => [
//		'cmsController' => cmsController::getInstance(),
//		'hierarchy' => umiHierarchy::getInstance(),
//		'objectsCollection' => umiObjectsCollection::getInstance(),
//		'hierarchyTypesCollection' => umiHierarchyTypesCollection::getInstance(),
//		'permissionsCollection' => permissionsCollection::getInstance(),
//	]
]);
$import->setInputFileName($configCat->get('path.input_filename'));
$import->setInputDirectory(WORKING_DIR. $configCat->get('path.input_dir'));
$import->setOutputDirectory(WORKING_DIR. $configCat->get('path.output_dir'));


// Выполним импорт
$start = intval($stateCat->get('lines.start'));
$per_page = intval($configCat->get('lines.per_page'));

if($start == 0){
	$total = $import->getCategoriesTotalCount();

	// Файл либо с ошибкой, либо еще не существует
	if($total === false){
		$state->set('process.lock', 0);
		exit("process.categories.import: Warning - waiting input JSON-file\n");
	}

	// Инициализируем начальное состояние
	$stateCat->set('lines.total', $total);
	$stateCat->set('errors.fatal', 0);
	$stateCat->set('errors.process', 0);
	$stateCat->set('operations.insert', 0);
	$stateCat->set('operations.update', 0);
	$stateCat->set('operations.virtual', 0);
	$stateCat->set('date.start', date('Y-m-d H:i:s'));
	$stateCat->set('date.end', '');

	$log->write('process.categories.import: START');
}
$total = intval($stateCat->get('lines.total'));
$last = (($start + $per_page) < $total) ? ($start + $per_page) : $total;

$progressBar = new \ProgressBar\Manager($start, $total);
$log->write("process.categories.import: current position: {$start}/{$total}");

$import->prepareCategoryTree();
for($i = $start; $i < $last; $i++){
	$result = $import->processItem($i);

	if($result === false){
		$stateCat->set('errors.process', ($stateCat->get('errors.process') + 1));
	}
	elseif($result === 'inserted'){
		$stateCat->set('operations.insert', ($stateCat->get('operations.insert') + 1));
	}
	elseif($result === 'updated'){
		$stateCat->set('operations.update', ($stateCat->get('operations.update') + 1));
	}
	elseif($result === 'virtual_copied'){
		$stateCat->set('operations.virtual', ($stateCat->get('operations.virtual') + 1));
	}

	// размещение счетчика в этой части позволяет прерывать скрипт
	// без потери точки входа при экстренном перезапуске
	$stateCat->set('lines.start', ($i + 1));
	$progressBar->update(($i + 1));
}
echo "\n";

/** Все операции процесса завершены */
if($stateCat->get('lines.start') === $stateCat->get('lines.total') && $stateCat->get('date.start')){
	$log->write("process.categories.import: COMPLETED: {$i}/{$total}");

	// Перенесем в архив отработанный файл
	$log->write("process.categories.import: ZIP + MOVE");
	$import->moveZipCsvFile();

	// Сообщим о завершении процесса
	$state->set('process.state', 'ready');


	// Инициализируем конечное состояние
	$stateCat->set('date.end', date('Y-m-d H:i:s'));

	// Добавим состояние в общий отчет
	$report->addContent("state.categories.import.ini\n\n". $stateCat->getContent());

	// Сбросим
	$stateCat->set('lines.start', 0);
	$stateCat->set('lines.total', 0);
}