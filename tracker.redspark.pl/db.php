<?php
// db.php - shared database helpers for the API endpoints.
// All queries go through prepared statements; errors are logged, never shown to the client.

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Stop the request with an HTTP status and a short, generic message.
// Internal details (e.g. DB errors) only go to the server error log.
function api_fail($status, $message, $exception = null) {
    if ($exception !== null) {
        error_log($exception->getMessage());
    }
    http_response_code($status);
    header("Content-Type: text/plain");
    echo $message;
    exit;
}

// Single connection per request
function db() {
    static $conn = null;
    if ($conn === null) {
        require __DIR__ . '/config.php';
        try {
            $conn = new mysqli($sql_address, $sql_login, $sql_pass, $sql_dbname);
        } catch (mysqli_sql_exception $e) {
            api_fail(500, "error", $e);
        }
    }
    return $conn;
}

// Execute a prepared statement, e.g. db_execute("... WHERE id = ?", "i", $id)
function db_execute($sql, $types = "", ...$params) {
    try {
        $stmt = db()->prepare($sql);
        if ($types !== "") {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt;
    } catch (mysqli_sql_exception $e) {
        api_fail(500, "error", $e);
    }
}

// Execute a prepared SELECT and return all rows as associative arrays
function db_fetch_all($sql, $types = "", ...$params) {
    try {
        return db_execute($sql, $types, ...$params)->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e) {
        api_fail(500, "error", $e);
    }
}

// Read a GET parameter as a non-negative integer ID, or null if missing/invalid
function get_id_param($name) {
    if (!isset($_GET[$name]) || !is_string($_GET[$name]) || !ctype_digit($_GET[$name])) {
        return null;
    }
    $value = filter_var($_GET[$name], FILTER_VALIDATE_INT);
    return $value === false ? null : $value;
}

// Send rows as JSON
function send_json($data) {
    header("Content-Type: application/json");
    echo json_encode($data);
}

// Send JSON from a short-lived file cache, calling $producer() to build the data on a miss.
// Many viewers polling the same list then cost one DB query per $ttl seconds instead of one each.
// The cache/ directory is blocked from web access in .htaccess.
function send_cached_json($key, $ttl, $producer) {
    $dir  = __DIR__ . '/cache';
    $file = $dir . '/' . preg_replace('/[^a-z0-9_]/', '', $key) . '.json';

    $mtime = @filemtime($file);
    if ($mtime !== false && (time() - $mtime) < $ttl) {
        $json = @file_get_contents($file);
        if ($json !== false) {
            header("Content-Type: application/json");
            echo $json;
            return;
        }
    }

    $json = json_encode($producer());

    // Write atomically (temp file + rename) so readers never see a partial file.
    // Caching is best-effort: on any failure the response is still sent.
    if (is_dir($dir) || @mkdir($dir, 0750)) {
        $tmp = @tempnam($dir, 'tmp');
        if ($tmp !== false) {
            if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
            }
        }
    }

    header("Content-Type: application/json");
    echo $json;
}
?>
