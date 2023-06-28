<?php

// BLUF 4.5
// Social Link Update Tracker library
// A tool to allow for automated creation, update and removal of links
// between social sites

// Version 1.0
// Date: 2023-01-16
// Initial commit

require_once('common/v4database.php') ;
require_once('core/config.php') ;

// SLUT constants are defined in core/config:
// SLUT_LOCAL_SERVICE_NAME is the name of our service, eg 'BLUF'
// SLUT_LOCAL_SERVICE_ID is a guid v4 identfier for our service
// SLUT_SIGNING_KEY is the path to our private key file for signing SLUT requests

// Our database holds information about the peers with which we connect
/*

CREATE TABLE `slut_peers` (
  `service_id` char(36) NOT NULL DEFAULT '',
  `service_name` char(255) DEFAULT NULL,
  `webhook_url` char(255) DEFAULT NULL,
  `request_url` char(255) DEFAULT NULL,
  `request_method` enum('get','post','disabled') NOT NULL DEFAULT 'get',
  `public_key` text DEFAULT NULL,
  `icon` char(50) DEFAULT NULL,
  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

*/

// We also have a table with linked user information
/*

CREATE TABLE `slut_links` (
  `userid` char(36) NOT NULL DEFAULT '',
  `service_id` char(36) NOT NULL DEFAULT '',
  `remote_id` char(36) DEFAULT NULL,
  `displayname` char(100) DEFAULT NULL,
  `url` char(255) DEFAULT NULL,
  `last_update` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  KEY `userid` (`userid`),
  KEY `service_id` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

*/

// And a log of messages received
/*

CREATE TABLE `slut_log` (
  `message_id` char(36) NOT NULL DEFAULT '',
  `timestamp` timestamp NULL DEFAULT NULL,
  `message` text DEFAULT NULL,
  PRIMARY KEY (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

*/

// function definitions
function verify_payload($pay, $sig)
{
	global $v4bluf ;
	// How to verify the payload:

	// start by looking up $pay->origin->serviceid in the database, to see if we have a relationship
	// get the public key at the same time
	// return false if not found
	$v1 = $v4bluf->stmt_init() ;
	$v1->prepare("SELECT public_key FROM slut_peers WHERE service_id = ?") ;
	$v1->bind_param('s', $pay->origin->serviceid) ;
	$v1->execute() ;
	$v1->store_result() ;

	if ($v1->num_rows == 0) {
		// you might want to add some error / security reporting here
		return false ; // no record found
	}

	$v1->bind_result($publickey_text) ;
	$v1->fetch() ;

	// next, check $pay->destination->serviceid to make sure the message is intended for us
	// return false if no match
	if ($pay->destination->serviceid != SLUT_LOCAL_SERVICE_ID) {
		// you might want to add some error / security reporting here
		return false ;
	}

	// for IP access control, use $pay->origin->serviceid to look up allowed IP addresses
	// compare them to $_SERVER['REMOTE_ADDR'], return false if no match
	// BUT note that for a link request, this may need to be skipped, as request
	// could come from a user's device, not a server

	// for protection against duplicates, check $pay->messageid against previous requests
	// return false if messageid is found

	// use  public key of $oay->original->serviceid to verify that the signature
	// matches the payload
	// return false if signature not verified
	$result = openssl_verify($pay, $sig, $publickey) ;

	return ($result == 1) ;
}

function log_slut_message($message)
{
	global $v4bluf ;
	$ins = $v4bluf->stmt_init() ;
	$ins->prepare('INSERT INTO slut_log SET messageid = ?, timestamp = ?, message = ?') ;
	$ins->bind_param('sis', $message->messageid, $message->timestamp, json_encode($message, JSON_UNESCAPED_SLASHES)) ;
	$ins->execute() ;
}

function create_social_link($service, $user)
{
	global $v4bluf ;
	$cr = $v4bluf->stmt_init() ;
	$cr->prepare('INSERT INTO slut_links SET userid = ?, service_id = ?, remote_id =?, displayname = ?, url = ?') ;
	$cr->bind_param('sss', $user->destinationid, $service, $user->originid, $user->displayname, $user->url) ;
	$cr->execute() ;
}

function update_social_link($service, $user)
{
	global $v4bluf ;
	$ud = $v4bluf->stmt_init() ;
	$ud->prepare('UPDATE slut_links SET userid = ?, service_id = ?, remote_id =?, displayname = ?, url = ?') ;
	$ud->bind_param('sss', $user->destinationid, $service, $user->originid, $user->displayname, $user->url) ;
	$ud->execute() ;
}

