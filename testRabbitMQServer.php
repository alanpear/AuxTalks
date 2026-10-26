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
				$stmtSession->bind_param("ss", $username, $sessionKey); // bind username and session key to the query
				$stmtSession->execute(); //executes the query
				$stmtSession->close(); //closes out the stmt
			}

			$stmt->close(); //closes out the stmt incase the if stmt doesnt run
			$db->close(); //closes the database

			return [ //returns the info wanted
				"status" => "success",
				"message" => "Loggin successful!",
				"sessionKey" => $sessionKey,
				"user" => $username
			];
		}
	}

	$stmt->close();//closes out the stmt incase the if stmt doesnt run
	$db->close();//closes out the db incase the if stmt doesnt run
	return ["status" => "error", "message" => "invalid username or password"];

    // check password
	//return true;
    //return false if not valid
}

function doRegister($first, $last, $email, $username, $password)
{
	$db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks'); //connects to database
	if($db->connect_error){ //checks if connection was successful
		return ["status" => "error", "message" => "Database connection failed"]; 
	}
	
	$checkStmt = $db->prepare("SELECT user, email FROM users WHERE user = ? OR email = ?"); //statement to check if the user exists already
	if(!$checkStmt){  //checks if the statement was made
		$db->close();
		return["status"=>"error", "message" => "database prepare failed:" . $db->error];
	}
	
	$checkStmt->bind_param("ss", $username, $email);
	$checkStmt->execute();
	$checkResult = $checkStmt->get_result();
	
	if($existing = $checkResult->fetch_assoc()){ //if the user exists code will run
		$checkStmt->close();
		$db->close();

		if ($existing['user'] === $username){ //does the username exist or is it the email
			return["status"=>"error", "message"=>"username already exists."];
		}else{
			return["status"=>"error", "message"=>"email already registered"];
		}
	}
	
	$checkStmt->close(); //closes the check statement

	$hash = hash('sha256', $password); //hashes the password

	$stmt = $db->prepare("INSERT INTO users (first_name, last_name, email, user, password, dateCreated) VALUES (?, ?, ?, ?, ?, NOW())"); //datebase query to create a user
	if(!$stmt){ //was statement made successfully
		$err=$db->error;
		$db->close();
		return ["status" => "error", "message" => "Database prepare failed: " . $err];
	}
	$stmt->bind_param("sssss", $first, $last, $email, $username, $hash); //binds user info but uses the password hash

	if ($stmt->execute()){
		$stmt->close();
		$db->close();
		return["status" => "success", "message" => "Registration completed!"];
	}

	//close the statements and database if they werent previously closed
	$stmt->close();
	$db->close();
	return ["status" => "error", "message" => "Username or email already exists."];

}

function handleSearchMusic($query, $page = 1) {

    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    if ($db->connect_error) return ["status" => "error", "message" => "Database offline"];

    //check local cached_releases
    $term = "%" . $query . "%"; //properly formated for discogs
    $stmt = $db->prepare("SELECT discogs_release_id, title, artist, release_year, format_summary FROM cached_releases WHERE title LIKE ? OR artist LIKE ? LIMIT 15"); //database query
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

    
    echo " [DB] Cache miss for '$query'. Forwarding to DMZ..." . PHP_EOL;
    $dmzClient = new rabbitMQClient("testRabbitMQ.ini", "dmzServer");
    $dmzRes = $dmzClient->send_request([
        'action' => 'search_music',
        'data'   => ['query' => $query, 'page' => $page]
    ]);

    
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
        // Only trust the cache if the stored tracklist decodes to a real, non-empty list.
        // Rows cached by search have no tracklist, and a too-small column truncates the JSON
        // (json_decode then returns null) -- in both cases fall through and refetch from the DMZ.
        $cachedTracks = !empty($row['tracklist']) ? json_decode($row['tracklist'], true) : null;
        if (is_array($cachedTracks) && count($cachedTracks) > 0) {
            $row['genres'] = json_decode($row['genres'] ?? '[]', true) ?? [];
            $row['tracklist'] = array_values($cachedTracks);
            $stmt->close();
            $db->close();
            return ["status" => "success", "source" => "database_cache", "data" => $row];
        }
    }
    $stmt->close();

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
        // Also overwrite artist/format: search caches the search text as the "artist".
        $ins = $db->prepare("INSERT INTO cached_releases (discogs_release_id, title, artist, release_year, format_summary, genres, tracklist, lowest_price, marketplace_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE artist = VALUES(artist), format_summary = VALUES(format_summary), genres = VALUES(genres), tracklist = VALUES(tracklist), lowest_price = VALUES(lowest_price)");
        if ($ins) {
            $ins->bind_param("ississsds", $d['discogs_release_id'], $d['title'], $d['artist'], $d['release_year'], $d['format_summary'], $genres, $tracks, $d['lowest_price'], $d['marketplace_url']);
            if (!$ins->execute()) {
                echo " [DB] Failed to cache release $releaseId: " . $ins->error . PHP_EOL;
            }
            $ins->close();
        } else {
            echo " [DB] Cache insert prepare failed: " . $db->error . PHP_EOL;
        }
    }
    $db->close();
    return $dmzRes;
}
 function handleRemoveFromCollection ($user, $releaseId){
    if(empty($user))return["status" => "error", "message => you must be logged in."];
    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    $stmt = $db->prepare("DELETE FROM user_collection WHERE user = ? AND discogs_release_id = ?");
    $stmt->bind_param("si", $user, $releaseId);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();
    $db->close();
    if ($removed > 0){
        return["status" => "success", "message" => "Removed from your collection."];
    } else { 
        return["status" => "error", "message" => "That album is not in your collection."]; 
    }
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

function handleAddToCollection($user, $releaseId, $notes = '', $condGrade = 'VG+', $sleeveCond = 'VG+') {
    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    if ($db->connect_error) return ["status" => "error", "message" => "Database offline"];

    //check if album is already in user_collection
    $check = $db->prepare("SELECT id FROM user_collection WHERE user = ? AND discogs_release_id = ?");
    $check->bind_param("si", $user, $releaseId);
    $check->execute();
    $res = $check->get_result();
    if ($res->fetch_assoc()) {
        $check->close();
        $db->close();
        return ["status" => "success", "message" => "Album is already in your collection."];
    }
    $check->close();

    $stmt = $db->prepare("INSERT INTO user_collection (user, discogs_release_id, condition_grade, sleeve_condition, notes, added_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sisss", $user, $releaseId, $condGrade, $sleeveCond, $notes);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "Album added to your collection!"];
}

