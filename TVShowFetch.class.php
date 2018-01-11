<?php

require_once("Logger.class.php");

/**
 * Class TVShowFetch
 */
class TVShowFetch {

	/**
	 * @var bool
	 */
	protected $latest = false;

	/**
	 * @var bool
	 */
	protected $execute = false;

	/**
	 * @var bool
	 */
	protected $verbose = false;

	/**
	 * @var bool
	 */
	protected $keep_files = false;

	/**
	 * @var string
	 */
	protected $base_dir = "~/TVShows";

	/**
	 * @var string
	 */
	protected $data_dir = "/tmp/TVShowFetchData";

	/**
	 * @var string
	 */
	protected $log_dir = "~/logs";

	/**
	 * @var Logger|null
	 */
	public $logger = null;

	/**
	 * @var null
	 */
	protected $logger_prefix = null;

	/**
	 * @var null
	 */
	protected $filter = null;

	/**
	 * @var null
	 */
	protected $networks = null;

	/**
	 * @param array $params
	 */
	public function __construct($params = array()) {
		register_shutdown_function(array($this, 'shutdownHandler'));

		foreach ($params as $param => $value) {
			if (property_exists($this, $param)) {
				$rp = new ReflectionProperty($this, $param);
				if (!$rp->isPrivate()) {
					$this->$param = $value;
				}
			}
		}

		$this->fixFilePaths();

		if ($this->logger === null) {
			$this->logger = new Logger("{$this->log_dir}/TVShowFetch_" . date("Y-m-d") . ".log", !$this->verbose);
		}

		if ($this->networks !== null) {
			$this->networks = explode(",", strtolower($this->networks));
		}

		if (!is_dir($this->data_dir)) {
			mkdir($this->data_dir, 0777, true);
		}

		$this->cleanup();
	}

	protected function fixFilePaths() {
		$file_paths = array("base_dir", "log_dir", "data_dir");
		$home = getenv("HOME");
		foreach($file_paths as $file_path) {
			$this->$file_path = str_replace("~", $home, $this->$file_path);
		}
	}

	/**
	 * @param $config
	 */
	public function processConfig($config) {
		if ($this->networks === null || in_array(strtolower($config['network']), $this->networks)) {
			$this->logger->addToLog("{$this->logger_prefix}Processing network '{$config['network']}'");
			call_user_func_array(array($this, $config['method']), array($config));
		}
	}

	/**
	 * @return string
	 */
	protected function getFetchCommand() {
		return "youtube-dl --no-mtime --audio-quality 0 -o '{$this->base_dir}/%(series)s/Season %(season_number)s/%(series)s - S%(season_number)02dE%(episode_number)02d.%(ext)s'";
	}

	/**
	 * @param $config
	 */
	protected function getCBSShows($config) {
		$shows = $this->getActiveShows($config['shows']);
		$count = count($shows);
		if ($count) {
			$base_url = "http://www.cbs.com";
			$limit = 100;
			foreach ($shows as $i => $show_info) {
				$num = $i + 1;
				$show_title = $show_info['show_title'];
				$this->logger->addToLog("{$this->logger_prefix}Processing show {$num} / {$count} :: '{$show_title}'");
				$episode_data = array("show" => $show_title, "episodes" => array());
				$offset = 0;
				$total = null;

				while ($total == null || $offset <= $total) {
					$show_id = $show_info['show_id'];

					if (isset($show_info['single_season']) && $show_info['single_season']) {
						$show_url = "{$base_url}/carousels/videosBySection/{$show_id}/offset/{$offset}/limit/{$limit}/xs/0/";
					} else {
						$show_url = "{$base_url}/carousels/shows/{$show_id}/offset/{$offset}/limit/{$limit}/";
					}
					$data_file = "{$show_id}-{$offset}.json";

					$contents = $this->getDataFile($show_url, $data_file);

					$json = json_decode($contents, true);
					if ($total == null) {
						$total = $json['result']['total'];
					}
					$offset += $limit;

					foreach ($json['result']['data'] as $record) {
						$filename = null;
						$season_number = $record['season_number'];
						$episode_number = $record['episode_number'];
						$episode_url = "{$base_url}{$record['url']}";
						if (strstr($episode_number, ",")) {
							$episode_numbers = explode(",", $episode_number);
							$eps = array();
							foreach ($episode_numbers as $episode_number) {
								$eps[] = "E" . str_pad(trim($episode_number), 2, "0", STR_PAD_LEFT);
							}
							$episode_string = implode("-", $eps);
							$filename = "{$this->base_dir}/%(series)s/Season %(season_number)s/%(series)s - S%(season_number)02d{$episode_string}";
						}
						$episode_data['episodes'][$season_number][$episode_number]["url"] = $episode_url;
						$episode_data['episodes'][$season_number][$episode_number]["filename"] = $filename;
					}
				}

				$this->processEpisodes($episode_data);
			}
		}
	}

