<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Content-Type: application/json");

$lookupDirs = [
    __DIR__,
    __DIR__ . '/..',
    __DIR__ . '/../..',
    '/var/www/html'
];

$libLoaded = false;
$iniPath = 'testRabbitMQ.ini';

foreach ($lookupDirs as $dir) {
    if (file_exists("$dir/rabbitMQLib.inc")) {
        require_once("$dir/path.inc");
        require_once("$dir/get_host_info.inc");
        require_once("$dir/rabbitMQLib.inc");
        if (file_exists("$dir/testRabbitMQ.ini")) {
            $iniPath = "$dir/testRabbitMQ.ini";
        }
        $libLoaded = true;
        break;
    }
}

if (!$libLoaded) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "RabbitMQ libraries not found."]);
    exit();
}

$input = file_get_contents("php://input");
$body = json_decode($input, true);

$query = trim($body["query"] ?? ($_GET["query"] ?? ($_GET["q"] ?? "")));

if ($query === "") {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Please enter an album name."
    ]);
    exit();
}

try {
    $client = new rabbitMQClient($iniPath, "testServer");

    $request = [
        "type"  => "search_music",
        "query" => $query,
        "page"  => 1
    ];

    $response = $client->send_request($request);

    if (!$response) {
        http_response_code(502);
        echo json_encode([
            "status"  => "error",
            "message" => "No response from Database server (AuxTalksQueue)."
        ]);
        exit();
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "RPC Error: " . $e->getMessage()
    ]);
}
?>
