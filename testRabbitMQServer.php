#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function doLogin($username,$password)
{
	// lookup username in databas
	$db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
	if($db->connect_error) {
		return ["status" => "error", "message" => "database connection failed"];
	}

	$inputHash = hash('sha256', $passsword);

	$stmt = $db->prepare("SELECT password FROM users WHERE user = ?");
	if(!stmt){
		$db->close();
		return ["status" => "error", "message" => "database prepare failed: " . $db->error];
	}

	$stmt->bind_param("s", $username);
	$stmt->execute();
	$result = $stmt->get_result();

	if($row = $result->fetch_assoc()){
		if (hash_equals($row['password'], $inputHash)) {
			$sessionKey = bin2hex(random_bytes(32));

			$stmtSession = $db->prepare("INSERT INTO sessions (user, session_key, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
			if($stmtSession) {
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
		return ["message" => "Database connection failed"];
	}

	$hash = hash('sha256', $password);

	$stmt = $db->prepare("INSERT INTO users (first_name, last_name, email, user, password, dateCreated) VALUES (?, ?, ?, ?, ?, NOW())");
	if(!$stmt){
		$err=$db->error;
		$db->close();
		return ["message" => "Database prepare failed: " . $err];
	}
	$stmt->bind_param("sssss", $first, $last, $email, $username, $hash);

	if ($stmt->execute()){
		$stmt->close();
		$db->close();
		return["message" => "Registration completed!"];
	}

		$stmt->close();
		$db->close();
		return ["message" => "Username or email already exists."];

}

function validateSession($sessionKey)
{
	$db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
	if($db->connect_error){
		return ["status" => "error", "message" => "database connection failed"]
	}

	//verify key exists and is not expired
	$stmt = $db->prepare("SELECT user FROM sessions WHERE session_key = ? AND expires at > NOW()");
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
  switch ($request['type'])
  {
    case "login":
      return doLogin($request['uname'],$request['password']);
    case "validate_session":
	    //return ["message" => "session validation not implemented yet"];
	    return validateSession($request['sessionKey']);
    case "register":
	return doRegister(
		$request['first_name'],
		$request['last_name'],
		$request['email'],
		$request['uname'],
		$request['password']
	);
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END".PHP_EOL;
exit();
?>

