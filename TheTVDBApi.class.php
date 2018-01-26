<?php
/**
 * Class TheTVDBApi
 */
class TheTVDBApi {

	/**
	 * @var string
	 */
	protected $base_url = "https://api.thetvdb.com";

	/**
	 * @var
	 */
	protected $username;
	/**
	 * @var
	 */
	protected $userkey;
	/**
	 * @var
	 */
	protected $apikey;

	/**
	 * @var string
	 */
	protected $data_dir = "/tmp/TheTVDBApiData";

	/**
	 * @var null
	 */
	private $_token;

	/**
	 * @param array $params
	 */
	public function __construct($params = array()) {
		#register_shutdown_function(array($this, 'shutdownHandler'));

		foreach ($params as $param => $value) {
			if (property_exists($this, $param)) {
				$rp = new ReflectionProperty($this, $param);
				if (!$rp->isPrivate()) {
					$this->$param = $value;
				}
			}
		}

		$this->_token = $this->_getApiToken();
	}

	/**
	 * @return null
	 */
	private function _getApiToken() {
		$token = null;
		$fields = array(
			"apikey" => $this->apikey,
			"userkey" => $this->userkey,
			"username" => $this->username
		);
		$headers = array(
			"Content-Type: application/json",
			"Accept: application/json"
		);
		$data = json_decode($this->_call(array("url"=>"{$this->base_url}/login", "headers"=>$headers, "fields"=>$fields)), true);
		if(isset($data['token'])) {
			$token = $data['token'];
		}
		return $token;
	}

	/**
	 * @param $series_name
	 * @return mixed
	 */
	public function getSeries($series_name) {
		$series_name = urlencode($series_name);
		$headers = $this->_getAuthHeaders();
		$url = "{$this->base_url}/search/series?name={$series_name}";
		$options = array("url"=>$url, "headers"=>$headers);
		$data = json_decode($this->_call($options), true);
		if(count($data['data']) == 1) {
			return $data['data'][0]['id'];
		} else {
			return false;
		}
	}

	/**
	 * @param $series_id
	 * @return mixed
	 */
	public function getSeriesEpisodes($series_id) {
		$episodes = array();
		$page = 1;
		$headers = $this->_getAuthHeaders();

		while($page !== null) {
			$url = "{$this->base_url}/series/{$series_id}/episodes?page={$page}";
			$options = array("url" => $url, "headers" => $headers);
			$data = json_decode($this->_call($options), true);
			$episodes = array_merge($episodes, $data['data']);
			$page = $data['links']['next'];
		}

		return $episodes;
	}

	/**
	 * @return array
     */
	private function _getAuthHeaders() {
		return array(
			"Accept: application/json",
			"Authorization: Bearer {$this->_token}"
		);
	}

	/**
	 * @param $options
	 * @return mixed
	 */
	private function _call($options) {
		$url = $options['url'];
		$headers = isset($options['headers']) ? $options['headers'] : null;
		$fields = isset($options['fields']) ? $options['fields'] : null;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if ($fields !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
			curl_setopt($ch, CURLOPT_POST, 1);
		}
		if ($headers !== null) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		$data = curl_exec($ch);
		curl_close($ch);

		return $data;
	}

	/**
	 * @param $file
	 * @param array $options
	 * @return mixed|null|string
	 */
	private function _getDataFile($file, $options = array()) {
		$data = null;
		if($file !== null) {
			$file = "{$this->data_dir}/{$file}";
			if (!file_exists($file)) {
				$data = $this->_call($options);
				file_put_contents($file, $data);
			}
			$data = file_get_contents($file);
		} else {
			$data = $this->_call($options);
		}

		return $data;
	}
}
