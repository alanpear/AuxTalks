#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$client = new rabbitMQClient("testRabbitMQ.ini", "dmzServer");
$res = $client->send_request(['action' => 'search_music', 'data' => ['query' => 'Kendrick Lamar', 'page' => 1]]);
var_dump($res);