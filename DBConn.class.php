<?php

class DBConn extends MySqli
{
	public function __construct($dbname = "trucraft")
	{
		parent::__construct('127.0.0.1', 'web_user', 'w3bu53r', $dbname);
	}
	
	public function query($query)
	{
		$result = parent::query($query);
		
		if(strlen($this->error))
			throw new Exception($this->error);
		
		return $result;
	}
}

?>
