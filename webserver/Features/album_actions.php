<?php
session_start();
header('Content-Type: application/json');

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$currentUser = $_SESSION['user'] ?? '';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// Allow public view of album details and reviews; authenticate collection/rating actions
if (empty($currentUser) && in_array($action, ['add_to_collection', 'get_collection', 'add_review'])) {
    echo json_encode(["status" => "error", "message" => "Please log in first."]);
    exit();
}

$client = new rabbitMQClient("../../testRabbitMQ.ini", "testServer");

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
            'type'               => 'add_to_collection',
            'user'               => $currentUser,
            'discogs_release_id' => (int)($_POST['discogs_release_id'] ?? 0),
            'notes'              => $_POST['notes'] ?? '',
            'condition_grade'    => $_POST['condition_grade'] ?? 'VG+',
            'sleeve_condition'   => $_POST['sleeve_condition'] ?? 'VG+'
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

    case 'add_review':
        $res = $client->send_request([
            'type'               => 'add_review',
            'user'               => $currentUser,
            'discogs_release_id' => (int)($_POST['discogs_release_id'] ?? 0),
            'rating'             => (float)($_POST['rating'] ?? 0.0),
            'review_text'        => !empty($_POST['review_text']) ? $_POST['review_text'] : null,
            'item_type'          => 'album',
            'physical_format'    => $_POST['physical_format'] ?? 'Vinyl'
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
        echo json_encode(["status" => "error", "message" => "Invalid action."]);
        break;
}
?>