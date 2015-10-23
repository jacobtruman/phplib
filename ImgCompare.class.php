<?php

require_once("DBConn.class.php");
require_once("Logger.class.php");

class ImgCompare {

	public $dir;
	protected $db;
	static protected $_table = "images";
	protected $table;
	protected $filters;
	protected $verbose = false;
	protected $cache = NULL;
	protected $size = 0;
	protected $logger;

	public function __construct($dir = NULL, $extensions = array(), $filters = NULL, $verbose = false) {
		if($dir === NULL) {
			throw new Exception("The directory must be specified");
		} else if(!is_array($extensions) || count($extensions) < 1) {
			throw new Exception("At least one extension must be specified");
		}
		$this->dir = $dir;
		$this->extensions = $extensions;
		$this->filters = $filters;
		$this->verbose = $verbose;
		$this->db = self::getDB();
		$this->table = self::getTable();
		$this->logger = self::getLogger();
	}

	protected static function getDB() {
		return new DBConn();
	}

	protected function getTable() {
		return self::$_table;
	}

	protected static function getLogger($silent = true) {
		$date = date("Y-m-d");
		$log_file = "/mine/scripts/logs/".__CLASS__."_{$date}.log";
		return new Logger($log_file, $silent);
	}

	public function findDuplicates() {
		$duplicates = $this->getDuplicates();
		if(count($duplicates) && $this->verbose) {
			print_r($duplicates);
		}
		$this->logger->addToLog(count($duplicates)." duplicates found");
		$this->logger->addToLog($this->size." Bytes");
	}

	public function getDuplicates() {
		$duplicates = array();
		$unique_column = "signature";
		$sql = "SELECT {$unique_column}, count(*) FROM {$this->table} WHERE signature IN (SELECT {$unique_column} FROM {$this->table}";
		if($this->filters !== NULL && is_array($this->filters)) {
			$wheres = array();
			foreach($this->filters as $column=>$value) {
				if(strstr($column, "_exclude")) {
					$column = str_replace("_exclude", "", $column);
					if (is_array($value)) {
						foreach ($value as $val) {
							$wheres[] = $column . " NOT LIKE '%{$this->db->real_escape_string($val)}%'";
						}
					} else {
						$wheres[] = $column . " NOT LIKE '%{$this->db->real_escape_string($value)}%'";
					}
				} else {
					if (is_array($value)) {
						foreach ($value as $val) {
							$wheres[] = $column . " LIKE '%{$this->db->real_escape_string($val)}%'";
						}
					} else {
						$wheres[] = $column . " LIKE '%{$this->db->real_escape_string($value)}%'";
					}
				}
			}
			if(count($wheres)) {
				$sql .= " WHERE " . implode(" AND ", $wheres);
			}
		}
		$sql .= ") GROUP BY {$unique_column} HAVING count(*) > 1 LIMIT 1000";
		if($this->verbose) {
			$this->logger->addToLog($sql);
		}

		$result = $this->db->query($sql);
		$this->size = 0;
		$this->logger->addToLog($result->num_rows." Rows to process");
		$i = 1;
		while ($row = $result->fetch_array()) {
			$pre_log = $i++." / ".$result->num_rows." :: ";
			list($hash, $count) = $row;
			if($this->verbose) {
				$this->logger->addToLog($pre_log.$hash." :: ".$count);
			}
			$sql = "SELECT path FROM {$this->table} WHERE {$unique_column} = '{$hash}'";
			if($this->verbose) {
				$this->logger->addToLog($pre_log.$sql);
			}
			$res = $this->db->query($sql);
			while ($row2 = $res->fetch_array()) {
				list($path) = $row2;
				if(!$this->fileExists($path)) {
					// remove the record from the db
					$this->logger->addToLog($pre_log."Deleting record for {$path}");
					$sql = "DELETE FROM {$this->table} WHERE path = '{$this->db->real_escape_string($path)}'";
					if($this->verbose) {
						$this->logger->addToLog($pre_log.$sql);
					}
					$this->db->query($sql);
				} else {
					$duplicates[ $hash ][] = $path;
					$this->size += filesize($path);
				}
			}
			if(isset($duplicates[ $hash ]) && count($duplicates[ $hash ]) <= 1) {
				unset($duplicates[ $hash ]);
			}
		}
		return $duplicates;
	}

	public function buildDB() {
		if($this->verbose) {
			$this->logger->addToLog("Building DB");
		}
		$this->processDir($this->dir);
	}

	protected static function _getCache($dir = NULL) {
		$cache = NULL;
		$db = self::getDB();
		$sql = "SELECT * FROM ".self::getTable();
		if($dir !== NULL) {
			$sql .= " WHERE path LIKE '{$db->real_escape_string($dir)}%'";
		}
		$result = $db->query($sql);
		while ($row = $result->fetch_assoc()) {
			$key = md5(stripslashes($row['path']));
			$cache[$key] = $row;
		}
		return $cache;
	}

	public static function getCache($dir = NULL) {
		return self::_getCache($dir);
	}

	protected function buildCache($dir = NULL) {
		if($this->cache === NULL) {
			$this->cache = self::_getCache($dir);
		}
	}

	protected function inCache($file) {
		$key = md5(stripslashes($file));
		if (isset($this->cache[$key])) {
			if(isset($this->cache[$key]['signature']) && !empty($this->cache[$key]['signature']) && (isset($this->cache[$key]['hash']) && !empty($this->cache[$key]['hash']))) {
				return true;
			}
		}
		return false;
	}

	public static function findFileDuplicates($file) {

	}