	/**
	 * @param $config
	 */
	protected function getNBCShows($config) {
		$shows = $this->getActiveShows($config['shows']);
		$count = count($shows);
		if ($count) {
			foreach ($shows as $i => $show_info) {
				$num = $i + 1;
				$show_id = $show_info['show_id'];
				$show_title = $show_info['show_title'];
				$this->logger->addToLog("{$this->logger_prefix}Processing show {$num} / {$count} :: '{$show_title}'");
				$episode_data = array("show" => $show_title, "episodes" => array());

				$base_url = "https://api.nbc.com/v3.14/videos";

				$end_date = date("Y-m-d", strtotime("+1 day"));

				$loop = true;
				$page_num = 0;
				$page_size = 50;
				while ($loop) {
					$page_num++;
					$params = array();
					$params[] = "fields[videos]=title,type,available,seasonNumber,episodeNumber,expiration,entitlement,tveAuthWindow,nbcAuthWindow,permalink,embedUrl,externalAdId";
					$params[] = "include=show.season";
					$params[] = "filter[show]={$show_id}";
					$params[] = "filter[available][value]={$end_date}";
					$params[] = "filter[available][operator]=<";
					$params[] = "filter[entitlement][value]=free";
					$params[] = "filter[entitlement][operator]==";
					$params[] = "filter[type][value]=Full Episode";
					$params[] = "filter[type][operator]==";

					$params[] = "page[number]={$page_num}";
					$params[] = "page[size]={$page_size}";

					$params_string = str_replace("include%3D", "include=", str_replace("%5D%3D", "%5D=", str_replace("%3D%3C", "=%3C", str_replace("%3D%3E", "=%3E", str_replace("%3D%3D", "=%3D", implode("&", array_map("urlencode", $params)))))));

					$show_url = $base_url . "?" . $params_string;

					$data_file = str_replace(" ", "_", strtolower($show_title)) . "-{$page_num}.json";

					$contents = $this->getDataFile($show_url, $data_file);

					$json = json_decode($contents, true);
					$data = $json['data'];
					// break out of the loop
					if (count($data) < $page_size) {
						$loop = false;
					}

					$now = time();
					foreach ($data as $record) {
						$attributes = $record['attributes'];
						$entitlement = $attributes['entitlement'];
						if ($entitlement != "free") {
							continue;
						}

						$get = false;
						foreach ($attributes['nbcAuthWindow'] as $window) {
							if ($window['type'] != "free") {
								continue;
							}
							$end_ts = strtotime($window['end']);
							if ($now < $end_ts) {
								$get = true;
							}
						}

						if ($get) {
							$season_number = $attributes['seasonNumber'];
							$episode_number = $attributes['episodeNumber'];
							$season = str_pad($season_number, 2, "0", STR_PAD_LEFT);
							$episode = str_pad($episode_number, 2, "0", STR_PAD_LEFT);
							$episode_string = "S{$season}E{$episode}";
							$filename = "{$this->base_dir}/{$show_info['show_title']}/Season {$season_number}/{$show_info['show_title']} - {$episode_string}";

							$episode_data['episodes'][$season_number][$episode_number]['url'] = $attributes['permalink'];
							$episode_data['episodes'][$season_number][$episode_number]['filename'] = $filename;
						}
					}
				}
				$this->processEpisodes($episode_data);
			}
		}
	}

