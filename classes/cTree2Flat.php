<?php

/**
 * Class Tree2Flat
 *
 * Сначала ветки первого уровня, затем второй уровень и т.д.
 */
class Tree2Flat {
	public function __construct($tree) {
		$this->tree = $tree;

		$this->tmp = [];
		$this->flat = [];

		$this->errors = 0;
	}


	private function subTreeProcess($tree){
		$this->resetErrorsCount();

		foreach($tree['SubTree'] as $branch){
			$depth = intval($branch['Depth']);

			if(!isset($this->flat[$depth])){
				$this->flat[$depth] = [];
			}

			$this->flat[$depth][] = [
				'TotalProducts' => $branch['TotalProducts'],
				'Id' => $branch['Id'],
				'Depth' => $branch['Depth'],
				'ParentId' => $branch['ParentId'],
				'Thumbnail' => $branch['Thumbnail'],
				'Name' => $branch['Name'],
				'SubTreeCount' => $branch['SubTreeCount'],
			];

			if(isset($branch['SubTree']) && is_array($branch['SubTree'])){
				$this->subTreeProcess($branch);
			}
		}
	}


	public function convert(){
		$this->subTreeProcess($this->tree);
		ksort($this->flat);

		// Разложим все в плоскую последовательность: сначала Depth: 1, 2, ... N
		$branchIdList = [];

		$tmp = $this->flat;
		$this->flat = [];
		foreach($tmp as $tree){
			$ord = 0;

			foreach($tree as $branch){

				if(array_key_exists($branch['Id'], $branchIdList)){
					// Такой раздел уже существует

					if(!in_array($branch['ParentId'], $branchIdList[$branch['Id']])){
						// Разные родители, для одного и того же Id, значит - это виртуальная копия
						$branch['IsVirtualCopy'] = true;
					}
					else {
						// Какой-то дубль в структуре
						$this->errors++;
						continue;
					}
				}

				// Для каждой позиции копим массив доступных разделов-родителей, где они могут находиться
				$branchIdList[$branch['Id']][] = $branch['ParentId'];

				$branch['OrderNum'] = $ord;
				$this->flat[] = $branch;

				$ord++;
			}
		}

		return ['CategoryTree' => $this->flat];
	}

	public function resetErrorsCount(){
		$this->errors = 0;
	}

	public function getErrorsCount(){
		return $this->errors;
	}
}

