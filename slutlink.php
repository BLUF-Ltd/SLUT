<?php

// BLUF 4.5 SLUT link page
// Expects to be called with query parameters to create a link to another site
// using the Social Link Update Tracker protocol (SLUT)
// r = base64 encoded request
// s = base64 encoded signature
//
// This is an interactive process; the user will be prompted to log in, if
// necessary, and the the link request is displayed, if valid
// On acceptance, the link is accepted and a SLUT message will be delivered
// back to the originating site, and the link will appear on the BLUF profile

// Set up the BLUF session
// This handles the automatic password prompt, and redirects back to the page

session_start() ;

require_once('common/smarty.php') ;
if (! isset($_SESSION['language'])) {
	require('common/setlanguage.php');
} else {
	$language = $_SESSION['language'] ;
}
$bluf->assign('language', $language) ;
setlocale(LC_ALL, $language.'_'.strtoupper($language) . '.UTF-8');
require_once('common/v4database.php') ;
require_once('common/directories.php') ;
require_once('common/blufutils.php') ;
require_once('common/security.php') ;

// Now the real work starts
require_once('common/slutlib.php') ;


// Check we have the params we need
if (!isset($_REQUEST['r']) ||  !isset($_REQUEST['s'])) {
	// one of our parameters is missing
	$bluf->assign('message', 'To link your account, please start the process by following instructions on the other site') ;
	$bluf->display('sorry.tpl') ;
	exit ;
}

$payload = json_decode(base64_decode($_REQUEST['r'])) ;
$signature = base64_decode($_REQUEST['s']) ;

if (!verify_payload($payload, $signature) || ($payload->action != 'link')) {
	// payload could not be verified, or it's not a link request
	$bluf->assign('nessage', 'Sorry... we could not verify the link request. Please try again') ;
	$bluf->display('sorry.tpl') ;
	exit ;
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	// the user has confirmed the link request, so we can make an entry in the db
	// first we need the unique id for the user (called the serviceid in the BLUF db)
	$account = $v4bluf->stmt_init() ;
	$account->prepare('SELECT serviceid FROM memberAccounts WHERE memberid = ?') ;
	$account->bind_param('i', $_SESSION['blufid']) ;
	$account->execute() ;

	$account->store_result() ;
	$account->bind_result($uniqueid) ;
	$account->fetch() ;

	// create the info to send back to the originating service, for a two way link
	$destination = $payload->origin->serviceid ;
	$user = new stdObject() ;
	$user->displayname = get_cached_name($_SESSION['blufid']) ;
	$user->url = 'https://www.bluf.com/profiles/' . $_SESSION['blufid'] ;
	$user->originid = $uniqueid ;
	$user->destinationid = $payload->user->originid ;

	// and send a message back to the originator, with our unique id
	// displayname and url, to complete the two-way link

	if (send_slut_request('accept', $destination, $user) === false) {
		$result = 'error' ;
	} else {
		$result = 'ok' ;

		// add the info to the slut_links table locally
		$payload->user->destinationid = $uniqueid ;
		create_social_link($destination, $payload->user) ;
	}

	$bluf->assign('result', $result) ;
}

// set up some params to pass through to the page template
$bluf->assign('displayname', $payload->user->displayname) ;
$bluf->assign('service', $payload->origin->servicename) ;
$bluf->assign('r', $_REQUEST['r']) ;
$bluf->assign('s', $_REQUEST['s']) ;

$bluf->display('v4/slutlink.tpl') ;
