<?php

// Function to execute SQL queries
function executeQuery($sql) {
	require 'config.php';
    // Replace with your database connection code
    $servername = $sql_address;
	$username   = $sql_login;
	$password   = $sql_pass;
	$dbname     = $sql_dbname;

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

// Function to get a list of all flights
function getAllFlights() {
    $sql = "SELECT f.*
			FROM Flights f
			INNER JOIN (
				SELECT object_id, MAX(end_time) AS most_recent_end_time
				FROM Flights
				GROUP BY object_id
			) subquery
			ON f.object_id = subquery.object_id AND f.end_time = subquery.most_recent_end_time;";
    return executeQuery($sql);
}

// Function to get a list of all flights
function getSummaryFm23() {
    $sql = "SELECT f.*
			FROM Flights f
			INNER JOIN (
			   SELECT object_id
			   FROM Flights
			   WHERE end_time >= '2023-09-01' AND end_time <= '2023-09-03'
			) subquery
			ON f.object_id = subquery.object_id;";
    return executeQuery($sql);
}

// Function to get a list of all flights
function getFlight($flightId) {
    $sql = "SELECT * FROM Flights WHERE flight_id = $flightId";
    return executeQuery($sql);
}

// Check if the request is a GET request
if ($_SERVER["REQUEST_METHOD"] === "GET") {
	
	if (isset($_GET["flight_id"]) && ctype_digit($_GET["flight_id"])) {
		$flightId = $_GET["flight_id"];
		$flights = getFlight($flightId);
	}
	else if(isset($_GET["mode"])) {
		$mode = $_GET["mode"];
		if($mode == "summary_fm23"){
			//FM23 data
		}
	}
	else {
		// Get the list of all flights
		$flights = getAllFlights();
	}
    
    // Return the flights data as JSON response
    header("Content-Type: application/json");
    echo json_encode($flights);
}

?>