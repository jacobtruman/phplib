<?php

require_once("Logger.class.php");
require_once("DBConn.class.php");
require_once("ImgCompare.class.php");

class Photo {

	protected $yearmonth_pattern = '/[0-9]{4}\/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i';
	protected $file;
	protected $exif;
	protected $signature;
	protected $path;
	protected $dry_run;
	protected $db;
	protected $table = "images";
	protected $log_prefix = "";

	/**
	 * Initializes the object
	 *
	 * @param $path                 The destination base path
	 * @param $file                 The file location of the photo
	 * @param null $base_path       The source base path
	 * @param bool|false $dry_run
	 * @param null $logger
	 */
	public function __construct($path, $file, $base_path = NULL, $dry_run = false, $logger = NULL) {
		$this->path = $path;
		$this->base_path = $base_path;
		$this->file = $file;
		$this->logger = $logger;
		$this->dry_run = $dry_run;
		$this->verbose = true;
		$this->db = new DBConn();
		if($this->dry_run) {
			$this->log_prefix = "** DRY RUN ** ";
		}
		$this->initLog();
		$this->getExif();
		$this->getSignature();
	}

	protected function initLog() {
		if($this->logger === NULL) {
			$this->logger = new Logger("/mine/logs/Photos_".date("Y-m-d").".log");
		}
	}

	protected function getExif() {
		$this->exif = exif_read_data($this->file);
	}

	protected function getSignature() {
		$image = new Imagick($this->file);
		$this->signature = $image->getImageSignature();
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
				ImgCompare::isInDB($new_file, $this->signature);
				var_dump($this->file);
				exit;
				if($new_file !== NULL) {
					$this->logger->addToLog($this->log_prefix."Renaming file " . $this->file . " to " . $new_file);
					$this->addExifNote("Renamed from " . $this->file . " to " . $new_file);
					if(!$this->dry_run) {
						if (!rename($this->file, $new_file)) {
							$this->logger->addToLog($this->log_prefix."Failed to rename file - reverting exif changes");
							$this->clearExifNote();
						} else {
							$this->file = $new_file;
						}
					}
				} else {
					$this->logger->addToLog($this->log_prefix."Unable to get new filename for " . $this->file);
				}
			} else {
				$this->logger->addToLog($this->log_prefix."Timestamp is empty for " . $this->file);
			}
		} else {
			$this->logger->addToLog($this->log_prefix."Unable to get datetime from " . $this->file);
		}
	}

	public function addToDateTimeTaken($num = 0) {
		$datetime = $this->getDateTimeFromExif();
		$ts = strtotime($datetime) + $num;
		if(!$this->dry_run) {
			$this->changeDateTimeTaken($ts);
		} else {
			// spoof changing the exif data when in dry_run mode
			$this->exif['DateTimeDigitized'] = date("Y:m:d:H:i:s", $ts);
		}
	}

	public function changeDateTimeTaken($ts = NULL) {
		if(!$this->dry_run) {
			if ($ts !== null) {
				exec("jhead -ts" . date("Y:m:d:H:i:s", $ts) . " " . addslashes($this->file));
				$this->getExif();
			}
		}
	}

	public function addExifNote($note) {
		if(!$this->dry_run) {
			exec("jhead -cl \"" . addslashes($note) . "\" " . addslashes($this->file));
			$this->getExif();
		}
	}

	public function clearExifNote() {
		if(!$this->dry_run) {
			exec("jhead -dc " . addslashes($this->file));
			$this->getExif();
		}
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
			$this->logger->addToLog($this->log_prefix."Creating directory: ".$path);
			mkdir($path, 0777, true);
		}

		$file = $path."/".$filename;
		$this->logger->addToLog($this->log_prefix."Checking file: {$this->file} against {$file}");
		if($this->isDuplicate($this->file, $file)) {
			// do nothing with the image
			$this->logger->addToLog($this->log_prefix."duplicate image, skipping");
			return NULL;
		} else if(!$this->fileExists($file)) {
			$this->logger->addToLog($this->log_prefix."image name exists, incrementing image time and trying again");
			$this->addToDateTimeTaken(1);
			return $this->getNewFilename(++$ts);
		} else {
			return $file;
		}
	}

	protected function isDuplicate($source_file, $dest_file) {
		// check the file with the same name, and the db
		$source_hash = $this->getFileHash($source_file);
		if(!$this->fileExists($dest_file)) {
			$dest_hash = $this->getFileHash($dest_file);
			if($source_hash == $dest_hash) {
				if($this->verbose) {
					$this->logger->addToLog($this->log_prefix . "{{$source_file}} is a duplicate of dest file {{$dest_file}}");
				}
				return true;
			}
		} else {
			$sql = "SELECT * FROM {$this->table} WHERE hash = '{$source_hash}' AND path <> '{$this->db->real_escape_string($dest_file)}'";
			$result = $this->db->query($sql);
			if($result->num_rows > 0) {
				if($this->verbose) {
					while ($row = $result->fetch_assoc()) {
						$this->logger->addToLog($this->log_prefix . "{{$source_file}} is a duplicate of {{$row['path']}}");
					}
				}
				return true;
			}
		}
		return false;
	}

	protected function fileExists(&$file) {
		if(file_exists($file)) {
			return true;
		} else if(file_exists(addslashes($file))) {
			$file = addslashes($file);
			return true;
		} else if(file_exists(stripslashes($file))){
			$file = stripslashes($file);
			return true;
		}
		return false;
	}

	protected function getFileHash($file) {
		return md5(file_get_contents($file));
	}
}

?>