	/**
	 * @param $config
	 */
	protected function getCWShows($config) {
		$shows = $this->getActiveShows($config['shows']);
		$count = count($shows);
		if ($count) {
			$date = date("Ymd");

			foreach ($shows as $i => $show_info) {
				$num = $i + 1;
				$show_title = $show_info['show_title'];
				$this->logger->addToLog("{$this->logger_prefix}Processing show {$num} / {$count} :: '{$show_title}'");
				$episode_data = array("show" => $show_title, "episodes" => array());
				$show_id = $show_info['show_id'];
				$show_url = "http://images.cwtv.com/data/r_{$date}000/videos/{$show_id}/data.js";
				$data_file = "{$show_id}.json";

				$contents = $this->getDataFile($show_url, $data_file);

				$start = strpos($contents, "{");
				$end = strpos($contents, ";", $start);
				$json = json_decode(substr($contents, $start, $end - $start), true);

				foreach ($json as $episode_id => $episode_info) {
					if ($episode_info['type'] == "Full") {
						$season_number = $episode_info['season'];
						$episode_number = $episode_info['episode'];
						$episode_data['episodes'][$season_number][$episode_number]['url'] = "http://www.cwtv.com/shows/{$show_id}/?play={$episode_id}";
						$episode_data['episodes'][$season_number][$episode_number]['filename'] = null;
					}
				}
				$this->processEpisodes($episode_data);
			}
		}
	}

	/**
	 * @param $config
	 */
	protected function getABCShows($config) {
		$shows = $this->getActiveShows($config['shows']);
		$count = count($shows);
		if ($count) {
			$base_url = "http://abc.go.com";
			foreach ($shows as $i => $show_info) {
				$num = $i + 1;
				$show_title = $show_info['show_title'];
				$this->logger->addToLog("{$this->logger_prefix}Processing show {$num} / {$count} :: '{$show_title}'");
				$episode_data = array("show" => $show_title, "episodes" => array());
				$show_id = $show_info['show_id'];
				$show_url = "{$base_url}/shows/{$show_id}/episode-guide/";
				$data_file = "{$show_id}-base.json";

				$contents = $this->getDataFile($show_url, $data_file);
				$dom = $this->getDOM($contents);
				$elements = $dom->getElementsByTagName('select');
				foreach ($elements as $element) {
					if ($element->getAttribute('name') == "blog-select") {
						$seasons = $element->getElementsByTagName('option');
						foreach ($seasons as $season) {
							$season_url = $season->getAttribute('value');
							$season_number = trim(substr($season_url, strrpos($season_url, "-") + 1));
							$contents = $this->getDataFile($base_url . $season_url, "{$show_id}-{$season_number}");

							$season_dom = $this->getDOM($contents);
							$elements = $season_dom->getElementsByTagName('div');
							foreach ($elements as $element) {
								if ($element->getAttribute('data-sm-type') == "episode") {
									$links = $element->getElementsByTagName('a');
									$watch = false;
									foreach ($links as $link) {
										if (strtolower($link->nodeValue) == "watch") {
											$watch = true;
											break;
										}
									}
									if ($watch) {
										$divs = $element->getElementsByTagName('div');
										$locked = false;
										foreach ($divs as $div) {
											if (strstr($div->getAttribute('class'), "locked")) {
												$locked = true;
												break;
											}
										}

										$spans = $element->getElementsByTagName('span');
										foreach ($spans as $span) {
											if (strstr($span->getAttribute('class'), "episode-number")) {
												$episode = trim(str_replace("E", "", $span->nodeValue));
											}
										}

										if (!$locked) {
											$episode_data['episodes'][$season_number][$episode]['url'] = $base_url . trim($element->getAttribute('data-url'));
											$episode_data['episodes'][$season_number][$episode]['filename'] = null;
										}
									}
								}
							}
						}
					}
				}
				$this->processEpisodes($episode_data);
			}
		}
	}

