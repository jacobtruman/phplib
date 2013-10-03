<?php

class TVShow
{
	private $episode_pattern = '/S[0-9]{1,2}E[0-9]{1,2}|[0-9]{1,2}x[0-9]{1,2}/';
	private $season_pattern = '/S[0-9]{1,2}/';
	private $episode;
	private $season;
	private $show;
	private $show_string;
	private $valid;

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
		}

		if(!$this->getSeason()) {
			$this->valid = false;
		}

		if(!$this->getShow()) {
			$this->valid = false;
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
		}
		else
		{
			$ret_val = false;
		}
		return $ret_val;
	}

	private function getSeason() {
		$ret_val = true;
		preg_match($this->season_pattern, $this->episode, $episode_parts);
		if(!empty($episode_parts[0])) {
			$this->season = $episode_parts[0];
		}
		else
		{
			$ret_val = false;
		}
		return $ret_val;
	}

	private function getEpisode() {
		$ret_val = true;
		preg_match($this->episode_pattern, $this->show_string, $title_parts);
		if(!empty($title_parts[0])) {
			$this->episode = $title_parts[0];
		}
		else
		{
			$ret_val = false;
		}
		return $ret_val;
	}

	public function __get($name) {
		if(isset($this->$name)) {
			$ret_val = $this->$name;
		}
		else
		{
			$ret_val = false;
		}
		return $ret_val;
	}

	public function isValid() {
		return $this->valid;
	}

	private function cleanEpisode()
	{
		$this->episode = (int)str_replace(array("S", "E", "x"), "", $this->episode);
	}

	private function cleanSeason()
	{
		$this->season = (int)str_replace(array("S"), "", $this->season);
	}

	private function cleanShowName()
	{
		$this->show = str_replace(array("'", '"', "&", "-"), "", $this->show);
		$this->show = str_replace(array("."), " ", $this->show);
	}
}

?>