<?php

class KvickPSN {

	const THIS_PSNID		= '<INSERT YOUR PSN ID HERE>';
	const THIS_ACCOUNTID	= '<INSERT YOUR PSN ACCOUNT ID HERE>';
	const VERSION			= 0.3;
	const USER_AGENT		= 'KvickPSN/0.3';
	const CHECK_TOKEN		= 1;
	const CHECK_REFRESH		= 3600;
	const CHECK_SENDING		= 1;
	const CHECK_MESSAGES	= 0.5;
	const CHECK_UNREAD		= 0.5;
	const CHECK_THREADS		= 8;
	const CHECK_CURLSIZE	= 20;
	const MAX_MESSAGE_AGE	= 300;
	const STATE_FILE		= 'kvickpsn.json';
	const THREADS_FILE		= 'kvickpsn-threads.json';
	const LOG_FILE			= 'kvickpsn-log.txt';
	const DEBUG_FILE		= 'kvickpsn-debug.txt';
	const CURL_LOGFILE		= 'kvickpsn-curldebug-{time}.txt';
	const MESSAGE_FILE		= 'kvickpsn-messages.txt';
	const BASE_URI			= 'https://ca.account.sony.com/api';
	const PROFILE_URI		= 'https://m.np.playstation.net/api';
	const CLIENT_ID			= 'ac8d161a-d966-4728-b0ea-ffec22f69edc';
	const BASIC_AUTH		= 'YWM4ZDE2MWEtZDk2Ni00NzI4LWIwZWEtZmZlYzIyZjY5ZWRjOkRFaXhFcVhYQ2RYZHdqMHY=';
	const DEFAULT_SCOPES	= 'psn:clientapp psn:mobile.v1';

	private static	$curlh = null,
					$curlf = null,
					$code = null,
					$loop = null,
					$psnId = self::THIS_PSNID,
					$accountId = self::THIS_ACCOUNTID,
					$ignore = [
						self::THIS_ACCOUNTID,
					],
					$threads = [],
					$check = [],
					$messages = [],
					$sending = [],
					$rateLimited = null,
					$timers = [],
					$state = [
						'npsso' => null,
						'updated' => null,
						'refresh_token' => null,
						'refresh_token_expires' => null,
						'access_token' => null,
						'access_token_expires' => null,
					],
					$events = [
						'message' => null,
						'threadupdated' => null,
						'accessupdated' => null,
						'refreshneeded' => null,
					];

	//
	//	Class constructor
	//
	public function __construct($options = []) {
		self::debug('Creating class', __LINE__, __METHOD__);
		self::$curlh = curl_init();
		if( isset($options['curldebug']) && true === $options['curldebug'] ) {
			self::$curlf = fopen(str_replace('{time}', time(), self::CURL_LOGFILE), 'a');
		}
		self::$loop = $options['loop'] ?? React\EventLoop\Factory::create();
		if( file_exists(self::STATE_FILE) && $json = @file_get_contents(self::STATE_FILE) ) {
			$state = json_decode($json, true);
			if( !is_null($state) ) {
				self::$state = $state;
			} else {
				// error in JSON?
			}
		} else {
			// missing kvickpsn.json
		}
		if( file_exists(self::THREADS_FILE) && $json = @file_get_contents(self::THREADS_FILE) ) {
			$threads = json_decode($json, true);
			if( !is_null($threads) ) {
				self::$threads = $threads;
			} else {
				// error in JSON?
			}
		} else {
			// missing kvickpsn-threads.json
		}
		foreach(self::$events as $k => $v) {
			self::$events[$k] = function() {};
		}
	}

