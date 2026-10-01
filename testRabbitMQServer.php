#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function doLogin($username,$password)
{
    // lookup username in databas
    // check password
	return true;
    //return false if not valid
}

doRegister($first, $last, $email, $username, $password){
	$db = new mysqli('localhost', 'admin', 'AuxTalks', 'AuxTalks');
	if($db->connect_error){
		return ["message" => "Database connection failed"];
	}

	$hash = hash('sha256', $password);

	$stmt = $db->prepare("INSERT INTO users (first_name, last_name, email, user, password, dateCreated) VALUES (?, ?, ?, ?, ?, NOW())");
	$stmt->bind_param("sssss", $first, $last, $email, $username, $hash);

	if ($stmt->execute()){
		$stmt->close();
		$db->close();
		return["message" => "Regestration completed!"];
	}

		$stmt->close();
		$db->close();
		return ["message" => "Username or email already exists."]
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
      return doLogin($request['username'],$request['password']);
    case "validate_session":
	    return doValidate($request['sessionId']);
    case "register"
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

