<?php

	//
	//	Quick setup
	//
	//	First time running this, call $kvick->authenticate with a valid NPSSO code.
	//	This should only be needed the first time, or when the refresh token
	//	expires (something like 60 days or so). When that happens, you need to
	//	re-authenticate() with a new NPSSO code.
	//
	//	When the authentication is done, the class will save "kvickpsn.json" to
	//	disk, containing information about the npsso code, refresh tokens,
	//	access tokens, expiration of the tokens and so on.
	//	DON'T SHARE THAT FILE! It allows someone else to log in to that PSN id!
	//
	//	On first run, it will run through all the threads that the logged in
	//	user is "subscribed" to, and save them in a JSON file. Anything older
	//	than 300 seconds is ignored (see kvickpsn.php consts).
	//
	//	Uses a React\EventLoop to perform regular checks (modify kvickpsn.php
	//	to change intervals if needed). Will also kick back a few events that
	//	you can listen to, mainly the 'message' event (see example below),
	//	but also a few others ('refreshneeded' should notify you before a new
	//	NPSSO code is needed).
	//
	//	React\EventLoop normally installed with composer, i.e.:
	//		composer require react/event-loop react/http
	//
	//	The example below listens and responds to a couple of commands: !quit, !uptime and !test
	//	The example below also stores the entire message received on disk
	//	Will also store any attached images on disk
	//

	date_default_timezone_set('Europe/Stockholm');

	require_once 'vendor/autoload.php';
	require_once 'kvickpsn.php';


	$loop = React\EventLoop\Factory::create();

	$init = time();
	$kvick = new KvickPSN([
		'loop' => $loop,
	]);

	//
	//	Log in using web browser like Chrome or Firefox, then
	//	visit https://ca.account.sony.com/api/v1/ssocookie
	//	to obtain your NPSSO code
	//
	//	Only needed on first run or if refresh token expired!
	//
	$kvick->authenticate('<INSERT YOUR NPSSO CODE HERE>');

	//
	//	Listen for received messages
	//
	$kvick->on('message', function($msg) use ($kvick, $init) {
		@file_put_contents('message-'.$msg['eventIndex'].'.txt', json_encode($msg, JSON_PRETTY_PRINT));
		switch( strtolower($msg['messageDetail']['body']) ) {
			case '!quit':
				$kvick->exit();
				break;
			case '!uptime':
				$kvick->sendMessage([
					'threadId' => $msg['threadId'],
					'message' => 'Uptime: '.secondsToTime(time()-$init),
				]);
				break;
			case '!test':
				$kvick->sendMessage([
					'threadId' => $msg['threadId'],
					'message' => 'Yes, I\'m still alive!',
				]);
				break;
			default:
				if( isset($msg['attachedMediaPath']) ) {
					$contenttype = null;
					$mediadata = $kvick->attachment($msg['attachedMediaPath'], $contenttype);
					if( false !== $mediadata && $contenttype != null ) {
						$filename = 'image-'.$msg['eventIndex'].'.';
						switch( $contenttype ) {
							case 'image/jpg':
							case 'image/jpeg':
								$filename .= 'jpg';
								break;
							case 'image/png':
								$filename .= 'png';
								break;
							case 'image/gif':
								$filename .= 'gif';
								break;
							default:
								if( substr($contenttype, 0, 6) == 'image/' ) {
									$filename .= substr($contenttype, 6);
								} else {
									$filename .= 'bin';
								}
								break;
						}
						file_put_contents($filename, $mediadata);
					}
				}
				echo ' » '.$msg['messageDetail']['body'].PHP_EOL;
				break;
		}
	});

	//
	//	Init our loop timers
	//
	$kvick->init();

	//
	//	And kick off the loop
	//
	$loop->run();
	die;

	function secondsToTime($seconds) {
		$dtF = new \DateTime('@0');
		$dtT = new \DateTime("@$seconds");
		return $dtF->diff($dtT)->format('%a days, %h hours, %i minutes and %s seconds.');
	}

?>