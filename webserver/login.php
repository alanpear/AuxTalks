<?php
session_start();

header('Content-Type: application/json');

// Includes the required files
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

error_log("[WEB VM] login.php called");


// Check if the request is JSON
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
	// Reads raw JSON string from HTTP POST request body
	$input = file_get_contents('php://input');

	error_log("[WEB VM] Raw php://input received: " . $input);

	// Converts JSON string into PHP array
	$request = json_decode($input, true);


	// Make sure JSON was decoded correctly
	if (!$request || !isset($request['type'])) {
		error_log("[WEB VM ERROR] JSON decoding failed or missing 'type'");

		echo json_encode([
			"message" => "Web VM: Invalid JSON or missing type"
		]);

		exit();
	}


	// Validate session request
	if ($request['type'] === 'validate_session') {
		if (!isset($_SESSION['sessionKey'])) {
			echo json_encode([
				"status" => "error",
				"message" => "No active session found"
			]);

			exit();
		}

		$request['sessionKey'] = $_SESSION['sessionKey'];
	}


	// Create RabbitMQ client
	$client = new rabbitMQClient(
		"testRabbitMQ.ini",
		"testServer"
	);


	// Send request to RabbitMQ
	$response = $client->send_request($request);


	// If login was successful and RabbitMQ returned a session key,
	// store it in the PHP session
	if (
		$request['type'] === 'login' &&
		is_array($response) &&
		isset($response['sessionKey'])
	) {
		$_SESSION['sessionKey'] = $response['sessionKey'];
	}


	// Send RabbitMQ response back to browser
	echo json_encode($response);

	exit();
}


//Includes the required files
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

error_log("[WEB VM] login.phpcalled");

//Reads Raw JSON string from HTTP POST request body and decodes the json string into an array
$input = file_get_contents('php://input');
error_log("[WEB VM] Raw php://input received: " . $input);

$request = json_decode($input, true);

if (!$request || !isset($request['type'])) {
	error_log("[WEB VM ERROR] JSON decoding failed or missing 'type'");
	echo json_encode(["message" => "Web VM: Invalid JSON or missing type"]);
	exit();
}

if ($request['type'] === 'validate_session') {
	if (!isset($_SESSION['sessionKey'])) {
		echo json_encode(["status" => "error", "message" => "no active session found"]);
		exit();
	}
	$request['sessionKey'] = $_SESSION['sessionKey'];
}

$client = new rabbitMQClient("testRabbitMQ.ini", "testServer");
$response = $client->send_request($request);

if ($request['type'] === 'login' && isset($response['status']) && $response['status'] === 'success') {
	if (isset($response['sessionKey'])) {
		$_SESSION['sessionKey'] = $response['sessionKey'];
	}
	if (isset($response['user'])) {
		$_SESSION['user'] = $response['user'];
	}
}

echo json_encode($response);

// Handle logout
if ($request['type'] === 'logout') {
    if (isset($_SESSION['sessionKey'])) {
        $request['sessionKey'] = $_SESSION['sessionKey'];
    }

    // Tell the database via RabbitMQ to delete the token
    $client = new rabbitMQClient("testRabbitMQ.ini", "testServer");
    $response = $client->send_request($request);

    // Destroy local PHP session completely
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    echo json_encode(["status" => "success", "message" => "Logged out successfully"]);
    exit(0);
}

//non used code
/*
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
 */

exit(0);