	/**
	 * @param $config
	 */
	protected function getFOXShows($config) {
		$headers = array(
			"apiKey: {$config['apiKey']}"
		);

		$shows = $this->getActiveShows($config['shows']);
		$count = count($shows);
		if ($count) {
			foreach ($shows as $i => $show_info) {
				$num = $i + 1;
				$show_title = $show_info['show_title'];
				$this->logger->addToLog("{$this->logger_prefix}Processing show {$num} / {$count} :: '{$show_title}'");
				$episode_data = array("show" => $show_title, "episodes" => array());
				$show_id = $show_info['show_id'];
				$show_url = "https://api.fox.com/fbc-content/v1_4/screens/series-detail/{$show_id}/";
				$data_file = "{$show_id}.json";

				$contents = json_decode($this->getDataFile($show_url, $data_file, $headers), true);

				$seasons = $contents['panels']['member'][1]['items']['member'];

				foreach ($seasons as $season) {
					if (isset($season['episodes'])) {
						$season_number = $season['seasonNumber'];
						$episodes_url = $season['episodes']['@id'];
						$data_file = "{$show_id}_{$season_number}.json";
						$season_episodes = json_decode($this->getDataFile($episodes_url, $data_file, $headers), true);
						foreach ($season_episodes['member'] as $episode) {
							if (!$episode['requiresAuth'] && $episode['isFullEpisode']) {
								$episode_number = $episode['episodeNumber'];
								$id = $episode['id'];
								$url = "https://www.fox.com/watch/{$id}/";
								$episode_data['episodes'][$season_number][$episode_number]['url'] = $url;
								$episode_data['episodes'][$season_number][$episode_number]['filename'] = null;
							}
						}
					}
				}
				$this->processEpisodes($episode_data);
			}
		}
	}

	/**
	 * @param $config
	 */
	protected function getCBCShows($config) {
		$shows = $this->getActiveShows($config['shows']);
		$count = count($shows);
		if ($count) {
			foreach ($shows as $i => $show_info) {
				$num = $i + 1;
				$show_title = $show_info['show_title'];
				$this->logger->addToLog("{$this->logger_prefix}Processing show {$num} / {$count} :: '{$show_title}'");
				$episode_data = array("show" => $show_title, "episodes" => array());
				$show_id = $show_info['show_id'];
				$base_url = "http://www.cbc.ca";

				$url = "{$base_url}/{$show_id}/episodes/season1";

				$data_file = "{$show_id}.html";
				$contents = $this->getDataFile($url, $data_file);
				$dom = $this->getDOM($contents);

				$elements = $dom->getElementsByTagName('div');

				$seasons = array();
				foreach ($elements as $element) {
					if (strstr($element->getAttribute('class'), "seasons")) {
						$links = $element->getElementsByTagName('a');
						foreach ($links as $link) {
							$season_url = $base_url . $link->getAttribute('href');
							$season_number = str_replace("Season ", "", $link->nodeValue);
							$seasons[$season_number] = $season_url;
						}
					}
				}
				ksort($seasons);
				$this->cleanup();

				$max_season = null;
				if ($this->latest) {
					$max_season = max(array_keys($seasons));
				}
				$episode_pages = array();
				foreach ($seasons as $season_number => $season_url) {
					if ($max_season !== null && $season_number !== $max_season) {
						continue;
					}
					$data_file = "{$show_id}-{$season_number}.html";
					$contents = $this->getDataFile($season_url, $data_file);
					$season_dom = $this->getDOM($contents);
					$list_items = $season_dom->getElementsByTagName('li');
					foreach ($list_items as $item) {
						if (strstr($item->getAttribute('class'), "episode")) {
							$links = $item->getElementsByTagName('a');
							foreach ($links as $link) {
								$episode_page_url = $base_url . $link->getAttribute('href');
								$episode_spans = $link->getElementsByTagName('span');
								foreach ($episode_spans as $span) {
									$episode_num_date = explode(" ", trim($span->nodeValue));
									$episode_number = intval(substr($episode_num_date[0], strlen($season_number)));
								}

								$episode_pages[$season_number][$episode_number] = $episode_page_url;
							}
						}
					}
				}
				$this->cleanup();

				$max_episode = null;
				if ($this->latest) {
					$max_episode = max(array_keys($episode_pages[$max_season]));
				}
				foreach ($episode_pages as $season_number => $episode_pages) {
					foreach ($episode_pages as $episode_number => $episode_page_url) {
						if ($max_episode !== null && $episode_number !== $max_episode) {
							continue;
						}
						$data_file = "{$show_id}-{$season_number}-{$episode_number}.html";
						$contents = $this->getDataFile($episode_page_url, $data_file);
						$episode_dom = $this->getDOM($contents);
						$divs = $episode_dom->getElementsByTagName('div');
						foreach ($divs as $div) {
							if (strstr($div->getAttribute('class'), "responsive-container")) {
								$episode_links = $div->getElementsByTagName('a');
								foreach ($episode_links as $episode_link) {
									$episode_data['episodes'][$season_number][$episode_number]['url'] = $episode_link->getAttribute('href');
									$episode_data['episodes'][$season_number][$episode_number]['filename'] = null;
								}
							}
						}
					}
				}

				$this->processEpisodes($episode_data);
			}
		}
	}

