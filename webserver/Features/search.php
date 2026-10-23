<?php

header("Content-Type: application/json");

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

// Read JSON sent by JavaScript
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Check the search query
if (!is_array($data) ||
    !is_string($data["query"] ?? null) ||
    trim($data["query"]) === "") {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Please enter an album name"
    ]);

    exit();
}

// Create request for RabbitMQ
$request = [
    "type" => "search_album",
    "query" => trim($data["query"])
];

// Send request to DMZ worker
$client = new rabbitMQClient(
    "testRabbitMQ.ini",
    "dmzServer"
);

$response = $client->send_request($request);

// Return JSON to the browser
echo json_encode($response);

?>
