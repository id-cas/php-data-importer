<?php
/**
 * cLogger - осуществляет взаимодействие с файлом лога
 */

class Logger{
    /**
     * Осуществляет запись
     * @path путь к директории логирования
     * Возвращает: true - если класс успешно создан для выбранной директории,
     * false - если произошли какие-то ошибки
     */
    private $log_file;

    // Создает класс
	public function __construct($path){
        $this->log_file = fopen($path. '/'. date("Ymd"). '.log', "a");
    }

	private function line($msg){
		return date("Y.m.d H:i:s"). " {$msg}\n";
	}

    // Делает запись в файл лога
    public function write($msg){

        fwrite($this->log_file, $this->line($msg));
    }

    // Делает запись в файл лога, отправляет сообщение и завершает работу скрипта
    public function fatal($msg){
		$msg = 'FATAL '. $msg;
		$this->write($msg);
        exit($this->line($msg));
    }

    public function __destruct() {
        fclose($this->log_file);
    }
}