	/**
	 * @param $contents
	 * @return DOMDocument
	 */
	protected function getDOM($contents) {
		$dom = new DOMDocument;
		@$dom->loadHTML($contents);
		return $dom;
	}


	/**
	 * Get the latest episode of the $episodes passed in
	 *
	 * @param $episodes Array of episodes in the format:
	 *  array( $season_number => array( $episode_number => array( "url" => $episode_url, "filename" => $filename ) ) )
	 * @return array
	 */
	protected function getLatestEpisode($episodes) {
		$ret = false;
		if (is_array($episodes) && count($episodes) > 0) {
			$max_season = max(array_keys($episodes));
			$max_episode = max(array_keys($episodes[$max_season]));
			$ret = array($max_season, $max_episode);
		}
		return $ret;
	}

	/**
	 * @param $file
	 */
	protected function getShowsFromFile($file) {
		if (file_exists($file)) {
			$urls = explode("\n", trim(file_get_contents($file)));
			foreach ($urls as $url) {
				if (!empty($url)) {
					$this->processUrl($url);
				}
			}
		}
	}

	/**
	 * @param $url
	 * @param $file
	 * @param null $headers
	 * @return string
	 */
	protected function getDataFile($url, $file, $headers = null) {
		$file = "{$this->data_dir}/{$file}";
		if (!file_exists($file)) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			if ($headers !== null) {
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			}
			$output = curl_exec($ch);
			curl_close($ch);

			file_put_contents($file, $output);
		}

