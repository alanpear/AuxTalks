<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Content-Type: application/json");

$lookupPaths = [
    __DIR__,
    __DIR__ . '/..',
    __DIR__ . '/../..',
    '/var/www/html'
];

$libLoaded = false;
$iniPath = 'testRabbitMQ.ini';

foreach ($lookupPaths as $path) {
    if (file_exists("$path/rabbitMQLib.inc")) {
        require_once("$path/path.inc");
        require_once("$path/get_host_info.inc");
        require_once("$path/rabbitMQLib.inc");
        if (file_exists("$path/testRabbitMQ.ini")) {
            $iniPath = "$path/testRabbitMQ.ini";
        }
        $libLoaded = true;
        break;
    }
}

if (!$libLoaded) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "RabbitMQ libraries not found on Web server."]);
    exit();
}

$rawInput = file_get_contents("php://input");
$postData = json_decode($rawInput, true);

$action = $postData['action'] ?? ($_GET['action'] ?? 'search');

try {
    $client = new rabbitMQClient($iniPath, "testServer");

    // Route: Search Music / Albums
    if ($action === 'search') {
        $query = trim($postData["query"] ?? ($_GET["query"] ?? ($_GET["q"] ?? "")));

        if ($query === "") {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Please enter an album or artist name."]);
            exit();
        }

        $request = [
            "type"  => "search_music",
            "query" => $query,
            "page"  => (int)($postData['page'] ?? ($_GET['page'] ?? 1))
        ];

        $response = $client->send_request($request);

        if (!$response) {
            http_response_code(502);
            echo json_encode(["status" => "error", "message" => "No response from Database server."]);
            exit();
        }

        echo json_encode($response);
        exit();
    }

    elseif ($action === 'details') {
        $releaseId = (int)($postData['discogs_release_id'] ?? ($_GET['id'] ?? 0));

        if ($releaseId <= 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Valid release ID is required."]);
            exit();
        }

        $request = [
            "type"               => "get_release",
            "discogs_release_id" => $releaseId
        ];

        $response = $client->send_request($request);

        if (!$response) {
            http_response_code(502);
            echo json_encode(["status" => "error", "message" => "No response from Database server."]);
            exit();
        }

        echo json_encode($response);
        exit();
    }

    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Unsupported action '$action'."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "RPC Error: " . $e->getMessage()]);
}
?>
