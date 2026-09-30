<?php

//Includes the required files
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

//Reads Raw JSON string from HTTP POST request body and decodes the json string into an array
$input = file_get_contents('php://input');
$request = json_decode($input, true);

$client = new rabbitMQClient("testRabbitMQ.ini", "testServer");
	
$response = $client->send_request($request);

echo json_encode($response);

if (!isset($_POST))
{
	$msg = "NO POST MESSAGE SET, POLITELY FUCK OFF";
	echo json_encode($msg);
	exit(0);
}
$request = $_POST;
$response = "unsupported request type, politely FUCK OFF";
switch ($request["type"])
{
	case "login":
		$response = "login, yeah we can do that";
	break;
}
echo json_encode($response);
exit(0);

?>
