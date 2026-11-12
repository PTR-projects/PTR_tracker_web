<?php

require __DIR__ . '/db.php';

// Age of the last data point is computed by MySQL so the client's clock/timezone doesn't matter
const FLIGHT_COLUMNS = "f.*, TIMESTAMPDIFF(SECOND, f.end_time, NOW()) AS age_seconds";

// Upper bounds on list sizes, so a flood of flights can't produce huge responses
const MAX_RECENT_FLIGHTS = 500;
const MAX_DUMP_FLIGHTS   = 1000;

// List responses are shared by all viewers and cached this long (seconds)
const LIST_CACHE_TTL = 5;

// Function to get a list of all flights
function getAllFlights() {
    return db_fetch_all(
        "SELECT " . FLIGHT_COLUMNS . "
         FROM Flights f
         WHERE f.end_time >= NOW() - INTERVAL 10 DAY
         ORDER BY f.end_time DESC
         LIMIT " . MAX_RECENT_FLIGHTS);
}

// Function to get a list of all flights without any filters (newest MAX_DUMP_FLIGHTS)
function getFlightsDump() {
    return db_fetch_all(
        "SELECT " . FLIGHT_COLUMNS . "
         FROM Flights f
         ORDER BY f.end_time DESC
         LIMIT " . MAX_DUMP_FLIGHTS);
}

// Function to get a list of all flights from FM23
function getSummaryFm23() {
    return db_fetch_all(
        "SELECT " . FLIGHT_COLUMNS . "
         FROM Flights f
         WHERE f.object_id IN (
            SELECT object_id
            FROM Flights
            WHERE end_time >= '2023-09-01' AND end_time <= '2023-09-03'
         )
         LIMIT " . MAX_DUMP_FLIGHTS);
}

// Function to get a single flight
function getFlight($flightId) {
    return db_fetch_all(
        "SELECT " . FLIGHT_COLUMNS . " FROM Flights f WHERE f.flight_id = ?",
        "i", $flightId);
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    api_fail(405, "Method not allowed");
}

if (isset($_GET["flight_id"])) {
    $flightId = get_id_param("flight_id");
    if ($flightId === null) {
        api_fail(400, "Invalid flight_id");
    }
    send_json(getFlight($flightId));
}
else if (isset($_GET["mode"])) {
    $mode = $_GET["mode"];
    if ($mode === "summary_fm23") {
        //FM23 data
        send_cached_json("flights_fm23", LIST_CACHE_TTL, "getSummaryFm23");
    }
    else if ($mode === "dump") {
        send_cached_json("flights_dump", LIST_CACHE_TTL, "getFlightsDump");
    }
    else {
        api_fail(400, "Invalid mode");
    }
}
else {
    // Get the list of all flights
    send_cached_json("flights_recent", LIST_CACHE_TTL, "getAllFlights");
}

?>
