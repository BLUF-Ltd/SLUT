<?php

// BLUF 4.5
// Support for Social Link Update Tracker protocol
// Handles notifications of updates from another SLUT-enabled system

// Get the JSON data from the input stream

require('common/slutlib.php') ;

$payload = file_get_contents('php://input') ;

$remoteip = $_SERVER['REMOTE_ADDR'] ;
$signature = base64_decode($_SERVER['HTTP_SLUT_SIGNATURE']) ;


if (! verify_payload($payload, $signature)) {
	http_response_code(401) ; // not authorised
	exit ;
}

$message = json_decode($payload) ;


switch ($message->action) {
	case 'accept' :
		create_social_link($message->origin->serviceid, $message->user) ; // add necessary local database entries
		break ;

	case 'update':
		update_social_link($message_origin->serviceid, $message->user) ; // update local database entries
		break ;

	case 'delete':
		delete_social_link($message->origin->serviceid, $message->user) ; // delete local database entries
		break ;

	case 'reject':
		delete_link_request($message->origin->serviceid, $message->user) ; // delete pending requests, if necessary
		break ;

	default:
		http_response_code(400) ; // bad request
		exit ;
}

// now log $message->messageid to prevent duplicates, if required
log_slut_message($message) ;

http_response_code(200) ; // ok
