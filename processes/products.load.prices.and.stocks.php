<?php
$configLoadPrices = new FileIni(WORKING_DIR. '/sys_config/config.products.load.prices.and.stocks.ini', true);
$stateLoadPrices = new FileIni(WORKING_DIR. '/sys_state/state.products.load.prices.and.stocks.ini', true);

// Инициализируем импорт
$import = new ProductsLoadPricesAndStocks([
	'log' => $log,
	'config' => $configLoadPrices,
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
$import->setInputFileName($configLoadPrices->get('path.input_filename'));
$import->setInputDirectory(WORKING_DIR. $configLoadPrices->get('path.input_dir'));
$import->setOutputDirectory(WORKING_DIR. $configLoadPrices->get('path.output_dir'));
$import->setValidColsCount(5);


// Выполним импорт
$start = intval($stateLoadPrices->get('lines.start'));

$perPage = intval($configLoadPrices->get('lines.per_page'));
$perPageAPI = intval($configLoadPrices->get('api.per_page'));

if($start == 0){
	// Минус одна строка, т.к. первая строка это шапка CSV
	$total = $import->getCsvTotalLines() - 1;
	$start = ($start === 0) ? 1 : $start;

	// Файл либо с ошибкой, либо еще не существует
	if($total === false){
		$state->set('process.lock', 0);
		exit("products.load.prices.and.stocks: Warning - waiting input JSON-file\n");
	}

	// Инициализируем начальное состояние
	$stateLoadPrices->set('lines.total', $total);
	$stateLoadPrices->set('errors.fatal', 0);
	$stateLoadPrices->set('errors.process', 0);
	$stateLoadPrices->set('operations.insert', 0);
	$stateLoadPrices->set('operations.update', 0);
	$stateLoadPrices->set('operations.success', 0);
	$stateLoadPrices->set('operations.non_active', 0);
	$stateLoadPrices->set('operations.empty_price', 0);
	$stateLoadPrices->set('date.start', date('Y-m-d H:i:s'));
	$stateLoadPrices->set('date.end', '');

	$log->write('products.load.prices.and.stocks: START');
}
$total = intval($stateLoadPrices->get('lines.total'));
$last = (($start + $perPage) < $total) ? ($start + $perPage) : $total;

$progressBar = new \ProgressBar\Manager($start, $total);
$log->write("products.load.prices.and.stocks: current position: {$start}/{$total}");

// Подготовим массив части данных из CSV для быстрого доступа из программы
$import->prepareCsvPart($start, $last);


$api = $configLoadPrices->get('api');
// Пакетная обработка товаров
for($i = $start; $i < $last; $i+=$perPageAPI){
	$bulkStartPos = $i;
	$bulkEndPos = (($i + $perPageAPI) > $last) ? $last : ($i + $perPageAPI);
	$result = $import->processItems($bulkStartPos, $bulkEndPos, $api);

	$stateLoadPrices->set('operations.success', ($stateLoadPrices->get('operations.success') + $result['success']));
	$stateLoadPrices->set('operations.non_active', ($stateLoadPrices->get('operations.non_active') + $result['non_active']));
	$stateLoadPrices->set('operations.empty_price', ($stateLoadPrices->get('operations.empty_price') + $result['empty_price']));
	$stateLoadPrices->set('errors.process', ($stateLoadPrices->get('errors.process') + $result['errors']));

	// размещение счетчика в этой части позволяет прерывать скрипт
	// без потери точки входа при экстренном перезапуске
	$stateLoadPrices->set('lines.start', (($i + $perPageAPI) < $last) ? ($i + $perPageAPI) : $last);
	$progressBar->update(($i + $perPageAPI));
}
echo "\n";

/** Все операции процесса завершены */
if($stateLoadPrices->get('lines.start') === $stateLoadPrices->get('lines.total') && $stateLoadPrices->get('date.start')){
	$log->write("products.load.prices.and.stocks: COMPLETED: {$i}/{$total}");

	// Сообщим о завершении процесса
	$state->set('process.state', 'ready');


	// Инициализируем конечное состояние
	$stateLoadPrices->set('date.end', date('Y-m-d H:i:s'));

	// Добавим состояние в общий отчет
	$report->addContent("state.categories.import.ini\n\n". $stateLoadPrices->getContent());

	// Сбросим
	$stateLoadPrices->set('lines.start', 0);
	$stateLoadPrices->set('lines.total', 0);
}