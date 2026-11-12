<?php

require __DIR__ . '/db.php';

// Upper bound on returned points (~5.5 h at the ingest limit of 1 sample/s)
const MAX_TRACK_POINTS = 20000;

// Function to get the track (positions only) for a given flight_id, in the order received
function getDataPointsForFlight($flightId) {
    return db_fetch_all(
        "SELECT flight_id, latitude, longitude, altitude FROM Data WHERE flight_id = ?
         ORDER BY position_id LIMIT " . MAX_TRACK_POINTS,
        "i", $flightId);
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    api_fail(405, "Method not allowed");
}

$flightId = get_id_param("flight_id");
if ($flightId === null) {
    api_fail(400, "Invalid flight_id");
}

// Return the track for the given flight_id as JSON response
send_json(getDataPointsForFlight($flightId));

?>
