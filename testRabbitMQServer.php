#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function doLogin($username,$password)
{
	// lookup username in databas
	$db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
	
	if($db->connect_error){
		return ["status" => "error", "message" => "database connection failed"];
	}

	// makes a hash of the password
	$inputHash = hash('sha256', $password);
	
	//prepares statement to get sent and checks if the statement was made
	$stmt = $db->prepare("SELECT password FROM users WHERE user = ?");
	if(!$stmt){
		$db->close();
		return ["status" => "error", "message" => "database prepare failed: " . $db->error];
	}

	$stmt->bind_param("s", $username); //add username passed through to the function to the statement
	$stmt->execute();
	$result = $stmt->get_result(); //returns the hash of the password

	if($row = $result->fetch_assoc()){
		if (hash_equals($row['password'], $inputHash)) { //compares the hash of the inputed password to the hash from the db
			$sessionKey = bin2hex(random_bytes(32)); // makes a random 32 bit session key

			$stmtSession = $db->prepare("INSERT INTO sessions (user, session_key, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"); 
			if($stmtSession) { //checks if the session stmt was created and then excutes the session creation
				$stmtSession->bind_param("ss", $username, $sessionKey);
				$stmtSession->execute();
				$stmtSession->close();
			}

			$stmt->close();
			$db->close();

			return [
				"status" => "success",
				"message" => "Loggin successful!",
				"sessionKey" => $sessionKey,
				"user" => $username
			];
		}
	}

	$stmt->close();
	$db->close();
	return ["status" => "error", "message" => "invalid username or password"];

    // check password
	//return true;
    //return false if not valid
}

function doRegister($first, $last, $email, $username, $password)
{
	$db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
	if($db->connect_error){
		return ["status" => "error", "message" => "Database connection failed"];
	}
	
	$checkStmt = $db->prepare("SELECT user, email FROM users WHERE user = ? OR email = ?");
	if(!$checkStmt){
		$db->close();
		return["status"=>"error", "message" => "database prepare failed:" . $db->error];
	}
	
	$checkStmt->bind_param("ss", $username, $email);
	$checkStmt->execute();
	$checkResult = $checkStmt->get_result();
	
	if($existing = $checkResult->fetch_assoc()){
		$checkStmt->close();
		$db->close();

		if ($existing['user'] === $username){
			return["status"=>"error", "message"=>"username already exists."];
		}else{
			return["status"=>"error", "message"=>"email already registered"];
		}
	}
	
	$checkStmt->close();

	$hash = hash('sha256', $password);

	$stmt = $db->prepare("INSERT INTO users (first_name, last_name, email, user, password, dateCreated) VALUES (?, ?, ?, ?, ?, NOW())");
	if(!$stmt){
		$err=$db->error;
		$db->close();
		return ["status" => "error", "message" => "Database prepare failed: " . $err];
	}
	$stmt->bind_param("sssss", $first, $last, $email, $username, $hash);

	if ($stmt->execute()){
		$stmt->close();
		$db->close();
		return["status" => "success", "message" => "Registration completed!"];
	}

		$stmt->close();
		$db->close();
		return ["status" => "error", "message" => "Username or email already exists."];

}

function handleSearchMusic($query, $page = 1) {
    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    if ($db->connect_error) return ["status" => "error", "message" => "Database offline"];

    //Check local cached_releases
    $term = "%" . $query . "%";
    $stmt = $db->prepare("SELECT discogs_release_id, title, artist, release_year, format_summary FROM cached_releases WHERE title LIKE ? OR artist LIKE ? LIMIT 15");
    $stmt->bind_param("ss", $term, $term);
    $stmt->execute();
    $res = $stmt->get_result();

    $cached = [];
    while ($row = $res->fetch_assoc()) {
        $cached[] = $row;
    }
    $stmt->close();

    if (count($cached) >= 3) {
        $db->close();
        return [
            "status" => "success",
            "source" => "database_cache",
            "data"   => ["page" => $page, "results" => $cached]
        ];
    }

    //Cache Miss: Forward request to DMZ using persistent client
    echo " [DB] Cache miss for '$query'. Forwarding to DMZ..." . PHP_EOL;
    $dmzClient = new rabbitMQClient("testRabbitMQ.ini", "dmzServer");
    $dmzRes = $dmzClient->send_request([
        'action' => 'search_music',
        'data'   => ['query' => $query, 'page' => $page]
    ]);

    //Cache results in MySQL
    if (isset($dmzRes['status']) && $dmzRes['status'] === 'success') {
        $resultsToCache = $dmzRes['data']['results'] ?? ($dmzRes['results'] ?? []);
        if (!empty($resultsToCache)) {
            $ins = $db->prepare("INSERT IGNORE INTO cached_releases (discogs_release_id, title, artist, release_year, format_summary) VALUES (?, ?, ?, ?, ?)");
            foreach ($resultsToCache as $item) {
                $year = !empty($item['year']) ? (int)$item['year'] : null;
                $format = $item['format'] ?? 'Standard';
                $ins->bind_param("issis", $item['discogs_release_id'], $item['title'], $query, $year, $format);
                $ins->execute();
            }
            $ins->close();
        }
    }

    $db->close();
    return $dmzRes;
}