	//
	//	Internal function to via NPSSO retrieve a code to
	//	supply to oauth_token to obtain both a new refresh
	//	token as well as a new access token
	//
	private function oauth_authorize() {
		$headers = [];
		$endpoint = self::BASE_URI.'/authz/v3/oauth/authorize';
		$cookies = [
			'npsso='.self::$state['npsso'],
		];
		$parameters = [
          'access_type' => 'offline',
          'client_id' => self::CLIENT_ID,
          'redirect_uri' => 'com.playstation.PlayStationApp://redirect',
          'response_type' => 'code',
          'scope' => self::DEFAULT_SCOPES
		];
		$params = http_build_query($parameters);
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint.'?'.$params,
			CURLOPT_COOKIE => implode('; ', $cookies),
			CURLOPT_HTTPGET => true,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		$codes = [];
		if( isset($headers['location'][0]) ) {
			if( 1 == preg_match('/code=([A-Za-z0-9:\?_\-\.\/=]+)/', $headers['location'][0], $m) ) {
				$codes['code'] = $m[1];
			} else {
				echo __LINE__.PHP_EOL;
			}
			if( 1 == preg_match('/cid=([A-Za-z0-9:\?_\-\.\/=]+)/', $headers['location'][0], $m) ) {
				$codes['cid'] = $m[1];
			} else {
				echo __LINE__.PHP_EOL;
			}
		} else {
			echo __LINE__.PHP_EOL;
		}
		return $codes;
	}

