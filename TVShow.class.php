<?php

class TVShow
{
	private $episode_pattern = '/[Ss][0-9]{1,2}[Ee][0-9]{1,2}|[0-9]{1,2}x[0-9]{1,2}|\.[0-9]{1,2}[0-9]{1,2}/';
	private $season_pattern = '/[Ss][0-9]{1,2}|[0-9]{1,2}(?<![0-9]{2})/';
	private $episode;
	private $season;
	private $show;
	private $show_string;
	private $valid;
	private $invalid_reason;

	/**
	* Initializes the object
	* @param string $string The show/episode string to be objectized
	* @return boolean true if it is a valid string, else false
	*/
	public function __construct($show_string) {
		$this->show_string = $show_string;
		$this->valid = true;

		if(!$this->getEpisode()) {
			$this->valid = false;
			$this->invalid_reason = "Episode is false: ".$this->show_string;
		}

		if(!$this->getSeason()) {
			$this->valid = false;
			$this->invalid_reason = "Season is false: ".$this->show_string;
		}

		if(!$this->getShow()) {
			$this->valid = false;
			$this->invalid_reason = "Show is false: ".$this->show_string;
		}

		// cleanup show and episode strings
		$this->cleanEpisode();
		$this->cleanSeason();
		$this->cleanShowName();
	}

	private function getShow() {
		$ret_val = true;
		if(isset($this->episode)) {
			$ptr = strpos($this->show_string, $this->episode);
			$this->show = trim(substr($this->show_string, 0, $ptr - 1));
		} else {
			$ret_val = false;
		}

		return $ret_val;
	}

	private function getSeason() {
		$ret_val = true;
		preg_match($this->season_pattern, $this->episode, $episode_parts);
		if(!empty($episode_parts[0])) {
			$this->season = $episode_parts[0];
		} else {
			$ret_val = false;
		}
		return $ret_val;
	}

	private function getEpisode() {
		$ret_val = true;
		preg_match_all($this->episode_pattern, $this->show_string, $title_parts);
		if(!empty($title_parts[0])) {
			$this->episode = str_replace(".", "", end($title_parts[0]));
		} else {
			$ret_val = false;
		}
		return $ret_val;
	}

	public function getEpisodeString() {
		return $this->show_string;
	}

	public function __get($name) {
		if(isset($this->$name)) {
			$ret_val = $this->$name;
		} else {
			$ret_val = false;
		}
		return $ret_val;
	}

	public function isValid() {
		return $this->valid;
	}

	public function getInvalidReason() {
		return $this->invalid_reason;
	}

	private function cleanEpisode() {
		$this->episode = (int)str_replace(array("S", "s", "E", "e", "x"), "", $this->episode);
	}

	private function cleanSeason() {
		$this->season = (int)str_replace(array("S", "s"), "", $this->season);
	}

	private function cleanShowName() {
		//$year_pattern = "/\((\d{4})\)/";
		$year_pattern = "/(\d{4})/";
		$this->show = str_replace(array("'", '"', "&", "-", "(", ")"), "", $this->show);
		$this->show = str_replace(array("."), " ", $this->show);
		// check if the show name contains the year, if so, remove it
		if(preg_match($year_pattern, $this->show, $matches)) {
			$this->show = trim(str_replace($matches[0], "", $this->show));
		}
		$this->show = ucwords($this->show);
	}
}

?>
