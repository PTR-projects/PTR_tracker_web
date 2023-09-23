<?php
	
// Function to execute SQL queries
function executeQuery($sql) {
	require 'config.php';
	$servername = $sql_address;
	$username = $sql_login;
	$password = $sql_pass;
	$dbname = $sql_dbname;

echo "$dbname";
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $result = $conn->query($sql);

    $conn->close();

    return $result;
}

// Function to execute SQL queries
function executeQuery_addNewFlight($sql) {
	$servername = "localhost";
	$username = "dm66733_php_access";
	$password = "t^xMU7kX)PGzJH!";
	$dbname = "dm66733_rocket_tracker";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $result = $conn->query($sql);
	$id = $conn->insert_id;		// Get the id of the inserted row

    $conn->close();

    return $id;
}

// Function to add a new data point for an object
function addDataPoint($objectID, $packetNo, $latitude, $longitude, $altitude, $gnssSats, $gnssFix, $vbat, $max_altitude, $raw) {
//    // Check if the given object name exists in the Objects table
//    $sql = "SELECT object_id FROM Objects WHERE object_name = '$objectID'";
//    $result = executeQuery($sql);
//
//    if ($result->num_rows === 0) {
//        // If the object does not exist, insert it into the Objects table
//        $sql = "INSERT INTO Objects (object_name) VALUES ('$objectID')";
//        executeQuery($sql);
//        $objectID = $conn->insert_id;
//    } else {
//        $row = $result->fetch_assoc();
//        $objectID = $row["object_id"];
//    }

	//echo "Prepare SQL 1";
    // Check if there is a flight for this object within the last 15 minutes
    $sql = "SELECT flight_id, end_time FROM Flights WHERE object_id = $objectID ORDER BY end_time DESC LIMIT 1";
    //echo "Check if flight exists   $sql" . PHP_EOL ;
    $result = executeQuery($sql);
	$row = $result->fetch_assoc();
    $last_time =  strtotime($row["end_time"]);
	$flight_id = $row["flight_id"];
    $last_age = time() - $last_time ;
    
    //echo "Last point age = $last_age"  . PHP_EOL ;
	//echo "Flight id: $flight_id" . PHP_EOL ;
    $tmp = $result->num_rows;
    //echo "Flight exists = $tmp" . PHP_EOL;

    if (($result->num_rows === 0) || ($last_age > 900)) {
		echo "error";
        // If there is no flight or the last flight was more than 15 minutes ago, create a new flight
        $sql = "INSERT INTO Flights (object_id, start_time, end_time, last_latitude, last_longitude, last_altitude) 
				VALUES ($objectID, NOW(), NOW(), $latitude, $longitude, $altitude)";
				
        $flight_id = executeQuery_addNewFlight($sql);
        //echo "New flight\n" . PHP_EOL ;
        //echo "Executes  $sql" . PHP_EOL;
		//echo "New FlightID $flight_id" . PHP_EOL;
    } else {
        // Use the latest flight for this object
		if($gnssFix != 0){
			$sql = "UPDATE Flights 
				SET end_time = NOW(),  
				last_longitude = $longitude,
				last_latitude = $latitude,
				last_altitude = $altitude
				WHERE flight_id = $flight_id";
		}
		else {
			$sql = "UPDATE Flights 
				SET end_time = NOW()
				WHERE flight_id = $flight_id";
		}
        
		//echo "Last point age = $sql"  . PHP_EOL ;
        executeQuery($sql);
        //echo "Update last msg time $sql" . PHP_EOL;
    }

	
    // Insert the new data point into the Data table
    $sql = "INSERT INTO Data (flight_id, object_id, datetime, packet_no, latitude, longitude, altitude, gnss_sats, gnss_fix, vbat, max_altitude, raw) 
            VALUES ($flight_id, $objectID, NOW(), $packetNo, $latitude, $longitude, $altitude, $gnssSats, '$gnssFix', $vbat, $max_altitude, $raw)";
    executeQuery($sql);
	//echo "Add new data point $sql" . PHP_EOL;
    
    // Return response indicating success or error
    if ($flight_id) {
        return "OK";
    } else {
        return "Error";
    }
}
//echo "Parsing started". PHP_EOL;
// Check if the request is a GET request and contains the required parameters
if ($_SERVER["REQUEST_METHOD"] === "GET"
	&& isset($_GET["api_key"])
    && isset($_GET["object_id"], $_GET["packet_no"], $_GET["latitude"], $_GET["longitude"], $_GET["altitude"], $_GET["sats_fix"], $_GET["vbat"], $_GET["max_altitude"],  $_GET["raw"])) {
	
	// Check API key
	require 'config.php';
	if($_GET["api_key"] != $api_key){
		echo "API key error";
		return;
	}

	$arguments_valid = true;
	
    // Extract data from GET parameters
    $objectID = intval($_GET["object_id"]);
    $packetNo = intval($_GET["packet_no"]);
    $latitude = floatval($_GET["latitude"]);
    $longitude = floatval($_GET["longitude"]);
    $altitude = floatval($_GET["altitude"]);
    $gnssSats = intval($_GET["sats_fix"]) & 0x3F;
    $gnssFix =  intval($_GET["sats_fix"]) >> 6;
    $vbat = floatval($_GET["vbat"]);
    $max_altitude = floatval($_GET["max_altitude"]);
	$raw = $_GET["raw"];
	
	$max_length_raw = 20; 							// Change this to the desired maximum length
	if (strlen($raw) > $max_length_raw) {
		$raw = substr($raw, 0, $max_length_raw); 	// Truncate the text to the maximum length
	}
	
	if (preg_match('/^[0-9A-Fa-f]+$/', $raw)) {
		//valid argument
	} else {
		$arguments_valid = false;
	}

	if($arguments_valid == true){
		$result = addDataPoint($objectID, $packetNo, $latitude, $longitude, $altitude, $gnssSats, $gnssFix, $vbat, $max_altitude, $raw);
	}
}
else {
	echo "error";
}

?>