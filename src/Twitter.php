<?php

declare(strict_types=1);

/**
 * Twitter for PHP - library for sending messages to Twitter and receiving status updates.
 *
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 * This software is licensed under the New BSD License.
 *
 * Homepage:    https://phpfashion.com/twitter-for-php
 * Twitter API: https://dev.twitter.com/rest/public
 * Version:     4.0
 */

namespace DG\Twitter;

use stdClass;


/**
 * Twitter API.
 */
class Twitter
{
	public const ME = 1;
	public const ME_AND_FRIENDS = 2;
	public const REPLIES = 3;
	public const RETWEETS = 128; // include retweets?

	private const API_URL = 'https://api.twitter.com/1.1/';

	/** @var int */
	public static $cacheExpire = '30 minutes';

	/** @var string */
	public static $cacheDir;

	/** @var array */
	public $httpOptions = [
		CURLOPT_TIMEOUT => 20,
		CURLOPT_SSL_VERIFYPEER => 0,
		CURLOPT_HTTPHEADER => ['Expect:'],
		CURLOPT_USERAGENT => 'Twitter for PHP',
	];

	/** @var OAuth\Consumer */
	private $consumer;

	/** @var OAuth\Token */
	private $token;


	/**
	 * Creates object using consumer and access keys.
	 * @throws \Twitter\Exception when CURL extension is not loaded
	 */
	public function __construct(string $consumerKey, string $consumerSecret, string $accessToken = null, string $accessTokenSecret = null)
	{
		if (!extension_loaded('curl')) {
			throw new Exception('PHP extension CURL is not loaded.');
		}

		$this->consumer = new OAuth\Consumer($consumerKey, $consumerSecret);
		$this->token = new OAuth\Token($accessToken, $accessTokenSecret);
	}


	/**
	 * Tests if user credentials are valid.
	 * @throws \Twitter\Exception
	 */
	public function authenticate(): bool
	{
		try {
			$res = $this->request('account/verify_credentials', 'GET');
			return !empty($res->id);

		} catch (Exception $e) {
			if ($e->getCode() === 401) {
				return false;
			}
			throw $e;
		}
	}


	/**
	 * Sends message to the Twitter.
	 * https://dev.twitter.com/rest/reference/post/statuses/update
	 * @param  string|array  $mediaPath  path to local media file to be uploaded
	 * @throws \Twitter\Exception
	 */
	public function send(string $message, $mediaPath = null, array $options = []): stdClass
	{
		$mediaIds = [];
		foreach ((array) $mediaPath as $item) {
			$res = $this->request(
				'https://upload.twitter.com/1.1/media/upload.json',
				'POST',
				null,
				['media' => $item]
			);
			$mediaIds[] = $res->media_id_string;
		}
		return $this->request(
			'statuses/update',
			'POST',
			$options + ['status' => $message, 'media_ids' => implode(',', $mediaIds) ?: null]
		);
	}


	/**
	 * Sends a direct message to the specified user.
	 * https://dev.twitter.com/rest/reference/post/direct_messages/new
	 * @throws \Twitter\Exception
	 */
	public function sendDirectMessage(string $username, string $message): stdClass
	{
		return $this->request(
			'direct_messages/events/new',
			'POST',
			['event' => [
				'type' => 'message_create',
				'message_create' => [
					'target' => ['recipient_id' => $this->loadUserInfo($username)->id_str],
					'message_data' => ['text' => $message],
				],
			]]
		);
	}


	/**
	 * Follows a user on Twitter.
	 * https://dev.twitter.com/rest/reference/post/friendships/create
	 * @throws \Twitter\Exception
	 */
	public function follow(string $username): stdClass
	{
		return $this->request('friendships/create', 'POST', ['screen_name' => $username]);
	}


