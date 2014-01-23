<?php

class DirectoryCleaner
{
	protected $dir;
	protected $lookback;

	public function __construct($dir, $lookback = "30 days") {
		if(is_dir($dir)) {
			$this->dir = $dir;
		} else {
			throw new Exception("The directory provided is not valid");
		}

		$this->lookback = $lookback;
	}

	public function runProcess($filter = NULL) {
		$expired = strtotime("-".$this->lookback);
		$files = glob($this->dir."/*".$filter);

		foreach($files as $file) {
			if(!is_dir($file)) {
				$stats = stat($file);
				if($stats['mtime'] <= $expired) {
					if(unlink($file)) {
						echo "File deleted: ".$file."\n";
					} else {
						echo "File fialed to delete: ".$file."\n";
					}
				}
			}
		}
	}
}

?>
