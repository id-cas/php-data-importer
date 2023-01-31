<?php
require_once 'classes/cImport.php';

class ProductsLoadPricesAndStocks extends Import{

	/*** BULK MODE ***/
	/** Основной алгоритм пакетного добаления групп */
	public function processItems($posStart, $posLast, $api){
		$result = [
			'errors' => 0,

			'success' => 0,
			'non_active' => 0,
			'empty_price' => 0,
		];

		$symbolList = [];
		for($pos = $posStart; $pos < $posLast; $pos++){
			$row = $this->getCsvRow($pos);

			// Валидность строки с данными
			if(!$this->validateColsCount($row)){
				$this->log->write('Error: invalid columns count in row <'. $pos. '>');
				$result['errors']++;
				continue;
			}

			// Получим данные о товаре
			$item = [
				'symbol' => $row[0],
				'producer' => $row[1],
				'category_id' => $row[2],
				'is_active' => $row[3],
				'product_status' => $row[4],
			];

			// Валидность кода товара
			if(empty($item['symbol'])){
				$this->log->write('Error: invalid <symbol> count in row <'. $pos. '>');
				$result['errors']++;
				continue;
			}

			// Проверка активности товара
			if(mb_strtolower($item['is_active']) !== 'true'){
				$result['non_active']++;
				continue;
			}

			$symbolList[] = $item['symbol'];
		}

		if(!count($symbolList)){
			$this->log->write('Error: no valid symbols per pack for items from <'. $posStart. ' to '. $posLast. '>');
			$result['errors']++;
			return $result;
		}



		/** Если дошли сюда, то значит данные из CSV удалось получить и мы "насобирали" пакет на запрос */

		/** ************************************* **/
		/** Цены + Склады                         **/
		/** ************************************* **/
		$res = $this->bulkApi('Products/GetPricesAndStocks', ['SymbolList' => $symbolList], $api, $posStart, $posLast, $result);
		if($res === false) return $result;

		foreach($res['Data']['ProductList'] as $data){
			$symbol = $data['Symbol'];
			$priceList = $data['PriceList'];
			$stock = [
				'amount' => $data['Amount'],
				'unit' => $data['Unit'],
			];

			// Находим ID продукта в БД из расчета, что он был занесен на этапе импорта товаров в UMI
			$productId = $this->tme->product->getProduct($symbol, true);
			if($productId === false){
				continue;
			}

			/** Добавим/обвновим/актуализируем список цен */
			$resPriceList = $this->tme->prices->update($productId, $priceList);
			$resStocks = $this->tme->stocks->update($productId, $stock);

			// Узнаем о результате добавления/обновления товарной позиции
			if($resPriceList === null){
				$this->log->write('Warning: empty price list for <'. $symbol. '>');
				$result['empty_price']++;
			}
			if($resPriceList === false){
				$this->log->write('Error: Unable to insert/update <'. $symbol. '> in tme_product_prices table');
				$result['errors']++;
			}
			if($resStocks === false){
				$this->log->write('Error: Unable to insert/update <'. $symbol. '> in tme_product_stocks table');
				$result['errors']++;
			}
			if(!($resPriceList === false && $resStocks === false)){
				$result['success']++;
			}

		}

		return $result;
	}

}