		return file_get_contents($file);
	}

	/**
	 * @param $episode_data
	 */
	protected function processEpisodes($episode_data) {
		if(isset($episode_data['episodes']) && isset($episode_data['show'])) {
			$show_title = $episode_data['show'];
			$episodes = $episode_data['episodes'];
			// sort by season number
			ksort($episodes);
			$this->logger_prefix = "\t[ {$show_title} ] ";
			if ($this->latest) {
				$latest = $this->getLatestEpisode($episodes);
				$this->logger->addToLog("{$this->logger_prefix}Processing latest episode");
				if ($latest !== false) {
					list($max_season, $max_episode) = $latest;
					$this->logger_prefix = "\t[ {$show_title} ][ Season {$max_season} ][ Episode {$max_episode} ]";
					$latest_episode = $episodes[$max_season][$max_episode];
					$this->logger->addToLog("{$this->logger_prefix}");
					$this->processUrl($latest_episode['url'], $latest_episode['filename']);
				} else {
					$this->logger->addToLog("{$this->logger_prefix}[ {$show_title} ] Unable to get the latest episode");
				}
			} else {
				$this->logger->addToLog("{$this->logger_prefix}Processing episodes from " . count($episodes) . " seasons");
				foreach ($episodes as $season_num => $episode_list) {
					// sort by episode number
					ksort($episode_list);
					$this->logger_prefix = "\t[ {$show_title} ] ";
					$this->logger->addToLog("{$this->logger_prefix}Processing " . count($episode_list) . " episodes");
					foreach ($episode_list as $episode_num => $episode) {
						$this->logger_prefix = "\t[ {$show_title} ][ Season {$season_num} ][ Episode {$episode_num} ] ";
						$this->logger->addToLog("{$this->logger_prefix}");
						$this->processUrl($episode['url'], $episode['filename']);
					}
				}
			}
			$this->cleanup();
		} else {
			$this->logger->addToLog("{$this->logger_prefix}Episode data structure provided is missing required data");
		}
		$this->logger_prefix = null;
	}

	/**
	 * @param $url
	 * @param null $filename
	 */
	protected function processUrl($url, $filename = null) {
		$filename_auto = $this->getFilename($url);
		$file_info = pathinfo($filename_auto);
		$ext = null;
		if (isset($file_info['extension'])) {
			$ext = ".{$file_info['extension']}";
		}

		$cmd = $this->getFetchCommand();
		if (!empty($filename)) {
			$cmd .= " -o '{$filename}.%(ext)s'";
		} else {
			$filename = $filename_auto;
		}

		$filename = $this->sanitizeFilename($filename);

		$file_info = pathinfo($filename);
		if (!isset($file_info['extension']) && $ext !== null) {
			$filename .= $ext;
		}

		$cmd .= " {$url}";

		$new_filename = null;
		if ($ext !== ".mp4") {
			$new_filename = str_replace($ext, ".mp4", $filename);
		}

		if ($new_filename !== null && file_exists($new_filename)) {
			$this->logger->addToLog("{$this->logger_prefix}File already exists: {$new_filename}");
		} else if ($new_filename === null && file_exists($filename)) {
			$this->logger->addToLog("{$this->logger_prefix}File already exists: {$filename}");
		} else {
			if ($this->execute) {
				if ($this->runCommand($cmd)) {
					$this->convert($filename, $new_filename);
				}
			} else {
				$this->logger->addToLog("{$this->logger_prefix}NOT EXECUTING COMMAND: {$cmd}");
			}
		}
	}

	/**
	 * @param $url
	 * @return bool
	 */
	protected function getFilename($url) {
		$filename = false;
		$cmd = $this->getFetchCommand() . " --get-filename {$url}";
		$this->logger->addToLog($cmd);
		exec($cmd, $output, $status);
		if ($status !== 0) {
			$this->logger->addToLog("{$this->logger_prefix}ERROR: the command '{$cmd}' exited with code '{$status}': {$output}");
		} else {
			$filename = $output[0];
		}
		return $filename;
	}

	/**
	 * @param $cmd
	 * @return bool
	 */
	protected function runCommand($cmd) {
		$ret = true;
		$this->logger->addToLog($cmd);
		if ($this->verbose) {
			system($cmd, $status);
		} else {
			exec($cmd, $output, $status);
		}
		if ($status !== 0) {
			$this->logger->addToLog("{$this->logger_prefix}ERROR: the command '{$cmd}' exited with code '{$status}'");
			$ret = false;
		}
		return $ret;
	}

	/**
	 * @param $filename
	 * @return mixed
	 */
	protected function sanitizeFilename($filename) {
		return str_replace(array("'"), "", $filename);
	}

	/**
	 * @param $shows
	 * @return array
	 */
	protected function getActiveShows($shows) {
		$active_shows = array();
		foreach ($shows as $show_info) {
			if (!isset($show_info['active']) || !$show_info['active']) {
				$this->logger->addToLog("{$this->logger_prefix}{$show_info['show_title']} is not active - skipping");
			} else if ($this->filter !== null && !stristr($show_info['show_title'], $this->filter)) {
				$this->logger->addToLog("{$this->logger_prefix}{$show_info['show_title']} does not match filter provided: '{$this->filter}' - skipping");
			} else {
				$active_shows[] = $show_info;
			}
		}

		$this->logger->addToLog("{$this->logger_prefix}Shows to be processed: " . count($active_shows));

		return $active_shows;
	}

	/**
	 * @param $filename
	 * @param $new_filename
	 */
	protected function convert($filename, $new_filename) {
		$rename = false;
		$file_info = pathinfo($filename);
		$ext = ".mp4";
		if (isset($file_info['extension'])) {
			$ext = ".{$file_info['extension']}";
		}
		if ($new_filename === null || $ext === ".mp4") {
			$new_filename = str_replace($ext, "NEW.mp4", $filename);
			$rename = true;
		}
		$cmd = "ffmpeg -i '{$filename}' -c:v libx264 '{$new_filename}'";
		if ($this->runCommand($cmd)) {
			$this->logger->addToLog("{$this->logger_prefix}Deleting source file '{$filename}'");
			unlink($filename);
			if ($rename) {
				rename($new_filename, $filename);
			}
		} else {
			$this->logger->addToLog("{$this->logger_prefix}Conversion failed; keeping source file '{$filename}'");
		}
	}

	/**
	 *
	 */
	protected function cleanup() {
		if (!$this->keep_files) {
			$this->logger->addToLog("{$this->logger_prefix}Cleaning up");
			$data_files = glob("{$this->data_dir}/*");
			foreach ($data_files as $data_file) {
				if (file_exists($data_file)) {
					$this->logger->addToLog("{$this->logger_prefix}Deleting data file '{$data_file}'");
					unlink($data_file);
				}
			}
		}
	}

	/**
	 *
	 */
	public function shutdownHandler() {
		$this->cleanup();
	}
}
