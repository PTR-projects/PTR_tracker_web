<?php

require __DIR__ . '/db.php';

// Reply to the tracker and stop. Response bodies are kept as the firmware expects them.
function reply($message) {
    header("Content-Type: text/plain");
    echo $message;
    exit;
}

// Read an integer GET parameter within [min, max], or null if out of range
function get_int_param($name, $min, $max) {
    $value = intval($_GET[$name]);
    return ($value < $min || $value > $max) ? null : $value;
}

// Read a float GET parameter within [min, max], or null if out of range / not finite
function get_float_param($name, $min, $max) {
    $value = floatval($_GET[$name]);
    return (!is_finite($value) || $value < $min || $value > $max) ? null : $value;
}

// Function to add a new data point for an object.
// Returns false if the packet was dropped by the rate limit.
function addDataPoint($objectID, $packetNo, $latitude, $longitude, $altitude, $gnssSats, $gnssFix, $vbat, $max_altitude, $raw) {
    // Check if there is a flight for this object within the last 15 minutes.
    // Compute the age entirely inside MySQL so we never mix PHP's clock/timezone
    // with MySQL's clock/timezone.
    $rows = db_fetch_all(
        "SELECT flight_id, TIMESTAMPDIFF(SECOND, end_time, NOW()) AS age_seconds
         FROM Flights WHERE object_id = ? ORDER BY end_time DESC LIMIT 1",
        "i", $objectID);
    $row = $rows ? $rows[0] : null;

    // Rate limit: at most one sample per second per object.
    // Every stored packet sets end_time = NOW(), so age 0 means a packet was already stored this second.
    if (($row !== null) && ((int)$row["age_seconds"] === 0)) {
        return false;
    }

    if (($row === null) || ((int)$row["age_seconds"] > 900)) {
        // If there is no flight or the last flight was more than 15 minutes ago, create a new flight
        db_execute(
            "INSERT INTO Flights (object_id, start_time, end_time, last_latitude, last_longitude, last_altitude)
             VALUES (?, NOW(), NOW(), ?, ?, ?)",
            "iddd", $objectID, $latitude, $longitude, $altitude);
        $flight_id = db()->insert_id;
    } else {
        // Use the latest flight for this object
        $flight_id = (int)$row["flight_id"];
        if ($gnssFix != 0) {
            db_execute(
                "UPDATE Flights
                 SET end_time = NOW(), last_longitude = ?, last_latitude = ?, last_altitude = ?
                 WHERE flight_id = ?",
                "dddi", $longitude, $latitude, $altitude, $flight_id);
        } else {
            db_execute("UPDATE Flights SET end_time = NOW() WHERE flight_id = ?", "i", $flight_id);
        }
    }

    // Insert the new data point into the Data table
    db_execute(
        "INSERT INTO Data (flight_id, object_id, datetime, packet_no, latitude, longitude, altitude, gnss_sats, gnss_fix, vbat, max_altitude, raw)
         VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "iiidddiidds", $flight_id, $objectID, $packetNo, $latitude, $longitude, $altitude, $gnssSats, $gnssFix, $vbat, $max_altitude, $raw);
    return true;
}

$required = ["api_key", "object_id", "packet_no", "latitude", "longitude", "altitude", "sats_fix", "vbat", "max_altitude", "raw"];

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    reply("error");
}
foreach ($required as $name) {
    // Reject missing parameters and arrays (e.g. ?raw[]=...)
    if (!isset($_GET[$name]) || !is_string($_GET[$name])) {
        reply("error");
    }
}

// Check API key (constant-time compare; refuse to run with an empty or template key)
require __DIR__ . '/config.php';
if (!is_string($api_key) || $api_key === "" || $api_key === "your_api_key_here"
        || !hash_equals($api_key, $_GET["api_key"])) {
    reply("API key error");
}

// Extract and validate data from GET parameters (ranges match the DB column types)
$objectID     = get_int_param("object_id", 0, 2147483647);
$packetNo     = get_int_param("packet_no", -2147483648, 2147483647);
$satsFix      = get_int_param("sats_fix", 0, 255);
$latitude     = get_float_param("latitude", -90, 90);
$longitude    = get_float_param("longitude", -180, 180);
$altitude     = get_float_param("altitude", -99999999.99, 99999999.99);
$vbat         = get_float_param("vbat", -999.99, 999.99);
$max_altitude = get_float_param("max_altitude", -99999999.99, 99999999.99);

if (in_array(null, [$objectID, $packetNo, $satsFix, $latitude, $longitude, $altitude, $vbat, $max_altitude], true)) {
    reply("error");
}

$gnssSats = $satsFix & 0x3F;
$gnssFix  = $satsFix >> 6;

//clear Raw
$raw = trim($_GET["raw"], '"');
$raw = trim($raw, "'");

$max_length_raw = 100; 					// Change this to the desired maximum length
if (strlen($raw) > $max_length_raw) {
    $raw = substr($raw, 0, $max_length_raw); 	// Truncate the text to the maximum length
}

if (!preg_match('/^[0-9A-Fa-f]+$/', $raw)) {
    reply("error");
}

// Serialise packets of one object (MyISAM has no transactions), so concurrent requests
// can't both pass the rate limit or both create a new flight. Released when the connection closes.
$lock = db_fetch_all("SELECT GET_LOCK(?, 1) AS ok", "s", "ptr_tracker_object_" . $objectID);
if ((int)$lock[0]["ok"] !== 1) {
    reply("rate limit");
}

if (!addDataPoint($objectID, $packetNo, $latitude, $longitude, $altitude, $gnssSats, $gnssFix, $vbat, $max_altitude, $raw)) {
    reply("rate limit");
}
reply("success");

?>
