<?php
require_once 'classes/cImport.php';

class ImportCategories extends Import{
	private $categoryTree = [];

	public function prepareCategoryTree(){
		$jsonFilePath = $this->getInputFilePath();

		if(!file_exists($jsonFilePath)){
			throw new Exception("Error: There is no input file <{$jsonFilePath}>");
		}

		$jsonString = file_get_contents($jsonFilePath);
		$json = json_decode($jsonString, true);
		$this->categoryTree = $json['CategoryTree'];

		return $this->categoryTree;
	}

//	public function calcSubTreeCount($tree){
//		$counter = 0;
//		foreach($tree['SubTree'] as $branch){
//			$counter++;
//			if(isset($branch['SubTree']) && is_array($branch['SubTree'])){
//				$counter += $this->calcSubTreeCount($branch);
//			}
//		}
//		return $counter;
//	}

	public function getCategoriesTotalCount(){
		$tree = $this->prepareCategoryTree();
		return count($tree);
	}

	/**
	 * @param $tmeId
	 * @param string $typeName: 'pages' || 'objects'
	 * @return bool
	 */
	private function umiElementByTmeId($tmeId, $typeName = 'pages'){
		$pages = new selector('pages');
		$pages->types('object-type')->name('catalog', 'category');
		$pages->where('tme_id')->equals($tmeId);
		$pages->limit(0, 1);

		$total = $pages->length();
		if(!$total) return false;

		$result = $pages->result();
		return $result[0];
	}

	private function umiIdByTmeId($tmeId, $typeName = 'pages'){
		$pages = new selector('pages');
		$pages->types('object-type')->name('catalog', 'category');
		$pages->where('tme_id')->equals($tmeId);
		$pages->limit(0, 1);

		$total = $pages->length();
		if(!$total) return false;

		$result = $pages->result();
		return $result[0]->getId();
	}

	private function insertItem($data){
		// Найдем родителя элемента по идентификатору родителя элемента в структуре TME
		$parentElementId = $this->umiIdByTmeId($data['tme_parent_id'], 'page');
		if($parentElementId === false){
			$this->log->write('Error: there is no UMI parent for tme_id: '. $data['tme_id']);
			return false;
		}

		// Создаем новый разедл каталога
		$hierarchyTypes = umiHierarchyTypesCollection::getInstance();
		$hierarchyType = $hierarchyTypes->getTypeByName('catalog', 'category');
		$hierarchyTypeId = $hierarchyType->getId();

		// Прикрепляем его к нужному родителю
		$hierarchy = umiHierarchy::getInstance();

		// Создаем новую страницу раздела каталога
		$altName = $hierarchy->convertAltName($data['tme_name']);
		$newElementId = $hierarchy->addElement($parentElementId, $hierarchyTypeId, $data['tme_name'], $altName);
		if($newElementId === false) {
			$this->log->write('Error: unable to create page for tme_id: '. $data['tme_id']);
			return false;
		}

		//Установим права на страницу в состояние "по умолчанию"
		$permissions = permissionsCollection::getInstance();
		$permissions->setDefaultPermissions($newElementId);

		//Получим экземпляр страницы
		$newElement = $hierarchy->getElement($newElementId);

		if($newElement instanceof umiHierarchyElement) {
			// Заполним новую страницу свойствами TME
			$newElement->setValue('tme_id', $data['tme_id']);
			$newElement->setValue('tme_parent_id', $data['tme_parent_id']);
			$newElement->setValue('tme_depth', $data['tme_depth']);
			$newElement->setValue('tme_name', $data['tme_name']);
			$newElement->setValue('tme_thumbnail', $data['tme_thumbnail']);
			$newElement->setValue('tme_total_products', $data['tme_total_products']);
			$newElement->setValue('tme_sub_tree_count', $data['tme_sub_tree_count']);
			$newElement->setValue('tme_md5', $data['tme_md5']);

			// Заполним новую страницу свойствами для UMI
			$newElement->setValue('h1', $data['tme_name']);

			// Картинка для раздела
			if(!empty($data['tme_thumbnail'])){
				$image = $this->downloadImage($data['tme_thumbnail']);

				if($image !== false){
					$newElement->setValue('photo', $image['abs_path']);
				}
				else {
					$this->log->write('Error: unable download photo for tme_id: '. $data['tme_id']);

					// "Испортим" md5, чтобы при следующем проходе попробовать заново закачать изображение
					$newElement->setValue('tme_md5', $newElement->getValue('tme_md5'). '.no_img');
				}
			}

			// Укажем, что страница является активной
			$newElement->setIsActive(true);

//			// Показывать в меню
//			$newElement->setValue('show_submenu', true);
//			$newElement->setValue('is_expanded', true);

			$newElement->setOrd($data['tme_order_num']);

			//Подтвердим внесенные изменения
			$newElement->commit();

			// Успех
			$this->log->write('Success: page created for tme_id: '. $data['tme_id']);
			return true;
		} else {
			// Ошибка создания раздела каталога
			$this->log->write('Error: unable to get page instance for tme_id: '. $data['tme_id']);
			return false;
		}
	}