	/**
	 * Returns the most recent statuses.
	 * https://dev.twitter.com/rest/reference/get/statuses/user_timeline
	 * @param  int  $flags  timeline (ME | ME_AND_FRIENDS | REPLIES) and optional (RETWEETS)
	 * @return stdClass[]
	 * @throws \Twitter\Exception
	 */
	public function load(int $flags = self::ME, int $count = 20, array $data = null): array
	{
		static $timelines = [
			self::ME => 'user_timeline',
			self::ME_AND_FRIENDS => 'home_timeline',
			self::REPLIES => 'mentions_timeline',
		];
		if (!isset($timelines[$flags & 3])) {
			throw new \InvalidArgumentException;
		}

		return $this->cachedRequest('statuses/' . $timelines[$flags & 3], (array) $data + [
			'count' => $count,
			'include_rts' => $flags & self::RETWEETS ? 1 : 0,
		]);
	}


	/**
	 * Returns information of a given user.
	 * https://dev.twitter.com/rest/reference/get/users/show
	 * @throws \Twitter\Exception
	 */
	public function loadUserInfo(string $username): stdClass
	{
		return $this->cachedRequest('users/show', ['screen_name' => $username]);
	}


	/**
	 * Returns information of a given user by id.
	 * https://dev.twitter.com/rest/reference/get/users/show
	 * @throws \Twitter\Exception
	 */
	public function loadUserInfoById(string $id): stdClass
	{
		return $this->cachedRequest('users/show', ['user_id' => $id]);
	}


	/**
	 * Returns IDs of followers of a given user.
	 * https://dev.twitter.com/rest/reference/get/followers/ids
	 * @throws \Twitter\Exception
	 */
	public function loadUserFollowers(string $username, int $count = 5000, int $cursor = -1, $cacheExpiry = null): stdClass
	{
		return $this->cachedRequest('followers/ids', [
			'screen_name' => $username,
			'count' => $count,
			'cursor' => $cursor,
		], $cacheExpiry);
	}


	/**
	 * Returns list of followers of a given user.
	 * https://dev.twitter.com/rest/reference/get/followers/list
	 * @throws \Twitter\Exception
	 */
	public function loadUserFollowersList(string $username, int $count = 200, int $cursor = -1, $cacheExpiry = null): stdClass
	{
		return $this->cachedRequest('followers/list', [
			'screen_name' => $username,
			'count' => $count,
			'cursor' => $cursor,
		], $cacheExpiry);
	}


	/**
	 * Destroys status.
	 * @param  int|string  $id  status to be destroyed
	 * @throws \Twitter\Exception
	 */
	public function destroy($id)
	{
		$res = $this->request("statuses/destroy/$id", 'POST');
		return $res->id ?: false;
	}


	/**
	 * Returns tweets that match a specified query.
	 * https://dev.twitter.com/rest/reference/get/search/tweets
	 * @param  string|array
	 * @throws \Twitter\Exception
	 */
	public function search($query, bool $full = false): stdClass
	{
		$res = $this->request('search/tweets', 'GET', is_array($query) ? $query : ['q' => $query]);
		return $full ? $res : $res->statuses;
	}