function handleGetRelease($releaseId) {
    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    if ($db->connect_error) return ["status" => "error", "message" => "Database offline"];

    $stmt = $db->prepare("SELECT * FROM cached_releases WHERE discogs_release_id = ?");
    $stmt->bind_param("i", $releaseId);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        // Only return from cache if details (tracklist) exist
        if (!empty($row['tracklist'])) {
            $row['genres'] = json_decode($row['genres'], true);
            $row['tracklist'] = json_decode($row['tracklist'], true);
            $stmt->close();
            $db->close();
            return ["status" => "success", "source" => "database_cache", "data" => $row];
        }
    }
    $stmt->close();

    // Cache Miss -> Request from DMZ using persistent client
    echo " [DB] Cache miss for Release ID $releaseId. Forwarding to DMZ..." . PHP_EOL;
    $dmzClient = new rabbitMQClient("testRabbitMQ.ini", "dmzServer");
    $dmzRes = $dmzClient->send_request([
        'action' => 'fetch_release_details',
        'data'   => ['discogs_release_id' => $releaseId]
    ]);

    if (isset($dmzRes['status']) && $dmzRes['status'] === 'success') {
        $d = $dmzRes['data'];
        $genres = json_encode($d['genres'] ?? []);
        $tracks = json_encode($d['tracklist'] ?? []);
        $ins = $db->prepare("INSERT INTO cached_releases (discogs_release_id, title, artist, release_year, format_summary, genres, tracklist, lowest_price, marketplace_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE genres = VALUES(genres), tracklist = VALUES(tracklist), lowest_price = VALUES(lowest_price)");
        $ins->bind_param("ississsds", $d['discogs_release_id'], $d['title'], $d['artist'], $d['release_year'], $d['format_summary'], $genres, $tracks, $d['lowest_price'], $d['marketplace_url']);
        $ins->execute();
        $ins->close();
    }
    $db->close();
    return $dmzRes;
}


function doLogout($sessionKey){
	if(empty($sessionKey)){
		return["status"=>"error", "message"=>"no session key provided"];
	}

	$db = new mysqli('localhost','admin', 'AuxTalks', 'AuxTalks');
	if($db->connect_error){
		return["status"=>"error", "message"=>"database connection failed"];
	}

	$stmt=$db->prepare("DELETE FROM sessions WHERE session_key = ?");
	$stmt->bind_param("s", $sessionKey);
	$stmt->execute();
	$stmt->close();
	$db->close();

	return["status"=>"success", "message"=>"session deleted from database"];
}

function validateSession($sessionKey){
	$db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
	if($db->connect_error){
		return ["status" => "error", "message" => "database connection failed"];
	}

	//verify key exists and is not expired
	$stmt = $db->prepare("SELECT user FROM sessions WHERE session_key = ? AND expires_at > NOW()");
	$stmt->bind_param("s", $sessionKey);
	$stmt->execute();
	$result = $stmt->get_result();

	if($row = $result->fetch_assoc()) {
		$username = $row['user'];
		$stmt->close();
		$db->close();
		return[
			"status" => "success",
			"message" => "Session Valid",
			"user" => $username
		];
	}

	$stmt->close();
	$db->close();
	return["status" => "error", "message" => "session expired or doesnt exist"];
}

function requestProcessor($request)
{
  echo "received request".PHP_EOL;
  var_dump($request);
  if(!isset($request['type']))
  {
    return "ERROR: unsupported message type";
  }

  // Accepts 'uname', 'user', or 'username' dynamically
  $username = $request['uname'] ?? ($request['user'] ?? ($request['username'] ?? ''));

  switch ($request['type'])
  {
    case "login":
      return doLogin($username, $request['password'] ?? '');
    case "validate_session":
	    return validateSession($request['sessionKey'] ?? '');
    case "logout":
	    return doLogout($request['sessionKey'] ?? '');
    case "register":
	return doRegister(
		$request['first_name'] ?? '',
		$request['last_name'] ?? '',
		$request['email'] ?? '',
		$username,
		$request['password'] ?? ''
	);
	case "search_music":
            return handleSearchMusic($request['query'] ?? '', (int)($request['page'] ?? 1));
        case "get_release":
            return handleGetRelease((int)($request['discogs_release_id'] ?? 0));
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");
echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END".PHP_EOL;
exit();
?>