	public function cleanDB() {
		$sql = "SELECT path FROM {$this->table}";
		$result = $this->db->query($sql);
		while ($row = $result->fetch_assoc()) {
			if(!$this->fileExists($row['path'])) {
				$dsql = "DELETE FROM {$this->table} WHERE path = '{$this->db->real_escape_string($row['path'])}'";
				$this->logger->addToLog($dsql);
				$this->db->query($dsql);
			}
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

	protected function processDir($dir) {
		if($this->checkFilters($dir, array("path", "path_exclude"))) {
			// get existing records under this dir
			$this->buildCache($dir);
			$files = glob($dir . "/*.{" . implode(",", $this->getExtensionsPattern()) . "}", GLOB_BRACE);
			if($this->verbose) {
				("Processing dir {$dir}");
				$this->logger->addToLog(count($files)." files found");
			}
			$this->processFiles($files);
		}

		$dirs = glob($dir."/*", GLOB_ONLYDIR);
		if(count($dirs)) {
			foreach($dirs as $this_dir) {
				$this->processDir($this_dir);
			}
		}
	}

	protected function processFiles($files) {
		foreach($files as $file) {
			if(!$this->inCache($file)) {
				if ($this->checkFilters($file, array("file", "file_exclude"))) {
					$image = new Imagick($file);
					$signature = $image->getImageSignature();
					$hash = $this->db->real_escape_string(md5_file($file));
					$file = $this->db->real_escape_string($file);
					$this->logger->addToLog($hash . " :: " . $signature . " :: " . $file);
					$sql = "INSERT INTO {$this->table} SET path = '{$this->db->real_escape_string($file)}', hash = '{$hash}', signature = '{$signature}' ON DUPLICATE KEY UPDATE hash = '{$hash}', signature = '{$signature}'";
					if ($this->verbose) {
						$this->logger->addToLog($sql);
					}
					$this->db->query($sql);
				}
			} else {
				if($this->verbose) {
					$this->logger->addToLog("{$file} already processed");
				}
			}
		}
	}

	public static function processFile($file) {
		$image = new Imagick($file);
		$signature = $image->getImageSignature();
		if(!self::isInDB($file, $signature)) {
			$db = self::getDB();
			$table = self::getTable();
			$logger = self::getLogger(false);
			$hash = $db->real_escape_string(md5_file($file));
			$file = $db->real_escape_string($file);
			$logger->addToLog($hash . " :: " . $signature . " :: " . $file);
			$sql = "INSERT INTO {$table} SET path = '{$db->real_escape_string($file)}', hash = '{$hash}', signature = '{$signature}' ON DUPLICATE KEY UPDATE hash = '{$hash}', signature = '{$signature}'";
			$logger->addToLog($sql);
			$db->query($sql);
		}
	}

	public static function isInDB($file, $signature = NULL) {
		$in_db = false;
		$db = self::getDB();
		$table = self::getTable();
		$logger = self::getLogger(false);
		if($signature === NULL) {
			$image = new Imagick($file);
			$signature = $image->getImageSignature();
		}

		$sql = "SELECT * FROM {$table} WHERE signature = '{$signature}'"; // AND path != '".$db->real_escape_string($file)."'";
		$result = $db->query($sql);
		$logger->addToLog($result->num_rows." Rows found");
		while ($row = $result->fetch_array()) {
			if($row['path'] == $db->real_escape_string($file)) {
				$in_db = true;
				$logger->addToLog("This file ({$file}) is already recorded");
			} else {
				$logger->addToLog("This file ({$file}) is a duplicate of {$row['path']}");
			}
		}
		return $in_db;
	}

	protected function checkFilters($file, $types = NULL) {
		$pass = false;
		if($this->filters !== NULL) {
			// TODO: implement filter type check
			if($types === NULL) {
				// check all filter types
			}

			if(isset($this->filters['path'])) {
				foreach ($this->filters['path'] as $filter) {
					if (strstr($file, $filter)) {
						$pass = true;
						if ($this->verbose) {
							if ($this->verbose) {
								$this->logger->addToLog("Path {$file} matches include filter " . $filter);
							}
						} else {
							if ($this->verbose) {
								$this->logger->addToLog("Path {$file} does not match include filter " . $filter);
							}
						}
					}
				}
			} else {
				$pass = true;
			}

			if(isset($this->filters['path_exclude'])) {
				foreach($this->filters['path_exclude'] as $filter) {
					if(strstr($file, $filter)) {
						$pass = false;
						if($this->verbose) {
							if($this->verbose) {
								$this->logger->addToLog("Path {$file} matches exclude filter " . $filter);
							}
						} else {
							if($this->verbose) {
								$this->logger->addToLog("Path {$file} does not match exclude filter " . $filter);
							}
						}
					}
				}
			} else {
				$pass = true;
			}

			if($pass && $this->verbose) {
				$this->logger->addToLog("Path {$file} matches all filters");
			}
		} else {
			$this->logger->addToLog("No filters defined");
		}

		return $pass;
	}

	/*protected function getSize($path) {
		$bytes = sprintf('%u', filesize($path));

		if ($bytes > 0)
		{
			$unit = intval(log($bytes, 1024));
			$units = array('B', 'KB', 'MB', 'GB');

			if (array_key_exists($unit, $units) === true)
			{
				return sprintf('%d %s', $bytes / pow(1024, $unit), $units[$unit]);
			}
		}

		return $bytes;
	}*/

	protected function getExtensionsPattern() {
		$ret_val = array();

		foreach($this->extensions as $extension) {
			$chars = str_split($extension);
			$tmp = '';
			foreach($chars as $char) {
				$tmp .= "[".strtolower($char).strtoupper($char)."]";
			}
			$ret_val[] = $tmp;
		}

		return $ret_val;
	}

	protected function getExif($file) {
		return exif_read_data($file);
	}
}

?>