	private function updateItem($data){
		$pageId = $data['umi_page_id'];

		$hierarchy = umiHierarchy::getInstance();

		$element = $hierarchy->getElement($pageId);
		if(!($element instanceof umiHierarchyElement)){
			$this->log->write('Error: unable edit page for update info for tme_id: '. $data['tme_id']);
			return false;
		}

		// Если контрольная сумма параметров одинковая, то и обновлять нечего
		if($element->getValue('tme_md5') == $data['tme_md5']){
			return true;
		}

		// >>> Обновим параметры страницы (alt_name - не меняем, чтобы не слетали адреса)
		if($element->getValue('tme_depth') != $data['tme_depth']){
			$element->setValue('tme_depth', $data['tme_depth']);
		}

		if($element->getValue('tme_name') != $data['tme_name']){
			$element->setValue('tme_name', $data['tme_name']);
			$element->setValue('h1', $data['tme_name']);
		}

		if($element->getValue('tme_total_products') != $data['tme_total_products']){
			$element->setValue('tme_total_products', $data['tme_total_products']);
		}

		if($element->getValue('tme_sub_tree_count') != $data['tme_sub_tree_count']){
			$element->setValue('tme_sub_tree_count', $data['tme_sub_tree_count']);
		}

		if($element->getValue('tme_md5') != $data['tme_md5']){
			$element->setValue('tme_md5', $data['tme_md5']);
		}


		// Обновим изображение
		if($element->getValue('tme_thumbnail') != $data['tme_thumbnail']){
			$element->setValue('tme_thumbnail', $data['tme_thumbnail']);

			if(!empty($data['tme_thumbnail'])){
				$image = $this->downloadImage($data['tme_thumbnail']);

				if($image !== false){
					$element->setValue('photo', $image['abs_path']);
				}
				else {
					// "Испортим" md5, чтобы при следующем проходе попробовать заново закачать изображение
					$element->setValue('tme_md5', $element->getValue('tme_md5'). '.no_img');
					$this->log->write('Error: unable download photo for tme_id: '. $data['tme_id']);
				}
			}
		}

		// Если ранее изобаржение по какой-то причине не было установлено
		$photo = $element->getValue('photo');
		if($data['tme_thumbnail'] && empty($photo) && $image){
			$element->setValue('photo', $image['abs_path']);
		}

		if($element->getValue('tme_parent_id') != $data['tme_parent_id']){
			// Изменился родительский элемент
			$element->setValue('tme_parent_id', $data['tme_parent_id']);

			// Совершим перемещение
			$parentElementId = $this->umiIdByTmeId($data['tme_parent_id'], 'page');
			if($parentElementId === false){
				$this->log->write('Error: unable update parent for tme_id: '. $data['tme_id']);
			}

			$hierarchy->moveBefore($pageId, $parentElementId);
		}

		$element->commit();

		$this->log->write('Success: data has been updated for tme_id: '. $data['tme_id']);
		return true;
	}

