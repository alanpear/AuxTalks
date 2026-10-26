<?php
header('Content-Type: application/json');
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['action'])) {
    echo json_encode(["status" => "error", "message" => "Invalid request payload"]);
    exit();
}

$client = new rabbitMQClient("../../testRabbitMQ.ini", "testServer");

$request = [
    'type'       => $input['action'],
    'uname'      => $input['user'] ?? '',
    'sessionKey' => $input['sessionKey'] ?? '',
    'data'       => $input['data'] ?? []
];

$response = $client->send_request($request);
echo json_encode($response);
exit();
?>