	//
	//	Internal function to obtain fresh new tokens or
	//	only access token, depending on if the code
	//	obtained from the NPSSO login is present or not
	//
	private function oauth_token($code = null) {
		$headers = [];
		$endpoint = self::BASE_URI.'/authz/v3/oauth/token';
		if( isset($code) && !is_null($code) ) {
			self::$code = $code;
			$parameters = [
				'scope' => self::DEFAULT_SCOPES,
				'code' => $code,
				'grant_type' => 'authorization_code',
				'redirect_uri' => 'com.playstation.PlayStationApp://redirect',
				'token_format' => 'jwt',
			];
		} else {
			$parameters = [
				'scope' => self::DEFAULT_SCOPES,
				'refresh_token' => self::$state['refresh_token'],
				'grant_type' => 'refresh_token',
				'token_format' => 'jwt',
			];
		}
		$params = http_build_query($parameters);
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint,
			CURLOPT_POST => true,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Host: ca.account.sony.com',
				'Referer: https://my.playstation.com/',
				'Authorization: Basic '.self::BASIC_AUTH,
			],
			CURLOPT_POSTFIELDS => $params,
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		$json = json_decode($response, true);
		if( !is_null($json) ) {
			if( isset($json['refresh_token']) ) {
				self::$state['refresh_token'] = $json['refresh_token'];
				if( isset($json['refresh_token_expires_in']) ) {
					self::$state['refresh_token_expires'] = time() + $json['refresh_token_expires_in'];
					self::$state['refresh_token_expires_date'] = date('Y-m-d H:i:s', time() + $json['refresh_token_expires_in']);
					if( isset($json['access_token']) ) {
						self::$state['access_token'] = $json['access_token'];
						if( isset($json['expires_in']) ) {
							self::$state['access_token_expires'] = time() + $json['expires_in'];
							self::$state['access_token_expires_date'] = date('Y-m-d H:i:s', time() + $json['expires_in']);
							self::$state['updated'] = time();
							self::$state['updated_date'] = date('Y-m-d H:i:s', time());
							self::saveState();
						} else {
							self::debug('Missing "expires_in"', __LINE__, __METHOD__);
						}
					} else {
						self::debug('Missing "access_token"', __LINE__, __METHOD__);
					}
				} else {
					self::debug('Missing "refresh_token_expires_in"', __LINE__, __METHOD__);
				}
			} else {
				self::debug('Missing "refresh_token"', __LINE__, __METHOD__);
			}
		} else {
			self::debug('Failed decoding JSON', __LINE__, __METHOD__);
			self::debug($response);
		}
	}

	//
	//	Function to supply a new NPSSO login to
	//	obtain new refresh and access tokens
	//
	public function authenticate($npsso) {
		self::debug('Authenticating', __LINE__, __METHOD__);
		self::$state['npsso'] = $npsso;
		$codes = self::oauth_authorize();
		self::oauth_token($codes['code'] ?? null);
	}

	//
	//	Function to use existing refresh token
	//	to obtain a new access token
	public function refresh() {
		self::debug('Refreshing tokens', __LINE__, __METHOD__);
		self::oauth_token();
	}

	//
	//	Get the user profile of the bot (i.e. the logged in user)
	//
	public function ownProfile() {
		$headers = [];
		$endpoint = 'https://dms.api.playstation.com/api'.'/v1/devices/accounts/me';
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint,
			CURLOPT_HTTPGET => true,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Accept-Language: en-US',
				'User-Agent: '.self::USER_AGENT,
				'Authorization: Bearer '.self::$state['access_token'],
			],
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		return self::handleJsonResponse($response, $headers, __LINE__, __METHOD__);
	}

	//
	//	Attempt to send a friend request to specified user
	//
	public function addFriend($psnId, $message = null) {
		$headers = [];
		$endpoint = 'https://se-prof.np.community.playstation.net/userProfile/v1/users'.'/'.self::$psnId.'/friendList/'.$psnId;
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => !is_null($message) ? json_encode(['requestMessage' => $message]) : '{}',
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Accept-Language: en-US',
				'User-Agent: '.self::USER_AGENT,
				'Authorization: Bearer '.self::$state['access_token'],
				'Origin: https://my.playstation.com',
				'Referer: https://my.playstation.com/',
				'Content-Type: application/json; charset=utf-8',
			],
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
	}

	//
	//	Get the user profile of specified user by *ACCOUNT ID*
	//
	public function userProfile($psnId) {
		$headers = [];
		$endpoint = self::PROFILE_URI.'/userProfile/v1/internal/users'.'/'.$psnId.'/profiles';
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint,
			CURLOPT_HTTPGET => true,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Accept-Language: en-US',
				'User-Agent: '.self::USER_AGENT,
				'Authorization: Bearer '.self::$state['access_token'],
			],
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		return self::handleJsonResponse($response, $headers, __LINE__, __METHOD__);
	}

	//
	//	Attempt to add a PSN id to specified thread
	//	THIS IS NON-WORKING, RETURNS 429 "Rate limit exceeded", ALWAYS
	//
	public function addToThread($psnId, $threadId) {
		$headers = [];
		$endpoint = 'https://se-gmsg.np.community.playstation.net/groupMessaging/v1/threads'.'/'.$threadId.'/users';
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode([
				'userActionEventDetail' => [
					'targetList' => [
						['onlineId' => $psnId],
					],
				],
			]),
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Accept-Language: en-US',
				'User-Agent: '.self::USER_AGENT,
				'Authorization: Bearer '.self::$state['access_token'],
				'Origin: https://my.playstation.com',
				'Referer: https://my.playstation.com/',
				'Content-Type: application/json; charset=utf-8',
			],
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		file_put_contents('kvickpsn-response.txt', $response);
	}

	//
	//	Store a message to send to a specified thread by id
	//
	public function sendMessage($options = []) {
		$message = $options['message'] ?? null;
		$threadId = $options['threadId'] ?? null;
		if( is_string($message) && $message != '' && is_string($threadId) && $threadId != '' ) {
			self::$sending[] = $options;
		} else {
			self::debug('Missing thread id', __LINE__, __METHOD__);
		}
	}

	//
	//	Get all the threads the bot subscribes to
	//
	public function threads() {
		$headers = [];
		$endpoint = 'https://se-gmsg.np.community.playstation.net/groupMessaging/v1/threads';
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint,
			CURLOPT_HTTPGET => true,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Cache-Control: no-cache',
				'Accept-Language: en-GB',
				'User-Agent: '.self::USER_AGENT,
				'Referer: https://my.playstation.com/',
				'Authorization: Bearer '.self::$state['access_token'],
			],
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		return self::handleJsonResponse($response, $headers, __LINE__, __METHOD__);
	}

	//
	//	Get specific thread by id
	//
	public function thread($options = []) {
		$headers = [];
		$endpoint = 'https://se-gmsg.np.community.playstation.net/groupMessaging/v1/threads'.'/'.$options['threadId'];
		$parameters = [
          'fields' => implode(',', [
	          'threadEvents',
          ]),
		];
		$parameters['count'] = $options['count'] ?? 20;
		if( isset($options['maxEventIndex']) && !is_null($options['maxEventIndex']) ) {
			$parameters['maxEventIndex'] = $options['maxEventIndex'];
		}
		if( isset($options['sinceEventIndex']) && !is_null($options['sinceEventIndex']) ) {
			$parameters['sinceEventIndex'] = $options['sinceEventIndex'];
		}
		$params = http_build_query($parameters);
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $endpoint.'?'.$params,
			CURLOPT_HTTPGET => true,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Cache-Control: no-cache',
				'Accept-Language: en-GB',
				'User-Agent: '.self::USER_AGENT,
				'Referer: https://my.playstation.com/',
				'Authorization: Bearer '.self::$state['access_token'],
			],
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		return self::handleJsonResponse($response, $headers, __LINE__, __METHOD__);
	}

	//
	//	Get specific attachment by url
	//
	public function attachment($url, &$contenttype = null) {
		$headers = [];
		curl_reset(self::$curlh);
		if( !is_null(self::$curlf) && false !== self::$curlf ) {
			curl_setopt_array(self::$curlh, [
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => self::$curlf,
			]);
		}
		curl_setopt_array(self::$curlh, [
			CURLOPT_URL => $url,
			CURLOPT_HTTPGET => true,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Cache-Control: no-cache',
				'Accept-Language: en-GB',
				'User-Agent: '.self::USER_AGENT,
				'Referer: https://my.playstation.com/',
				'Authorization: Bearer '.self::$state['access_token'],
			],
			CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
				$len = strlen($header);
				$header = explode(':', $header, 2);
				if(count($header) < 2) {	// ignore invalid headers
					return $len;
				}
				$headers[strtolower(trim($header[0]))][] = trim($header[1]);
				return $len;
			},
		]);
		$response = curl_exec(self::$curlh);
		if( isset($headers['content-type']) ) {
			$contenttype = $headers['content-type'][0];
			return $response;
		}
		return false;
	}

	//
	//	Save state, including tokens as JSON
	//
	public function saveState() {
		self::debug('Saving state', __LINE__, __METHOD__);
		@file_put_contents(self::STATE_FILE, json_encode(self::$state, JSON_PRETTY_PRINT));
	}

	//
	//	Save subscribed threads to compare with
	//
	public function saveThreads() {
		self::debug('Saving threads', __LINE__, __METHOD__);
		@file_put_contents(self::THREADS_FILE, json_encode(self::$threads, JSON_PRETTY_PRINT));
	}

	//
	//	Initialize event loop timers
	//
	public function exit() {
		foreach( self::$timers as $t ) {
			self::$loop->cancelTimer($t);
		}
	}

	//
	//	Initialize event loop timers
	//
	public function init() {

		if( !is_null(self::$curlf) ) {
			self::$timers['check_curlsize'] = self::$loop->addPeriodicTimer(self::CHECK_CURLSIZE, function() {
				$fs = fstat(self::$curlf);
				if( $fs['size'] > 64 * 1024 ) {
					self::debug('CURL log size exceeds threshold, starting new log', __LINE__, basename(__FILE__));
					fclose(self::$curlf);
					self::$curlf = fopen(str_replace('{time}', time(), self::CURL_LOGFILE), 'a');
				}
			});
		}

		self::$timers['check_token'] = self::$loop->addPeriodicTimer(self::CHECK_TOKEN, function() {
			if( time() >= self::$state['access_token_expires'] ) {
				self::debug('Access token expired, trying to refresh', __LINE__, basename(__FILE__));
				self::oauth_token();
				self::$events['accessupdated']();
			}
		});

		self::$timers['check_refresh'] = self::$loop->addPeriodicTimer(self::CHECK_REFRESH, function() {
			if( (time() - (24 * 60 * 60)) >= self::$state['refresh_token_expires'] ) {
				if( date('H') > 7 && date('H') < 18 ) {
					self::debug('Refresh token expires in less than 24 hours, new NPSSO code needed', __LINE__, basename(__FILE__));
					self::$events['refreshneeded']();
				}
			}
		});

		self::$timers['check_sending'] = self::$loop->addPeriodicTimer(self::CHECK_SENDING, function() /* use (self::$loop) */ {
			if( !is_null(self::$rateLimited) && time() < self::$rateLimited ) return;
			if( time() >= self::$state['access_token_expires'] ) return;
			if( count(self::$sending) > 0 ) {
				self::debug('New message to send', __LINE__, basename(__FILE__));
				$m = reset(self::$sending);
				$message = $m['message'];
				$threadId = $m['threadId'];
				$boundary = self::randstr();
				$postFields = PHP_EOL;
				$postFields .= '--'.$boundary.PHP_EOL;
				$postFields .= 'Content-Type: application/json; charset=utf-8'.PHP_EOL;
				$postFields .= 'Content-Disposition: form-data; name="messageEventDetail"'.PHP_EOL;
				$postFields .= PHP_EOL;
				$postFields .= json_encode([
					'messageEventDetail' => [
						'eventCategoryCode' => 1,
						'messageDetail' => [
							'body' => $message,
						],
					],
				]).PHP_EOL;
	            $postFields .= '--'.$boundary.'--'.PHP_EOL.PHP_EOL;
				$headers = [];
				$endpoint = 'https://se-gmsg.np.community.playstation.net/groupMessaging/v1/threads'.'/'.$threadId.'/messages';
				curl_reset(self::$curlh);
				if( !is_null(self::$curlf) && false !== self::$curlf ) {
					curl_setopt_array(self::$curlh, [
						CURLOPT_VERBOSE => true,
						CURLOPT_STDERR => self::$curlf,
					]);
				}
				curl_setopt_array(self::$curlh, [
					CURLOPT_URL => $endpoint,
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => $postFields,
					CURLOPT_MAXREDIRS => 0,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => [
						'Cache-Control: no-cache',
						'Accept-Language: en-GB',
						'User-Agent: '.self::USER_AGENT,
						'Referer: https://my.playstation.com/',
						'Authorization: Bearer '.self::$state['access_token'],
						'Content-Type: multipart/form-data; boundary="'.$boundary.'"',
					],
					CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
						$len = strlen($header);
						$header = explode(':', $header, 2);
						if(count($header) < 2) {	// ignore invalid headers
							return $len;
						}
						$headers[strtolower(trim($header[0]))][] = trim($header[1]);
						return $len;
					},
				]);
				$response = curl_exec(self::$curlh);
				@file_put_contents(self::MESSAGE_FILE, '» '.self::$threads[$threadId]['onlineId'].': '.$message.PHP_EOL, FILE_APPEND);
				$result = self::handleJsonResponse($response, $headers, __LINE__, __METHOD__);
				if( false !== $result ) {
					array_shift(self::$sending);
				}
			}
		});

		self::$timers['check_messages'] = self::$loop->addPeriodicTimer(self::CHECK_MESSAGES, function() /* use (self::$loop) */ {
			if( !is_null(self::$rateLimited) && time() < self::$rateLimited ) return;
			if( time() >= self::$state['access_token_expires'] ) return;
			if( count(self::$messages) > 0 ) {
				self::debug('New message detected', __LINE__, basename(__FILE__));
				$m = array_shift(self::$messages);
				@file_put_contents(self::MESSAGE_FILE, '« '.$m['sender']['onlineId'].': '.$m['messageDetail']['body'].PHP_EOL, FILE_APPEND);
				self::$events['message']($m);
			}
		});

		self::$timers['check_unread'] = self::$loop->addPeriodicTimer(self::CHECK_UNREAD, function() /* use (self::$loop) */ {
			if( !is_null(self::$rateLimited) && time() < self::$rateLimited ) return;
			if( time() >= self::$state['access_token_expires'] ) return;
			if( count(self::$check) > 0 ) {
				$tId = reset(self::$check);
				self::debug('Checking thread '.$tId.' for messages', __LINE__, basename(__FILE__));
				$t = self::thread([
					'threadId' => $tId,
					'sinceEventIndex' => self::$threads[$tId]['sinceEventIndex'] ?? null,
				]);
				if( false !== $t ) {
					$updAcct = false;
					$topDate = null;
					$lastIdx = null;
					foreach( $t['threadEvents'] as $te ) {
						if( $te['messageEventDetail']['postDate'] >= self::$threads[$t['threadId']]['threadModifiedDate'] ) {
							if( is_null($topDate) || $te['messageEventDetail']['postDate'] > $topDate ) {
								$topDate = $te['messageEventDetail']['postDate'];
								$lastIdx = $te['messageEventDetail']['eventIndex'];
							}
							$tt = $te['messageEventDetail'];
							$poststamp = strtotime($tt['postDate']);
							$postage = time() - $poststamp;
							if( $postage > self::MAX_MESSAGE_AGE ) {
								self::debug('Ignoring message older than '.self::MAX_MESSAGE_AGE.' seconds', __LINE__, basename(__FILE__));
							} elseif( !in_array($tt['sender']['accountId'], self::$ignore) ) {
								$tt['threadId'] = $t['threadId'];
								$tt['threadType'] = $t['threadType'];
								self::$messages[] = $tt;
								if( $t['threadType'] == 0 ) {
									if( !isset(self::$threads[$t['threadId']]['accountId']) ) {
										self::$threads[$t['threadId']]['accountId'] = $tt['sender']['accountId'];
										self::$threads[$t['threadId']]['onlineId'] = $tt['sender']['onlineId'];
										$updAcct = true;
									} elseif( self::$threads[$t['threadId']]['onlineId'] != $tt['sender']['onlineId'] ) {
										self::$threads[$t['threadId']]['onlineId'] = $tt['sender']['onlineId'];
										$updAcct = true;
									}
								}
							} else {
								self::debug('Ignoring message from account id '.$tt['sender']['accountId'], __LINE__, basename(__FILE__));
							}
						}
					}
					if( isset(self::$threads[$t['threadId']]['threadNextModifiedDate']) && self::$threads[$t['threadId']]['threadNextModifiedDate'] > $topDate ) {
						$topDate = self::$threads[$t['threadId']]['threadNextModifiedDate'];
					}
					if( !is_null($topDate) ) {
						self::$threads[$t['threadId']]['threadModifiedDate'] = $topDate;
						$lastpost = strtotime($topDate);
						$lastage = time() - $lastpost;
						if( $lastage > self::MAX_MESSAGE_AGE ) {
							$t['endOfThreadEvent'] = true;
						}
					}
					if( !is_null($lastIdx) ) {
						self::$threads[$t['threadId']]['sinceEventIndex'] = $lastIdx;
					}
					if( !is_null($topDate) || !is_null($lastIdx) || $updAcct ) {
						self::saveThreads();
					}
					if( $t['endOfThreadEvent'] === true || is_null($lastIdx) ) {
						array_shift(self::$check);
					}
				}
			}
		});

		self::$timers['check_threads'] = self::$loop->addPeriodicTimer(self::CHECK_THREADS, function() /* use (self::$loop) */ {
			if( !is_null(self::$rateLimited) && time() < self::$rateLimited ) return;
			if( time() >= self::$state['access_token_expires'] ) return;
			self::debug('Checking threads @ '.__LINE__.' in '.basename(__FILE__));
			$t = self::threads();
			if( false !== $t ) {
				foreach($t['threads'] as $thread) {
					if( !isset(self::$threads[$thread['threadId']]) ) {
						self::$threads[$thread['threadId']] = $thread;
						self::$check[] = $thread['threadId'];
					} else {
						if( !in_array($thread['threadId'], self::$check) && $thread['threadModifiedDate'] > self::$threads[$thread['threadId']]['threadModifiedDate'] ) {
							self::$threads[$thread['threadId']]['threadNextModifiedDate'] = $thread['threadModifiedDate'];
							self::$check[] = $thread['threadId'];
							self::$events['threadupdated']($thread['threadId']);
						}
					}
				}
			}
		});
	}

	//
	//	Method to hook up events to external functions
	//
	public function on($event, $func) {
		if( isset(self::$events[$event]) && is_callable($func) ) {
			self::$events[$event] = $func;
		}
	}

	//
	//	Generic function to handle what is supposed
	//	to be returned JSON data from PSN
	//
	private function handleJsonResponse($response, $headers, $line, $function) {
		$data = json_decode($response, true);
		if( !is_null($data) ) {
			if( isset($data['error']) ) {
				self::psnError($response, $headers, $data, $line, $function);
				return false;
			}
			return $data;
		} else {
			self::debug('Failed decoding JSON', $line, $function);
			self::debug($response);
		}
		return false;
	}

	//
	//	We have an error code from PSN, special care
	//
	private function psnError($response, $headers, $json, $line, $function) {
		switch( $json['error']['code'] ) {

			//
			//	Access token expired, bump the expiration back in time
			//
			case 2122242:
				self::$state['access_token_expires'] = time() - 1;
				self::debug('Expired access token', __LINE__, __METHOD__);
				break;

			//
			//	Rate limit exceeded, pause until given time
			//
			case 2122251:
				if( isset($headers['x-ratelimit-next-available']) ) {
					echo 'Found "x-ratelimit-next-available", rate limited until '.date('H:i:s', $headers['x-ratelimit-next-available'][0]).PHP_EOL;
				}
				self::$rateLimited = $headers['x-ratelimit-next-available'][0];
				self::debug('Rate limit exceeded', __LINE__, __METHOD__);
				break;

			//
			//	Unknown error code, log it
			//
			default:
				self::debug($headers, $line, $function);
				self::debug($response, $line, $function);
				self::debug($json, $line, $function);
				break;

		}
	}

	//
	//	Debug to debug log file
	//
	private function debug($msg, $line = null, $function = null, $file = true, $echo = true) {
		if( is_array($msg) ) {
			$msg = print_r($msg, true);
		} elseif( is_string($msg) ) {
		} elseif( is_numeric($msg) ) {
		} elseif( is_bool($msg) ) {
			$msg = (($msg) ? 'TRUE' : 'FALSE');
		} elseif( is_null($msg) ) {
			$msg = 'NULL';
		} else {
			$msg = null;
		}
		if( !is_null($msg) ) {
			$timestamp = microtime(true);
			$timestamp = date('H:i:s', floor($timestamp)).substr(round($timestamp-floor($timestamp), 4).'0000', 1, 5);
			$msg = $timestamp.'› '.$msg;
			if( !is_null($line) && !is_null($function) ) {
				$msg .= ' @ '.$line.' in '.$function;
			} elseif( !is_null($line) ) {
				$msg .= ' @ '.$line;
			} elseif( !is_null($function) ) {
				$msg .= ' in '.$function;
			}
			$msg .= PHP_EOL;
			if( $echo ) echo $msg;
			if( $file ) @file_put_contents(self::DEBUG_FILE, $msg, FILE_APPEND);
		}
	}

	//
	//	Generate random string of given length
	//
	private function randstr($length = 24) {
		$s = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$l = strlen($s);
		$r = '';
		for( $i = 0; $i < $length; $i++ ) {
			$c = $s[mt_rand(0, $l - 1)];
			$r .= $c;
		}
		return $r;
	}

}

?>