	/**
	 * Process HTTP request.
	 * @return stdClass|stdClass[]
	 * @throws \Twitter\Exception
	 */
	public function request(string $resource, string $method, array $data = null, array $files = null)
	{
		if (!strpos($resource, '://')) {
			if (!strpos($resource, '.')) {
				$resource .= '.json';
			}
			$resource = self::API_URL . $resource;
		}

		$hasCURLFile = class_exists('CURLFile', false) && defined('CURLOPT_SAFE_UPLOAD');

		foreach ((array) $data as $key => $val) {
			if ($val === null) {
				unset($data[$key]);
			} elseif ($files && !$hasCURLFile && substr($val, 0, 1) === '@') {
				throw new Exception('Due to limitation of cURL it is not possible to send message starting with @ and upload file at the same time in PHP < 5.5');
			}
		}

		foreach ((array) $files as $key => $file) {
			if (!is_file($file)) {
				throw new Exception("Cannot read the file $file. Check if file exists on disk and check its permissions.");
			}
			$data[$key] = $hasCURLFile ? new \CURLFile($file) : '@' . $file;
		}

		$request = OAuth\Request::from_consumer_and_token($this->consumer, $this->token, $method, $resource, $files ? [] : $data);
		$request->sign_request(new OAuth\SignatureMethod_HMAC_SHA1, $this->consumer, $this->token);

		$options = [
			CURLOPT_HEADER => false,
			CURLOPT_RETURNTRANSFER => true,
		] + ($method === 'POST' ? [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $files ? $data : $request->to_postdata(),
			CURLOPT_URL => $files ? $request->to_url() : $request->get_normalized_http_url(),
		] : [
			CURLOPT_URL => $request->to_url(),
		]) + $this->httpOptions;

		if ($method === 'POST' && $hasCURLFile) {
			$options[CURLOPT_SAFE_UPLOAD] = true;
		}

		$curl = curl_init();
		curl_setopt_array($curl, $options);
		$result = curl_exec($curl);
		if (curl_errno($curl)) {
			throw new Exception('Server error: ' . curl_error($curl));
		}

		$payload = defined('JSON_BIGINT_AS_STRING')
			? @json_decode($result, false, 128, JSON_BIGINT_AS_STRING)
			: @json_decode($result); // intentionally @

		if ($payload === false) {
			throw new Exception('Invalid server response');
		}

		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if ($code >= 400) {
			throw new Exception(isset($payload->errors[0]->message)
				? $payload->errors[0]->message
				: "Server error #$code with answer $result",
				$code
			);
		}

		return $payload;
	}


	/**
	 * Cached HTTP request.
	 * @return stdClass|stdClass[]
	 */
	public function cachedRequest(string $resource, array $data = null, $cacheExpire = null)
	{
		if (!self::$cacheDir) {
			return $this->request($resource, 'GET', $data);
		}
		if ($cacheExpire === null) {
			$cacheExpire = self::$cacheExpire;
		}

		$cacheFile = self::$cacheDir
			. '/twitter.'
			. md5($resource . json_encode($data) . serialize([$this->consumer, $this->token]))
			. '.json';

		$cache = @json_decode(@file_get_contents($cacheFile)); // intentionally @
		$expiration = is_string($cacheExpire) ? strtotime($cacheExpire) - time() : $cacheExpire;
		if ($cache && @filemtime($cacheFile) + $expiration > time()) { // intentionally @
			return $cache;
		}

		try {
			$payload = $this->request($resource, 'GET', $data);
			file_put_contents($cacheFile, json_encode($payload));
			return $payload;

		} catch (Exception $e) {
			if ($cache) {
				return $cache;
			}
			throw $e;
		}
	}


	/**
	 * Makes twitter links, @usernames and #hashtags clickable.
	 */
	public static function clickable(stdClass $status): string
	{
		$all = [];
		foreach ($status->entities->hashtags as $item) {
			$all[$item->indices[0]] = ["https://twitter.com/search?q=%23$item->text", "#$item->text", $item->indices[1]];
		}
		foreach ($status->entities->urls as $item) {
			if (!isset($item->expanded_url)) {
				$all[$item->indices[0]] = [$item->url, $item->url, $item->indices[1]];
			} else {
				$all[$item->indices[0]] = [$item->expanded_url, $item->display_url, $item->indices[1]];
			}
		}
		foreach ($status->entities->user_mentions as $item) {
			$all[$item->indices[0]] = ["https://twitter.com/$item->screen_name", "@$item->screen_name", $item->indices[1]];
		}
		if (isset($status->entities->media)) {
			foreach ($status->entities->media as $item) {
				$all[$item->indices[0]] = [$item->url, $item->display_url, $item->indices[1]];
			}
		}

		krsort($all);
		$s = isset($status->full_text) ? $status->full_text : $status->text;
		foreach ($all as $pos => $item) {
			$s = iconv_substr($s, 0, $pos, 'UTF-8')
				. '<a href="' . htmlspecialchars($item[0]) . '">' . htmlspecialchars($item[1]) . '</a>'
				. iconv_substr($s, $item[2], iconv_strlen($s, 'UTF-8'), 'UTF-8');
		}
		return $s;
	}
}



/**
 * An exception generated by Twitter.
 */
class Exception extends \Exception
{
}
