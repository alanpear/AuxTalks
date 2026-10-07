<?php
session_start();

header('Content-Type: application/json');

// Include required files
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

error_log("[WEB VM] login.php called");

// Read JSON file from HTTP
$input = file_get_contents('php://input');
error_log("[WEB VM] Raw php://input received: " . $input);

$request = json_decode($input, true);

// Verify JSON
if (!$request || !isset($request['type'])) {
    error_log("[WEB VM ERROR] JSON decoding failed or missing 'type'");
    echo json_encode(["status" => "error", "message" => "Web VM: Invalid JSON or missing type"]);
    exit();
}

if ($request['type'] === 'logout') {
    if (isset($_SESSION['sessionKey'])) {
        $request['sessionKey'] = $_SESSION['sessionKey'];
    }

    // Tell Database VM delete the session record
    $client = new rabbitMQClient("testRabbitMQ.ini", "testServer");
    $response = $client->send_request($request);

    // Destroy local PHP session
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();

    echo json_encode(["status" => "success", "message" => "Logged out successfully"]);
    exit(0);
}

if ($request['type'] === 'validate_session') {
    if (!isset($_SESSION['sessionKey'])) {
        echo json_encode(["status" => "error", "message" => "No active session found"]);
        exit();
    }
    // Attach the session key
    $request['sessionKey'] = $_SESSION['sessionKey'];
}

$client = new rabbitMQClient("testRabbitMQ.ini", "testServer");
$response = $client->send_request($request);

if (
    $request['type'] === 'login' &&
    is_array($response) &&
    isset($response['status']) &&
    $response['status'] === 'success'
) {
    if (isset($response['sessionKey'])) {
        $_SESSION['sessionKey'] = $response['sessionKey'];
    }
    if (isset($response['user'])) {
        $_SESSION['user'] = $response['user'];
    }
}

echo json_encode($response);
exit(0);
