<?php

/**
 * speech_togetherai class
 *
 */
class speech_togetherai implements speech_interface {

	/**
	 * declare private variables
	 */
	private $api_key;
	private $api_url;
	private $path;
	private $filename;
	private $format;
	private $voice;
	private $message;
	private $model;
	private $language;

	/**
	 * called when the object is created
	 */
	public function __construct($settings) {
		$this->api_key = $settings->get('speech', 'api_key', '');
		$this->api_url = $settings->get('speech', 'api_url', 'https://api.together.ai/v1/audio/speech');
		$this->format = 'wav';
		$this->model = 'hexgrad/Kokoro-82M';
		$this->voice = 'af_heart';
	}

	public function set_path(string $audio_path) {
		$this->path = $audio_path;
	}

	public function set_filename(string $audio_filename) {
		$this->filename = $audio_filename;
	}

	public function set_voice(string $audio_voice) {
		if (strpos($audio_voice, '|') !== false) {
			$parts = explode('|', $audio_voice, 3);
			$this->model = $parts[0] ?: $this->model;
			$this->voice = $parts[1] ?? $this->voice;
			if (!empty($parts[2])) {
				$this->language = $parts[2];
			}
			return;
		}

		$this->voice = $audio_voice;

		if (empty($this->model)) {
			$this->model = $this->infer_model($audio_voice);
		}
	}

	public function set_language(string $audio_language) {
		$this->language = $audio_language;
	}

	public function set_message(string $audio_message) {
		$this->message = $audio_message;
	}

	public function is_language_enabled() : bool {
		// Provider-specific voice values carry model/language metadata for the UI.
		return false;
	}

	public function get_voices() : array {
		$voices = $this->fetch_remote_voices();
		return $voices;
	}

	public function get_format() : string {
		return $this->format;
	}

	public function get_languages() : array {
		return [];
	}

	/**
	 * speech - text to speech
	 */
	public function speech() : bool {
		if (empty($this->api_key) || empty($this->message) || empty($this->path) || empty($this->filename)) {
			return false;
		}

		if (empty($this->voice)) {
			$this->voice = 'af_heart';
		}
		if (empty($this->model)) {
			$this->model = $this->infer_model($this->voice);
		}

		$headers = [
			'Authorization: Bearer '.$this->api_key,
			'Content-Type: application/json'
		];

		$data = [
			'model' => $this->model,
			'input' => $this->message,
			'voice' => $this->voice,
			'response_format' => $this->format,
			'stream' => false
		];

		if (!empty($this->language)) {
			$data['language'] = $this->language;
		}

		$ch = curl_init($this->api_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 90);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

		$response = curl_exec($ch);
		$error = curl_error($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $http_code !== 200) {
			$message = '[speech_togetherai] request failed';
			if (!empty($error)) {
				$message .= ': '.$error;
			}
			if (!empty($response) && strlen($response) < 1000) {
				$message .= ' response='.$response;
			}
			error_log($message);
			return false;
		}

		if (!is_dir($this->path)) {
			mkdir($this->path, 0770, true);
		}

		$path_array = pathinfo($this->filename);
		$target_filename = ($path_array['filename'] ?? $this->filename).'.'.$this->format;
		file_put_contents($this->path.'/'.$target_filename, $response);

		return true;
	}

	public function set_model(string $model): void {
		if (array_key_exists($model, $this->get_models())) {
			$this->model = $model;
		}
	}

	public function get_models(): array {
		return [
			'hexgrad/Kokoro-82M' => 'Kokoro 82M',
			'canopylabs/orpheus-3b-0.1-ft' => 'Orpheus 3B 0.1 FT',
			'cartesia/sonic' => 'Cartesia Sonic',
			'cartesia/sonic-2' => 'Cartesia Sonic 2'
		];
	}

	private function fetch_remote_voices() : array {
		if (empty($this->api_key)) {
			return [];
		}

		$ch = curl_init($this->get_voices_url());
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer '.$this->api_key,
			'Content-Type: application/json'
		]);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $http_code !== 200) {
			return [];
		}

		$json = json_decode($response, true);
		if (!is_array($json)) {
			return [];
		}

		$data = $json['data'] ?? null;
		if (!is_array($data)) {
			return [];
		}

		$voices = [];
		foreach ($data as $model_voices) {
			$model = $model_voices['model'] ?? null;
			if (empty($model) || $this->is_dedicated_model($model)) {
				continue;
			}

			$model_label = $this->format_model_label($model);
			$voice_rows = $model_voices['voices'] ?? [];
			if (!is_array($voice_rows)) {
				continue;
			}

			foreach ($voice_rows as $voice_row) {
				$voice_name = is_array($voice_row) ? ($voice_row['name'] ?? null) : $voice_row;
				if (empty($voice_name)) {
					continue;
				}

				$language = null;
				if (is_array($voice_row)) {
					$language = $voice_row['language'] ?? $voice_row['language_code'] ?? $voice_row['locale'] ?? null;
				}

				$key = $this->build_voice_key($model, $voice_name, $language);
				$label = $voice_name;
				$voices[$model_label][$key] = $label;
			}
		}

		ksort($voices);
		foreach ($voices as $model_label => $voice_group) {
			asort($voice_group);
			$voices[$model_label] = $voice_group;
		}

		return $voices;
	}

	private function get_voices_url() : string {
		if (strpos($this->api_url, '/audio/speech') !== false) {
			return str_replace('/audio/speech', '/voices', $this->api_url);
		}

		return 'https://api.together.ai/v1/voices';
	}

	private function is_dedicated_model(string $model) : bool {
		$dedicated_models = [
			'rime-labs/rime-mist-v2',
			'rime-labs/rime-arcana-v2',
			'rime-labs/rime-arcana-v3',
			'rime-labs/rime-arcana-v3-turbo',
			'minimax/speech-2',
			'deepgram/aura-2'
		];

		return in_array($model, $dedicated_models, true);
	}

	private function format_model_label(string $model) : string {
		$known_models = array_merge($this->get_models(), [
			'cartesia/sonic-3' => 'Cartesia Sonic 3'
		]);

		if (isset($known_models[$model])) {
			return $known_models[$model];
		}

		[$provider, $name] = array_pad(explode('/', $model, 2), 2, '');
		$provider = ucwords(str_replace(['-', '_'], ' ', $provider));
		$name = ucwords(str_replace(['-', '_'], ' ', $name));
		return trim($provider.' '.$name);
	}

	private function build_voice_key(string $model, string $voice, ?string $language = null) : string {
		return $model.'|'.$voice.'|'.($language ?? '');
	}

	private function infer_model(string $voice) : string {
		if (strpos($voice, '_') !== false) {
			return 'hexgrad/Kokoro-82M';
		}

		$orphues_voices = ['tara', 'leah', 'jess', 'leo', 'dan', 'mia', 'zac', 'zoe'];
		if (in_array(strtolower($voice), $orphues_voices, true)) {
			return 'canopylabs/orpheus-3b-0.1-ft';
		}

		return 'cartesia/sonic-2';
	}

}
