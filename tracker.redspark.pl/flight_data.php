<?php

require __DIR__ . '/db.php';

// Upper bound on returned points (~5.5 h at the ingest limit of 1 sample/s)
const MAX_DATA_POINTS = 20000;

// Function to get the data points for a given flight_id (newest MAX_DATA_POINTS)
function getDataPointsForFlight($flightId) {
    return db_fetch_all(
        "SELECT * FROM Data WHERE flight_id = ? ORDER BY datetime DESC LIMIT " . MAX_DATA_POINTS,
        "i", $flightId);
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    api_fail(405, "Method not allowed");
}

$flightId = get_id_param("flight_id");
if ($flightId === null) {
    api_fail(400, "Invalid flight_id");
}

// Return all data points for the given flight_id as JSON response
send_json(getDataPointsForFlight($flightId));

?>
