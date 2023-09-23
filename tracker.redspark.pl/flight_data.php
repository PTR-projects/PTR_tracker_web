<?php

// Function to execute SQL queries
function executeQuery($sql) {
    require 'config.php';
	$servername = $sql_address;
	$username = $sql_login;
	$password = $sql_pass;
	$dbname = $sql_dbname;

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $result = $conn->query($sql);

    $rows = array();
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $conn->close();

    return $rows;
}

// Function to get all data points for a given flight_id
function getDataPointsForFlight($flightId) {
    $sql = "SELECT * FROM Data WHERE flight_id = $flightId";
    return executeQuery($sql);
}

// Check if the request is a GET request and contains the flight_id parameter
if ($_SERVER["REQUEST_METHOD"] === "GET" 
		&& isset($_GET["flight_id"])
		&& ctype_digit($_GET["flight_id"])) {
			
    $flightId = intval($_GET["flight_id"]);

    // Get all data points for the given flight_id
    $dataPoints = getDataPointsForFlight($flightId);

    // Return the data as JSON response
    header("Content-Type: application/json");
    echo json_encode($dataPoints);
}
?>