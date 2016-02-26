<?php

class TVShow
{
	protected $episode_pattern = '/[Ss][0-9]{1,2}[Ee][0-9]{1,2}|[0-9]{1,2}x[0-9]{1,2}|\.[0-9]{1,2}[0-9]{1,2}/';
	protected $season_pattern = '/[Ss][0-9]{1,2}|[0-9]{1,2}(?<![0-9]{2})/';
	protected $episode;
	protected $season;
	protected $show;
	protected $show_string;
	protected $show_folder;
	protected $valid;
	protected $invalid_reason;

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
		return "S".$this->padNumber($this->getSeasonNumber())."E".$this->padNumber($this->getEpisodeNumber());
	}

	public function getShowFolder() {
		return $this->show_folder;
	}

	public function getSeasonNumber() {
		return $this->season;
	}

	public function getEpisodeNumber() {
		return substr($this->episode, strpos($this->episode, $this->season) + 1);
	}

	public function getShowString() {
		return $this->show;
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
		$this->show = str_replace(array("'", '"', "&", "-", "(", ")"), "", $this->show);
		$this->show = str_replace(array("."), " ", $this->show);
		$this->show = ucwords($this->show);

		// check if the show name contains the year, if so, remove it
		$year_pattern = "/(\d{4})/";
		if(preg_match($year_pattern, $this->show, $matches)) {
			$this->show = trim(str_replace($matches[0], "", $this->show));
			$this->show_folder .= $this->show." (".$matches[0].")";
		} else {
			$this->show_folder = $this->show;
		}
	}

	protected function padNumber($number, $len = 2) {
		while(strlen($number) < $len) {
			$number = "0".$number;
		}
		return $number;
	}
}

?>
