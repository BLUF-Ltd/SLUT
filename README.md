# BLUF Social Link Update Tracker Protocol

This document outlines the BLUF SLUT protocol, which is designed to allow for automatic linking and 
back-linking between BLUF and other social platforms, providing visitors to a profile with confirmed
social links in both directions.

## Overview
The protocol uses JSON encoded messages, passed between servers, with an accompanying signature to provide
security. It allows for requesting an interactive login when a link is made from one service to another,
to verify account ownership, and then the rest of the work happens in the background.

### Example flow
+ A app users requests to link their account with their BLUF one
+ The app backend generates a link to the BLUF slutlink.php page and opens it in a browser
+ The BLUF site prompts the user to log in
+ The payload is extracted from the URL and verified
+ If verified, an acceptance message is sent back to the originating site
+ The BLUF site adds the requesting app to the list of social links on a profile
+ The backend for the requesting app uses the payload from BLUF to add the reciprocal link

## Data structures

### Database tables
This information is based on the BLUF reference implementation. Each peer has a list of systems with
which is exchanges information, called slut_peers

  `service_id` char(36) NOT NULL DEFAULT '',
  `service_name` char(255) DEFAULT NULL,
  `webhook_url` char(255) DEFAULT NULL,
  `request_url` char(255) DEFAULT NULL,
  `request_method` enum('get','post','disabled') NOT NULL DEFAULT 'get',
  `icon` char(50) DEFAULT NULL,
  `public_key` text DEFAULT NULL,
  
+ service_id is a UUID, unique for each site in the network
+ service_name is a friendly name to display to the user, like BLUF.com or Switched App
+ webhook_url is the endpoint to which machine-to-machine messages are POSTed
+ request_url is the URL to which users wanting to link interactively should be directed
+ request_method is the HTTP method used; in the BLUF implementation, only GET is supported at present
+ icon is the name of an icon file used to represent the service
+ public_key is the public key text for the service, to allow validation of requests

Links between profiles are stored in the slut_links table for the reference implementatin

  `userid` char(36) NOT NULL DEFAULT '',
  `service_id` char(36) NOT NULL DEFAULT '',
  `remote_id` char(36) DEFAULT NULL,
  `displayname` char(100) DEFAULT NULL,
  `url` char(255) DEFAULT NULL,
  `last_update` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),

+ userid is the unique user id of a member on the local system (in the case of BLUF, this is an internal value which, unlike BLUF numbers, cannot change)
+ service_id is the is of the service for this link, from the slut_peers table
+ remote_id is the unique id of the user on the remote service, analogous to the userid
+ displayname is how the remote name should be displayed, eg SubDirectory
+ url is a link that will open the remote profile, for example https://switchedapp.com/@SubDirectory
+ last_update is the time the entry was last update

Additionally, the reference implementation includes a slut_log table that simply logs all incoming messages, with a timestamp

### Message format
The message format is a simple object, so can be created for example by instantiating PHP's stdClass and then JSON encoding

In each message, there are destination and origin parts; the origin is always the server creating the message, and the destination is the peer.
When the JSON message has been created, a signature is created using the private key, which is then base64 encoded.

For messages passed via POST, the signature is added as a header Slut-Signature.

A typical message will look like this

	$message->messageid  					// a unique ID for the message
	$message->timestamp  					// UNIX timestamp, in seconds
	$message->origin->servicename 	 		// friendly name of the originating service
	$message->origin->serviceid		 		// Unique ID of the originating service
	$message->destination->servicename  	// friendly name of the peer
	$message->destination->serviceid  		// Unique ID of the peer
	$message->action 						// one of 'link','accept','reject','update','delete'
	$message->user 							// Details of the user, from the origin point of view
	
The user element contains

	user->displayname						// The name as it should be displayed in the UI, eg 'Subdirectory (330)'
	user->url								// The URL to open the user's profile, eg https://www.bluf.com/profiles/3
	user->originid							// The unique ID of the user on the origin site
	user->destinationid						// The unique ID of the user on the peer (not required in a link request
	
## Interactive linking to BLUF
To create a link from a service to BLUF, you need to construct a link message request, building the object with the properties as above. The action
property should be 'link' and the user->destinationid is not needed, as at this stage you don't know it.

The URL in the BLUF reference implementation is https://www.bluf.com/slutlink.php. After generating the JSON object, and creating a signature,
both are base64 encoded, and passed as parameters r (the request message) and s (signature) to the URL.

The BLUF site will prompt the user to log in, and then ask if they want to link. If they confirm, then the BLUF server will sent a message back to your
webhook URL, with the action 'accept' and the user element updated (ie your originid will now be in the destinationid, the url will be the URL for
the BLUF profile, and the originid will be the unique BLUF id of the account).

If a user's display name is updated, or their account is deleted, or they wish to remove the link, then the 'update' or 'delete' actions can be used
to automatically update details, by POSTing a JSON message to our webhook, presently https://bluf.com/hooks/slut-update.php 

## Validation
To validate an incoming request, perform the following steps:

+ Look up message->origin->serviceid in your list of peers; if not found, the request is invalid
+ If the first step passes, retrieve the public key of the peer; if unavailable, abort
+ Sanity check that message->destination->serviceid matches your own service id
+ Optionally check the originating IP address of the request against allow addresses for the peer (not implemented at BLUF)
+ Validate the message payload and signature using the public key

If all checks pass, the message is valid and can be processed accordingly.

You may (optionally) want to verify against your logs that specific messageid has not been processed before.

## Example JSON message
This is an example JSON message for a link request, from BLUF to Switched.app

	{
  		"messageid": "d86e76b5-35f0-46c6-9670-0e880179dd4f",
  		"timestamp": 1687865887,
  		"origin": {
			"servicename": "BLUF",
			"serviceid": "cda78899-a7dc-46b2-a279-1bcd48503381"
  		},
  		"destination": {
			"servicename": "Switched App",
			"serviceid": "9258bec7-95ac-11ed-abda-5d65e1397bbc"
  		},
  		"action": "link",
  		"user": {
			"displayname": "SubDirectory (3)",
			"originid": "b5bd64d7-b30d-11eb-83d1-d4ae52c98a27",
			"url": "https://bluf.com/profiles/3"
  		}
	}
