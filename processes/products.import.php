<?php
$configProducts = new FileIni(WORKING_DIR. '/sys_config/config.products.import.ini', true);
$stateProducts = new FileIni(WORKING_DIR. '/sys_state/state.products.import.ini', true);

// Инициализируем импорт
$import = new ImportProducts([
	'log' => $log,
	'config' => $configProducts,
	'state' => $state,
	'api' => $api,
	'tme' => $tme,
//	'umi' => [
//		'cmsController' => cmsController::getInstance(),
//		'hierarchy' => umiHierarchy::getInstance(),
//		'objectsCollection' => umiObjectsCollection::getInstance(),
//		'hierarchyTypesCollection' => umiHierarchyTypesCollection::getInstance(),
//		'permissionsCollection' => permissionsCollection::getInstance(),
//	]
]);
$import->setInputFileName($configProducts->get('path.input_filename'));
$import->setInputDirectory(WORKING_DIR. $configProducts->get('path.input_dir'));
$import->setOutputDirectory(WORKING_DIR. $configProducts->get('path.output_dir'));
$import->setValidColsCount(5);


// Выполним импорт
$start = intval($stateProducts->get('lines.start'));

$perPage = intval($configProducts->get('lines.per_page'));
$perPageAPI = intval($configProducts->get('api.per_page'));

if($start == 0){
	// Минус одна строка, т.к. первая строка это шапка CSV
	$total = $import->getCsvTotalLines() - 1;
	$start = ($start === 0) ? 1 : $start;

	// Файл либо с ошибкой, либо еще не существует
	if($total === false){
		$state->set('process.lock', 0);
		exit("process.products.load.params: Warning - waiting input JSON-file\n");
	}

	// Инициализируем начальное состояние
	$stateProducts->set('lines.total', $total);
	$stateProducts->set('errors.fatal', 0);
	$stateProducts->set('errors.process', 0);
	$stateProducts->set('operations.insert', 0);
	$stateProducts->set('operations.update', 0);
	$stateProducts->set('operations.success', 0);
	$stateProducts->set('operations.non_active', 0);
	$stateProducts->set('date.start', date('Y-m-d H:i:s'));
	$stateProducts->set('date.end', '');

	$log->write('process.products.load.params: START');
}
$total = intval($stateProducts->get('lines.total'));
$last = (($start + $perPage) < $total) ? ($start + $perPage) : $total;

$progressBar = new \ProgressBar\Manager($start, $total);
$log->write("process.products.load.params: current position: {$start}/{$total}");

// Подготовим массив части данных из CSV для быстрого доступа из программы
$import->prepareCsvPart($start, $last);


$api = $configProducts->get('api');
$umi = $configProducts->get('umi');
// Пакетная обработка товаров (в пакете может быть от 1 до 50 позиций)
for($i = $start; $i < $last; $i+=$perPageAPI){
	$bulkStartPos = $i;
	$bulkEndPos = (($i + $perPageAPI) > $last) ? $last : ($i + $perPageAPI);
	$result = $import->processItems($bulkStartPos, $bulkEndPos, $api, $umi);

	$stateProducts->set('operations.insert', ($stateProducts->get('operations.insert') + $result['insert']));
	$stateProducts->set('operations.update', ($stateProducts->get('operations.update') + $result['update']));
	$stateProducts->set('operations.success', ($stateProducts->get('operations.success') + $result['success']));
	$stateProducts->set('operations.non_active', ($stateProducts->get('operations.success') + $result['non_active']));
	$stateProducts->set('errors.process', ($stateProducts->get('errors.process') + $result['errors']));

	// размещение счетчика в этой части позволяет прерывать скрипт
	// без потери точки входа при экстренном перезапуске
	$stateProducts->set('lines.start', (($i + $perPageAPI) < $last) ? ($i + $perPageAPI) : $last);
	$progressBar->update(($i + $perPageAPI));
}
echo "\n";

/** Все операции процесса завершены */
if($stateProducts->get('lines.start') === $stateProducts->get('lines.total') && $stateProducts->get('date.start')){
	$log->write("process.products.load.params: COMPLETED: {$i}/{$total}");

	// Сообщим о завершении процесса
	$state->set('process.state', 'ready');


	// Инициализируем конечное состояние
	$stateProducts->set('date.end', date('Y-m-d H:i:s'));

	// Добавим состояние в общий отчет
	$report->addContent("state.categories.import.ini\n\n". $stateProducts->getContent());

	// Сбросим
	$stateProducts->set('lines.start', 0);
	$stateProducts->set('lines.total', 0);
}