	/**
	 * Создает виртуальную копию уже существуюшего раздела (добавляет ссылку в иерархии с привязкой к другому родителю)
	 */
	private function createVirtualCopy($data){
		$hierarchy = umiHierarchy::getInstance();
		$parentId = $this->umiIdByTmeId($data['tme_parent_id'], 'pages');
		$newElement = $hierarchy->copyElement($data['umi_page_id'], $parentId, false);

		if($newElement === false){
			$this->log->write('Error: unable create virtual copy for tme_id: '. $data['tme_id']);
			return false;
		}

		$this->log->write('Success: virtual copy has been created for tme_id: '. $data['tme_id']);
		return true;
	}

	/**
	 * Обновляем номер сессии для каждой позиции, чтобы иметь возможность узнать не тронутые позиции
	 * в процессах пост-обработки (удаления не нужных позиций из БД)
	 * @param $data
	 * @return bool
	 */
	private function updateSessionId($data){
		$pageId = isset($data['umi_page_id']) ? $data['umi_page_id'] : $this->umiIdByTmeId($data['tme_id'], 'pages');

		if(!$pageId){
			return false;
		}

		$hierarchy = umiHierarchy::getInstance();
		$element = $hierarchy->getElement($pageId);
		if(!($element instanceof umiHierarchyElement)){
			$this->log->write('Error: unable update session id for tme_id: '. $data['tme_id']);
			return false;
		}

		$sessionId = $this->state->get('processes.session_id');
		$element->setValue('import_session_id', $sessionId);
		$element->commit();
	}



	/** Основной алгоритм добаления групп */
	public function processItem($pos){
		$noErrors = true;
		$result = false;

		$item = $this->categoryTree[$pos];
		$data = [
			'tme_id' => $item['Id'],
			'tme_parent_id' => $item['ParentId'],
			'tme_depth' => $item['Depth'],
			'tme_name' => $item['Name'],
			'tme_thumbnail' => $item['Thumbnail'],
			'tme_total_products' => $item['TotalProducts'],
			'tme_sub_tree_count' => $item['SubTreeCount'],

			'tme_is_virtual_copy' => isset($item['IsVirtualCopy']) ? !!$item['IsVirtualCopy'] : false,
			'tme_order_num' => $item['OrderNum'],

			'tme_md5' => md5($item['ParentId']. '-'. $item['Depth']. '-'. $item['Name']. '-'. $item['Thumbnail']. '-'. $item['TotalProducts'])
		];

		// Определим идентификатор категории в UMI, и на основании его наличия примем решение о дальнейшем действии
		$pageId = $this->umiIdByTmeId($data['tme_id'], 'pages');
		if($pageId === false){
			/** INSERT */
			if(!$this->insertItem($data)){
				$noErrors = false;
				$this->state->set('errors.process', ($this->state->get('errors.process') + 1));
			}
			else {
				$result = 'inserted';
			}
		}
		else {
			$data['umi_page_id'] = $pageId;

			/** VIRTUAL COPY */
			if(!!$data['tme_is_virtual_copy'] === true){
				if(!$this->createVirtualCopy($data)){
					$noErrors = false;
					$this->state->set('errors.process', ($this->state->get('errors.process') + 1));
				}
				else {
					$result = 'virtual_copied';
				}
			}

			/** UPDATE */
			else {
				if(!$this->updateItem($data)){
					$noErrors = false;
					$this->state->set('errors.process', ($this->state->get('errors.process') + 1));
				}
				else {
					$result = 'updated';
				}
			}

		}

		/** SESSION */
		// Обновим на всех обработанных позициях уникальный идентификатор сессии текущей последовательности процессов
		if($noErrors === true){
			$this->updateSessionId($data);
		}

		return $result;
	}


}