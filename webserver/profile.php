<?php

session_start();

header("Content-Type: application/json");

// Include required RabbitMQ files
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

// Check if the user has an active PHP session
if (!isset($_SESSION["sessionKey"]))
{
    http_response_code(401);

    echo json_encode([
        "status" => "error",
        "message" => "No active session found"
    ]);

    exit();
}

// Create RabbitMQ request
$request = [
    "type" => "get_collection",
    "sessionKey" => $_SESSION["sessionKey"],
    "user" => $_SESSION["user"] ?? ""
];

try
{
    // Connect to Database VM through RabbitMQ
    $client = new rabbitMQClient(
        "testRabbitMQ.ini",
        "testServer"
    );

    // Send request to Database VM
    $response = $client->send_request($request);

    // Check response
    if (!is_array($response))
    {
        http_response_code(502);

        echo json_encode([
            "status" => "error",
            "message" => "Invalid response from Database VM"
        ]);

        exit();
    }

    // Return database response to JavaScript
    echo json_encode($response);
}
catch (Throwable $e)
{
    error_log("Profile RabbitMQ error: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Unable to retrieve music collection"
    ]);
}

?>