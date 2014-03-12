<?php

require_once("Logger.class.php");

class Photo {

	protected $yearmonth_pattern = '/[0-9]{4}\/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i';
	protected $file;
	protected $exif;
	protected $path;

	/**
	* Initializes the object
	* @param string $string The file location of the photo
	*/
	public function __construct($path, $file, $base_path = NULL, $logger = NULL) {
		$this->path = $path;
		$this->base_path = $base_path;
		$this->file = $file;
		$this->logger = $logger;
		$this->initLog();
		$this->getExif();
	}

	protected function initLog() {
		if($this->logger === NULL) {
			$this->logger = new Logger("/mine/logs/Photos_".date("Y-m-d").".log");
		}
	}

	protected function getExif() {
		$this->exif = exif_read_data($this->file);
	}

	public function getDateTimeFromExif() {
		return isset($this->exif['DateTimeDigitized']) ? $this->exif['DateTimeDigitized'] : (isset($this->exif['DateTimeOriginal']) ? $this->exif['DateTimeOriginal'] : $this->exif['DateTime']);
	}

	public function renameFile() {
		$datetime = $this->getDateTimeFromExif();
		if(!empty($datetime)) {
			$ts = strtotime($datetime);
			if(!empty($ts)) {
				$new_file = $this->getNewFilename($ts);
				$this->logger->addToLog("Renaming file ".$this->file." to ".$new_file);
				$this->addExifNote("Renamed from ".$this->file." to ".$new_file);
				#copy($this->file, $new_file);
				if(!rename($this->file, $new_file)) {
					$this->logger->addToLog("Failed to rename file - reverting exif changes");
					$this->clearExifNote();
				} else {
					$this->file = $new_file;
				}
			}
		}
	}

	public function addToDateTimeTaken($num = 0) {
		$datetime = $this->getDateTimeFromExif();
		$ts = strtotime($datetime) + $num;
		exec("jhead -ts".date("Y:m:d:H:i:s", $ts)." ".addslashes($this->file));
		$this->getExif();
	}

	public function changeDateTimeTaken($ts = NULL) {
		if($ts !=== NULL) {
			exec("jhead -ts".date("Y:m:d:H:i:s", $ts)." ".addslashes($this->file));
			$this->getExif();
		}
	}

	public function addExifNote($note) {
		exec("jhead -cl \"".addslashes($note)."\" ".addslashes($this->file));
		$this->getExif();
	}

	public function clearExifNote() {
		exec("jhead -dc ".addslashes($this->file));
		$this->getExif();
	}

	protected function getNewFilename($ts) {
		// format yyyy-mm-dd_hh'mm'ss
		$filename = date("Y-m-d_H'i's", $ts).".jpg";
		$year = date("Y", $ts);
		$month = date("M", $ts);
		$path = $this->base_path;
		if($path === NULL) {
			$path = $this->path;
		}
		preg_match($this->yearmonth_pattern, $path, $matches);
		if(!count($matches)) {
			$path .= "/".$year."/".$month;
		}
		// cleanup the path a little
		$path = str_replace("//", "/", $path);
		if(!is_dir($path)) {
			$this->logger->addToLog("Creating directory: ".$path);
			mkdir($path, 0777, true);
		}

		$this->logger->addToLog("Checking file: ".$path."/".$filename);
		if(file_exists($path."/".$filename)) {
			$this->logger->addToLog("file exists, trying again");
			$this->addToDateTimeTaken(1);
			return $this->getNewFilename(++$ts);
		} else {
			return $path."/".$filename;
		}
	}
}

?>