function delete_social_link($service, $user)
{
	global $v4bluf ;
	$de = $v4bluf->stmt_init() ;
	$de->prepare("DELETE FROM slut_links WHERE serviceid = ? AND userid = ?") ;
	$de->bind_param('ss', $service, $user->destinationid) ;
	$de->execute() ;
}

function delete_link_request($service, $user)
{
	// not implemented on BLUF
	// This would need a table of pending requests for background delivery
	// But we're planning to use GET requests to do it interactively
}

function create_id()
{
	// create a v4 uuid
	$data = openssl_random_pseudo_bytes(16);
	$data[6] = chr(ord($data[6]) & 0x0f | 0x40);	// set version to 0100
	$data[8] = chr(ord($data[8]) & 0x3f | 0x80);	// set bits 6-7 to 10
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function slut_interactive_link($service, $user)
{
	// generate a URL that will start the link process on a remote service
	// returns false if the service does not support linking via GET
	global $v4bluf ;

	$rs = $v4bluf->stmt_init() ;
	$rs->prepare("SELECT service_name, request_url FROM slut_peers WHERE service_id = ? AND request_method = 'get'") ;
	$rs->bind_param('s', $service) ;
	$rs->execute() ;

	$rs->store_result() ;

	if ($rs->num_rows != 1) {
		return false ; // peer is not listed or does not support interactive linking
	}
	$rs->bind_result($servicename, $requesturl) ;
	$rs->fetch() ;

	$message = new stdClass() ;
	$message->messageid = create_id() ;
	$message->timestamp = time() ;
	$message->origin->servicename = SLUT_LOCAL_SERVICE_NAME ;
	$message->origin->serviceid = SLUT_LOCAL_SERVICE_ID ;

	$message->destination->servicename = $servicename ;
	$message->destination->serviceid = $service ;

	$message->action = 'link' ;

	$message->user = $user ; // an object with displayname, originid and url properties

	$json_payload = json_encode($message, JSON_UNESCAPED_SLASHES) ;

	// create a signature for the payload
	$pkdata = file_get_contents(SLUT_SIGNING_KEY) ;
	$privatekey = openssl_pkey_get_private($pkdata);
	$signature = '' ;
	openssl_sign($json_payload, $signature, $privatekey) ;

	return $requesturl . '?r=' . base64_encode($json_payload) . '&s=' . base64_encode($signature) ;
}

function send_slut_request($action, $service, $user)
{
	// send a request to a remote server
	global $v4bluf ;

	$message = new stdClass() ;
	$message->messageid = create_id() ;
	$message->timestamp = time() ;
	$message->origin->servicename = SLUT_LOCAL_SERVICE_NAME ;
	$message->origin->serviceid = SLUT_LOCAL_SERVICE_ID ;

	$rs = $v4bluf->stmt_init() ;
	$rs->prepare("SELECT service_name, webhook_url FROM slut_peers WHERE service_id = ?") ;
	$rs->bind_param('s', $service) ;
	$rs->execute() ;

	$rs->store_result() ;

	if ($rs->num_rows != 1) {
		return false ; // peer is not listed
	}

	$rs->bind_result($servicename, $webhook) ;
	$rs->fetch() ;

	$rs->close() ;

	$message->destination->servicename = $servicename ;
	$message->destination->serviceid = $service ;

	$message->user = $user ;

	$json_payload = json_encode($message, JSON_UNESCAPED_SLASHES) ;

	if (SLUT_TEST_MODE) {
		mail(ADMIN_EMAIL . '@' . EMAIL_DOMAIN, 'SLUT payload for ' . $webhook, $json_payload) ;
		return true ;
	} else {
		// create a signature for the payload
		$pkdata = file_get_contents(SLUT_SIGNING_KEY) ;
		$privatekey = openssl_pkey_get_private($pkdata);
		$signature = '' ;
		openssl_sign($json_payload, $signature, $privatekey) ;


		// now POST the webhook message
		$c = curl_init() ;
		curl_setopt_array($c, [
		  CURLOPT_URL => $webhook,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 30,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_POSTFIELDS => $json_payload,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_HTTPHEADER => [
			"Content-Type: application/json;charset=utf-8",
			"Slut-Signature: " . base64_encode($signature)
		  ],
		]);

		$response = curl_exec($c) ;
		$result = curl_getinfo($c, CURLINFO_RESPONSE_CODE) ;
		// return true if we get a code 200, otherwise false
		return ($result == '200') ;
	}
}
