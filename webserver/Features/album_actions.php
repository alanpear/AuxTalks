<?php
session_start();
header('Content-Type: application/json');

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$currentUser = $_SESSION['user'] ?? '';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if (empty($currentUser) && $action !== 'get_album_details' && $action !== 'get_reviews') {
    echo json_encode(["status" => "error", "message" => "Authentication required."]);
    exit();
}

$client = new rabbitMQClient("testRabbitMQ.ini", "testServer");

switch ($action) {
    case 'get_album_details':
        $releaseId = (int)($_GET['id'] ?? 0);
        $res = $client->send_request([
            'type' => 'get_release',
            'discogs_release_id' => $releaseId
        ]);
        echo json_encode($res);
        break;

    case 'add_to_collection':
        $res = $client->send_request([
            'type' => 'add_to_collection',
            'user' => $currentUser,
            'discogs_release_id' => (int)($_POST['discogs_release_id'] ?? 0),
            'title' => $_POST['title'] ?? '',
            'artist' => $_POST['artist'] ?? ''
        ]);
        echo json_encode($res);
        break;

    case 'get_collection':
        $res = $client->send_request([
            'type' => 'get_collection',
            'user' => $currentUser
        ]);
        echo json_encode($res);
        break;

    case 'rate_album':
        $res = $client->send_request([
            'type' => 'rate_album',
            'user' => $currentUser,
            'discogs_release_id' => (int)($_POST['discogs_release_id'] ?? 0),
            'rating' => (int)($_POST['rating'] ?? 0)
        ]);
        echo json_encode($res);
        break;

    case 'review_album':
        $res = $client->send_request([
            'type' => 'review_album',
            'user' => $currentUser,
            'discogs_release_id' => (int)($_POST['discogs_release_id'] ?? 0),
            'review_text' => $_POST['review_text'] ?? ''
        ]);
        echo json_encode($res);
        break;

    case 'get_reviews':
        $releaseId = (int)($_GET['id'] ?? 0);
        $res = $client->send_request([
            'type' => 'get_album_reviews',
            'discogs_release_id' => $releaseId
        ]);
        echo json_encode($res);
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Unknown action."]);
        break;
}
?>