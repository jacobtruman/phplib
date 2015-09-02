<?php

require_once("Logger.class.php");

class Thumbnailer {

	protected $base_dir;
	protected $thumb_base;
	protected $logger;

	public function __construct($base_dir) {
		$this->base_dir = $base_dir;
		$this->thumb_base = $this->base_dir."/thumbnails";

		$date = date("Y-m-d");
		$log_file = "/mine/scripts/logs/".__CLASS__."_{$date}.log";
		$this->logger = new Logger($log_file);
	}

	public function getThumbDir($file) {
		$file_info = pathinfo($file);
		$thumb_dir = str_replace($this->base_dir, $this->thumb_base, $file_info['dirname']);
		if(!file_exists($thumb_dir)) {
			mkdir($thumb_dir, 0777, true);
		}
		return $thumb_dir;
	}

	public function generateThumb($file) {
		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$thumb_dir = $this->getThumbDir($file);
		$file_parts = explode("/", $file);
		$file_name = end($file_parts);

		$thumbnail = $thumb_dir . "/" . $file_name;
		if (!file_exists($thumbnail)) {
			if (file_exists($file)) {
				$this->logger->addToLog("Generating thumbnail from [{$file}] to [{$thumbnail}]");
				list($width, $height) = getimagesize($file);

				$new_height = 150;
				$new_width = $width / ($height / $new_height);

				if (in_array($ext, array("jpg", "jpeg"))) {
					$methods = array("imagecreatefrom" => "imagecreatefromjpeg", "image" => "imagejpeg");
					$quality = 100;
				} elseif ($ext == "png") {
					$methods = array("imagecreatefrom" => "imagecreatefrompng", "image" => "imagepng");
					$quality = 9;
				} elseif ($ext == "gif") {
					$methods = array("imagecreatefrom" => "imagecreatefromgif", "image" => "imagegif");
					$quality = 100;
				}

				// Load the images
				$thumb = imagecreatetruecolor($new_width, $new_height);
				if (!$thumb) {
					$this->logger->addToLog("imagecreatetruecolor failed");

					return false;
				}
				$source = $methods["imagecreatefrom"]($file);
				if (!$source) {
					$this->logger->addToLog("{$methods["imagecreatefrom"]} failed");

					return false;
				}

				// Resize the $thumb image.
				if (imagecopyresized($thumb, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height)) {
					// Save the new file to the location specified by $thumbnail
					if (!$methods["image"]($thumb, $thumbnail, $quality)) {
						$this->logger->addToLog("{$methods["image"]} FAILED to generate thumbnail... [{$thumbnail}]");

						return false;
					}
				} else {
					$this->logger->addToLog("imagecopyresized failed");

					return false;
				}
			} else {
				$this->logger->addToLog("File [{$file}] does not exist...");
				return false;
			}
		}
		return true;
	}
}

?>
