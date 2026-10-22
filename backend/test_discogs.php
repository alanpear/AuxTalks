<?php
$ini = parse_ini_file("testRabbitMQ.ini", true);
$key = $ini['discogs']['CONSUMER_KEY'] ?? '';
$secret = $ini['discogs']['CONSUMER_SECRET'] ?? '';
$userAgent = $ini['discogs']['USER_AGENT'] ?? 'AuxTalksApp/1.0';

if (empty($key) || empty ($secret)) {
	die("ERROR: Please set both KEY and SECRET in testRabbitMQ.ini." . PHP_EOL);
}

$url = "https://api.discogs.com/database/search?" . http_build_query([
	'q' => 'Miles Davis',
	'type' => 'release',
	'per_page' => 1
]);

$ch = curl_init();
curl_setopt_array($ch, [
	CURLOPT_URL => $url,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_USERAGENT => $userAgent,
	CURLOPT_HTTPHEADER => [
		"Authorization: Discogs key={$key}, secret={$secret}",
		"Accept: application/json"
	],
	CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Status Code: " . $httpCode . PHP_EOL;

if ($error) {
	die("cURL Network Error: " . $error . PHP_EOL);
}

if ($httpCode === 429) {
	die("Rate limit reached (60 req/min)." . PHP_EOL);
}

$data = json_decode($response, true);
if (isset($data['results'][0]['title'])) {
	echo "SUCCESS " .  PHP_EOL;
	echo "First Seach Result: " . $data['results'][0]['title] . PHP_EOL;
} else {
	echo "FAILED " . $response . PHP_EOL;
}