function handleGetUserCollection($user) {
    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    if ($db->connect_error) return ["status" => "error", "message" => "Database offline"];

    // join with cached_releases to retrieve album title, artist, and format
    $sql = "SELECT uc.id, uc.discogs_release_id, uc.condition_grade, uc.sleeve_condition, uc.notes, uc.added_at,
                   COALESCE(cr.title, 'Unknown Title') AS title,
                   COALESCE(cr.artist, 'Unknown Artist') AS artist,
                   COALESCE(cr.format_summary, 'Standard') AS format_summary
            FROM user_collection uc
            LEFT JOIN cached_releases cr ON uc.discogs_release_id = cr.discogs_release_id
            WHERE uc.user = ?
            ORDER BY uc.added_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $res = $stmt->get_result();
    $collection = [];
    while ($row = $res->fetch_assoc()) {
        $collection[] = $row;
    }
    $stmt->close();
    $db->close();

    return ["status" => "success", "data" => $collection];
}

function handleAddReview($user, $releaseId, $rating, $reviewText = null, $itemType = 'album', $trackTitle = null, $format = 'Vinyl') {
    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    if ($db->connect_error) return ["status" => "error", "message" => "Database offline"];

    $stmt = $db->prepare("INSERT INTO reviews (user, discogs_release_id, item_type, track_title, rating, review_text, physical_format, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sissdss", $user, $releaseId, $itemType, $trackTitle, $rating, $reviewText, $format);
    
    if ($stmt->execute()) {
        $stmt->close();
        $db->close();
        return ["status" => "success", "message" => "Feedback saved successfully!"];
    } else {
        $err = $db->error;
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "Failed to save: " . $err];
    }
}

function handleGetAlbumReviews($releaseId) {
    $db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
    if ($db->connect_error) return ["status" => "error", "message" => "Database offline"];

    $stmt = $db->prepare("SELECT user, rating, review_text, physical_format, created_at FROM reviews WHERE discogs_release_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $releaseId);
    $stmt->execute();
    $res = $stmt->get_result();
    $reviews = [];
    while ($row = $res->fetch_assoc()) {
        $reviews[] = $row;
    }
    $stmt->close();
    $db->close();

    return ["status" => "success", "data" => $reviews];
}




function requestProcessor($request)
{
  echo "received request".PHP_EOL;
  var_dump($request);
  if(!isset($request['type']))
  {
    return "ERROR: unsupported message type";
  }

  //'uname', 'user', or 'username' dynamically
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
  	case "add_to_collection":
        return handleAddToCollection(
            $username,
            (int)($request['discogs_release_id'] ?? 0),
            $request['notes'] ?? '',
            $request['condition_grade'] ?? 'VG+',
            $request['sleeve_condition'] ?? 'VG+'
        );
    case "get_collection":
        return handleGetUserCollection($username);
    case "add_review":
        return handleAddReview(
            $username,
            (int)($request['discogs_release_id'] ?? 0),
            (float)($request['rating'] ?? 0.0),
            $request['review_text'] ?? null,
            $request['item_type'] ?? 'album',
            $request['track_title'] ?? null,
            $request['physical_format'] ?? 'Vinyl'
        );
    case "remove_from_collection":
        return handleRemoveFromCollection($username, (int)($request['discogs_release_id'] ?? 0));
    case "get_album_reviews":
        return handleGetAlbumReviews((int)($request['discogs_release_id'] ?? 0));
  	}
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");
echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END".PHP_EOL;
exit();
?>
