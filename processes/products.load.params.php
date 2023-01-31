<?php
$configLoadParams = new FileIni(WORKING_DIR. '/sys_config/config.products.load.params.ini', true);
$stateLoadParams = new FileIni(WORKING_DIR. '/sys_state/state.products.load.params.ini', true);

// Инициализируем импорт
$import = new ProductsLoadParams([
	'log' => $log,
	'config' => $configLoadParams,
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
$import->setInputFileName($configLoadParams->get('path.input_filename'));
$import->setInputDirectory(WORKING_DIR. $configLoadParams->get('path.input_dir'));
$import->setOutputDirectory(WORKING_DIR. $configLoadParams->get('path.output_dir'));
$import->setValidColsCount(5);


// Выполним импорт
$start = intval($stateLoadParams->get('lines.start'));

$perPage = intval($configLoadParams->get('lines.per_page'));
$perPageAPI = intval($configLoadParams->get('api.per_page'));

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
	$stateLoadParams->set('lines.total', $total);
	$stateLoadParams->set('errors.fatal', 0);
	$stateLoadParams->set('errors.process', 0);
	$stateLoadParams->set('operations.insert', 0);
	$stateLoadParams->set('operations.update', 0);
	$stateLoadParams->set('operations.success', 0);
	$stateLoadParams->set('operations.non_active', 0);
	$stateLoadParams->set('date.start', date('Y-m-d H:i:s'));
	$stateLoadParams->set('date.end', '');

	$log->write('process.products.load.params: START');
}
$total = intval($stateLoadParams->get('lines.total'));
$last = (($start + $perPage) < $total) ? ($start + $perPage) : $total;

$progressBar = new \ProgressBar\Manager($start, $total);
$log->write("process.products.load.params: current position: {$start}/{$total}");

// Подготовим массив части данных из CSV для быстрого доступа из программы
$import->prepareCsvPart($start, $last);


$api = $configLoadParams->get('api');
// Пакетная обработка товаров
for($i = $start; $i < $last; $i+=$perPageAPI){
	$bulkStartPos = $i;
	$bulkEndPos = (($i + $perPageAPI) > $last) ? $last : ($i + $perPageAPI);
	$result = $import->processItems($bulkStartPos, $bulkEndPos, $api);

	$stateLoadParams->set('operations.success', ($stateLoadParams->get('operations.success') + $result['success']));
	$stateLoadParams->set('operations.non_active', ($stateLoadParams->get('operations.success') + $result['non_active']));
	$stateLoadParams->set('errors.process', ($stateLoadParams->get('errors.process') + $result['errors']));

	// размещение счетчика в этой части позволяет прерывать скрипт
	// без потери точки входа при экстренном перезапуске
	$stateLoadParams->set('lines.start', (($i + $perPageAPI) < $last) ? ($i + $perPageAPI) : $last);
	$progressBar->update(($i + $perPageAPI));
}
echo "\n";

/** Все операции процесса завершены */
if($stateLoadParams->get('lines.start') === $stateLoadParams->get('lines.total') && $stateLoadParams->get('date.start')){
	$log->write("process.products.load.params: COMPLETED: {$i}/{$total}");

	// Сообщим о завершении процесса
	$state->set('process.state', 'ready');


	// Инициализируем конечное состояние
	$stateLoadParams->set('date.end', date('Y-m-d H:i:s'));

	// Добавим состояние в общий отчет
	$report->addContent("state.categories.import.ini\n\n". $stateLoadParams->getContent());

	// Сбросим
	$stateLoadParams->set('lines.start', 0);
	$stateLoadParams->set('lines.total', 0);
}