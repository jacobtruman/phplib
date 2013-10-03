<?php

class Logger
{
	private $log_dir;

	public function __construct($file) {
		$this->file = $file;
		$file_parts = explode("/", $file);
		if(count($file_parts) > 1) {
			$this->filename = end($file_parts);
			unset($file_parts[count($file_parts) - 1]);
			$this->log_dir = implode("/", $file_parts);
		}
		else
		{
			$this->log_dir = dirname(__FILE__);
		}


		if(!is_dir($this->log_dir)) {
			mkdir($this->log_dir, 0777, true);
		}
	}

	public function addToLog($msg) {
		$msg = date("Y-m-d h:i:s")."\t".$msg.PHP_EOL;
		echo $msg;
		file_put_contents($this->file, $msg, FILE_APPEND);
	}
}

?>