<?php

require_once("Logger.class.php");
require_once("DBConn.class.php");
require_once("ImgCompare.class.php");

class Photo {

	protected $yearmonth_pattern = '/[0-9]{4}\/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i';
	protected $datetime_pattern = '/[0-9]{8}_[0-9]{6}/i';
	protected $file;
	protected $exif;
	protected $signature;
	protected $dest_path;
	protected $dry_run;
	protected $db;
	protected $table = "images";
	protected $log_prefix = "";
	protected $trash_dir = "/mine/ImageTrash";
	protected $trash = false;

	/**
	 * Initializes the object
	 *
	 * @param $source_file          The file location of the photo
	 * @param null $dest_path       The dest base path
	 * @param bool|false $dry_run
	 * @param bool|false $verbose
	 * @param bool|false $trash
	 * @param null $logger
	 */
	public function __construct($source_file, $dest_path = NULL, $dry_run = false, $verbose = false, $trash = false, $logger = NULL) {
		$this->dest_path = $dest_path;
		$this->file = $this->cleanPath($source_file);
		$this->logger = $logger;
		$this->dry_run = $dry_run;
		$this->verbose = $verbose;
		$this->trash = $trash;
		$this->db = new DBConn();
		if($this->dry_run) {
			$this->log_prefix = "** DRY RUN ** ";
		}
		$this->initLog();
		$this->getExif();
		$this->getSignature();
	}

	public function addProgressToLog($count, $num) {
		$perc = $this->getProgress($count, $num);
		$this->log_prefix .= "{$num} / {$count}: {$perc}% :: ";
	}

	protected function initLog() {
		if($this->logger === NULL) {
			$this->logger = new Logger("/mine/logs/Photos_".date("Y-m-d").".log", !$this->verbose);
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
		$date = isset($this->exif['DateTimeDigitized']) ? $this->exif['DateTimeDigitized'] : (isset($this->exif['DateTimeOriginal']) ? $this->exif['DateTimeOriginal'] : isset($this->exif['DateTime']) ? $this->exif['DateTime'] : NULL);
		if($date === NULL) {
			echo "No date/time from exif".PHP_EOL;
			if(preg_match($this->datetime_pattern, $this->file, $matches)) {
				$date = str_replace("_", " ", $matches[0]);
				$ts = strtotime($date);
				$this->initExif();
				$this->changeDateTimeTaken($ts);
				$date = date("Y:m:d H:i:s", $ts);
			}
		} else {
			echo "Got date/time from exif".PHP_EOL;
		}
		return $date;
	}

	public function renameFile() {
		list($in_db, $records) = ImgCompare::isInDB($this->file, $this->signature, $this->table);
		if(!$in_db) {
			$datetime = $this->getDateTimeFromExif();
			if (!empty($datetime)) {
				$ts = strtotime($datetime);
				if (!empty($ts)) {
					$new_file = $this->getNewFilename($ts);
					if ($new_file !== NULL) {
						$this->logger->addToLog($this->log_prefix . "Renaming file " . $this->file . " to " . $new_file);
						$this->addExifNote("Renamed from " . $this->file . " to " . $new_file);
						if (!$this->dry_run) {
							if (!rename($this->file, $new_file)) {
								$this->logger->addToLog($this->log_prefix . "Failed to rename file - reverting exif changes");
								$this->clearExifNote();
							} else {
								$this->file = $new_file;
								ImgCompare::processFile($this->file, $this->table);
							}
						}
					} else {
						$this->logger->addToLog($this->log_prefix . "Unable to get new filename for " . $this->file);
					}
				} else {
					$this->logger->addToLog($this->log_prefix . "Timestamp is empty for " . $this->file);
				}
			} else {
				$this->logger->addToLog($this->log_prefix . "Unable to get datetime from " . $this->file);
			}
		} else {
			$this->logger->addToLog($this->log_prefix . "Duplicate found for " . $this->file);
			var_dump($records);
			$this->deleteImage();
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
				$cmd = "jhead -ts" . date("Y:m:d:H:i:s", $ts) . " " . $this->cleanFilename($this->file);
				var_dump($cmd);
				exec($cmd);
				$this->getExif();
			}
		}
	}

	public function addExifNote($note) {
		if(!$this->dry_run) {
			exec("jhead -cl \"" . addslashes($note) . "\" " . $this->cleanFilename($this->file));
			$this->getExif();
		}
	}

	public function clearExifNote() {
		if(!$this->dry_run) {
			exec("jhead -dc " . $this->cleanFilename($this->file));
			$this->getExif();
		}
	}

	protected function initExif() {
		if(!$this->dry_run) {
			exec("jhead -mkexif " . $this->cleanFilename($this->file));
			$this->getExif();
		}
	}

	public function setTable($table) {
		$this->table = $table;
	}

	protected function getNewFilename($ts) {
		// format yyyy-mm-dd_hh'mm'ss
		$filename = date("Y-m-d_H'i's", $ts) . ".jpg";
		$year = date("Y", $ts);
		$month = date("M", $ts);
		$path = $this->dest_path;
		preg_match($this->yearmonth_pattern, $path, $matches);
		if (!count($matches)) {
			$path .= "/" . $year . "/" . $month;
		}
		// cleanup the path a little
		$path = $this->cleanPath($path);
		if (!is_dir($path)) {
			$this->logger->addToLog($this->log_prefix . "Creating directory: " . $path);
			mkdir($path, 0777, true);
		}

		$file = $path . "/" . $filename;

		$this->logger->addToLog($this->log_prefix . "Checking file: {$this->file} against {$file}");
		if ($this->fileExists($file)) {
			$this->logger->addToLog($this->log_prefix . "image name exists, incrementing image time and trying again");
			$this->addToDateTimeTaken(1);
			return $this->getNewFilename(++$ts);
		} else {
			return $file;
		}
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

	protected function deleteImage() {
		$ret_val = false;
		if(!file_exists($this->trash_dir)) {
			mkdir($this->trash_dir, 0777);
		}

		$dest_file = $this->trash_dir . $this->file;
		$dest_dir = dirname($dest_file);
		if (!file_exists($dest_dir)) {
			mkdir($dest_dir, 0777, true);
		}

		$this->logger->addToLog($this->log_prefix . "Moving file to trash: " . $this->file . " to " . $dest_file);
		if(!$this->dry_run && $this->trash) {
			$ret_val = rename($this->file, $dest_file);
		}
		return $ret_val;
	}

	protected function cleanPath($path) {
		while(strstr($path, "//")) {
			$path = str_replace("//", "/", $path);
		}
		return $path;
	}

	protected function getProgress($count, $num) {
		return number_format(round((($num / $count) * 100), 2), 2);
	}

	protected function cleanFilename($filename) {
		return addcslashes($filename, ' ,(,)\',"');
	}
}

?>
