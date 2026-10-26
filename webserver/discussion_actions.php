<?php
session_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$currentUser = $_SESSION['user'] ?? '';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if (empty($currentUser) && in_array($action, ['create_thread', 'add_post'], true)) {
    echo json_encode(["status" => "error", "message" => "Please log in to post."]);
    exit();
}

try {
    $client = new RabbitMQClient("testRabbitMQ.ini", "testServer");

    switch ($action) {
        case 'get_categories':
            $res = $client->send_request(['type' => 'get_forum_categories']);
            break;

        case 'get_threads':
            $res = $client->send_request([
                'type' => 'get_threads',
                'category_id' => (int)($_GET['category_id'] ?? 0)
            ]);
            break;

        case 'create_thread':
            $res = $client->send_request([
                'type' => 'create_thread',
                'user' => $currentUser,
                'category_id' => (int)($_POST['category_id'] ?? 0),
                'title' => $_POST['title'] ?? '',
                'body' => $_POST['body'] ?? '',
                'discogs_release_id' => (int)($_POST['discogs_release_id'] ?? 0)
            ]);
            break;

        case 'get_thread':
            $res = $client->send_request([
                'type' => 'get_thread',
                'thread_id' => (int)($_GET['id'] ?? 0)
            ]);
            break;

        case 'add_post':
            $res = $client->send_request([
                'type' => 'add_post',
                'user' => $currentUser,
                'thread_id' => (int)($_POST['thread_id'] ?? 0),
                'body' => $_POST['body'] ?? '',
                'parent_post_id' => (int)($_POST['parent_post_id'] ?? 0)
            ]);
            break;

            default:
                $res = ["status" => "error", "message" => "Unknown Action"];
    }

    if(!is_array($res)) {
        $res = ["status" => "error", "message" => "No Response From Database"];
    }
    echo json_encode($res);

} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => "Server.error:" . $e->getMessage()]);
}
?>