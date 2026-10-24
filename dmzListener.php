#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$ini = parse_ini_file(__DIR__ . "/testRabbitMQ.ini", true);
define('DISCOGS_KEY', $ini['discogs']['CONSUMER_KEY'] ?? '');
define('DISCOGS_SECRET', $ini['discogs']['CONSUMER_SECRET'] ?? '');
define('DISCOGS_USER_AGENT', $ini['discogs']['USER_AGENT'] ?? 'AuxTalksApp/1.0');

function callDiscogsAPI($endpoint, $params = [])
{
	$url = "https://api.discogs.com" . $endpoint;
	if (!empty($params)) {
		$url .= '?' . http_build_query($params);
	}

	$ch = curl_init();
	curl_setopt_array($ch, [
	CURLOPT_URL => $url,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_USERAGENT => DISCOGS_USER_AGENT,
	CURLOPT_HTTPHEADER => [
		"Authorization: Discogs key=" . DISCOGS_KEY . ", secret=" . DISCOGS_SECRET,
		"Accept: Application/json"
	],
	CURLOPT_TIMEOUT => 10

	]);

	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	if ($curlError) {
	return ["status" => "error", "message" => "cURL network error: " . $curlError];
	}

	if ($httpCode === 429) {
	return ["status" => "error", "message" => "Discogs rate limit reached (60 req/min). Pleas wait."];
	}

	if ($httpCode !== 200) {
	return ["status" => "error", "message" => "Discogs API returned HTTP " . $httpCode];
	}
	
	$data = json_decode($response, true);
	if (!$data) {
		return ["status" => "error", "message" => "Failed to parse Discogs JSON response"];
	}

	return ["status" => "success", "payload" => $data];
}

function processDMZRequest($request)
{
	$action = $request['action'] ?? ($request['type'] ?? 'UNKNOWN');
	$data = $request['data'] ?? [];

	echo " [DMZ] Processing task: " . $action . PHP_EOL;

	switch($action) {
		case 'search_music':
			$query = $data['query'] ?? '';
			$page = (int)($data['page'] ?? 1);

			$apiResult = callDiscogsAPI('/database/search' , [
				'q' => $query,
				'type' => 'release',
				'per_page' => 15,
				'page' => $page
			]);

			if ($apiResult['status'] === 'error') {
				return $apiResult;
			}

			$rawResults = $apiResult['payload']['results'] ?? [];
			$formattedResults = [];

			foreach ($rawResults as $item) {
				$formatStr = 'Standard';
				if (!empty($item['format'])) {
					$formatStr = is_array($item['format']) ? implode(', ', $item['format']) : (string)$item['format'];
				}

				$formattedResults[] = [
					'discogs_release_id' => $item['id'] ?? 0,
					'title'              => $item['title'] ?? 'Unknown Title',
					'year'               => $item['year'] ?? null,
					'format'             => $formatStr,
					'thumb'              => $item['thumb'] ?? ($item['cover_image'] ?? '')
				];
			}

			return [
				"status"  => "success",
				"message" => "Search results retrieved",
				"data"    => [
					"page"    => $page,
					"results" => $formattedResults
				]
			];
		case 'fetch_release_details':
			$releaseId = (int)($data['discogs_release_id'] ?? 0);
			if ($releaseId <= 0) {
				return ["status" => "error", "message" => "Invalid Discogs Release ID"];
			}

			$rel = callDiscogsAPI("/releases/{$releaseId}");
			if ($rel['status'] === 'error') {
				return $rel;
			}

			$stats = callDiscogsAPI("/marketplace/stats/{$releaseId}");
			$relData = $rel['payload'];
			$statsData = ($stats['status'] === 'success') ? $stats['payload'] : [];

			$tracklist = [];
			foreach ($relData['tracklist'] ?? [] as $t) {
				$tracklist[] = [
					'position' => $t['position'] ?? '',
					'title' => $t['title'] ?? 'Untitled Track',
					'duration' => !empty($t['duration']) ? $t['duration'] : 'N/A'
				];
			}

			$artistName = $relData['artists_sort'] ?? ($relData['artists'][0]['name'] ?? 'Unknown Artist');

			$formats = $relData['formats'] ?? [];
			$formatNames = is_array($formats) ? array_column($formats, 'name') : [];
			$formatSummary = !empty($formatNames) ? implode(', ', $formatNames) : 'Standard';

			return [
				"status" => "success",
				"data" => [
					"discogs_release_id" => $relData['id'],
					"title" => $relData['title'],
					"artist" => $artistName,
					"release_year" => $relData['year'] ?? null,
					"format_summary" => $formatSummary,
					"genres" => $relData['genres'] ?? [],
					"styles" => $relData ['styles'] ?? [],
					"labels" => array_column($relData['labels'] ?? [], 'name'),
					"tracklist" => $tracklist,
					"discogs_rating" => $relData['community']['rating']['average'] ?? 0.0,
					"discogs_rating_count" => $relData['community']['rating']['count'] ?? 0,
					"lowest_price" => $statsData['lowest_price']['value'] ?? null,
					"num_for_sale" => $statsData['num_for_sale'] ?? 0,
					"marketplace_url" => "https://www.discogs.com/sell/release/{$releaseId}"
				]
			];

		default:
			return ["status" => "error", "message" => "Unsupported action '{$action}' on DMZ"];
		}
	}

$server = new rabbitMQServer("testRabbitMQ.ini", "dmzServer");
echo "DMZ Worker running and listening on dmzQueue..." . PHP_EOL;
$server->process_requests('processDMZRequest');


