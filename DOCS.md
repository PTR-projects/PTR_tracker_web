# PTR Rocket Tracker — Documentation

Live GPS tracking website for PTR rockets and tracker devices, hosted at **https://tracker.redspark.pl**.

Tracker devices send telemetry packets (position, GNSS status, battery voltage, raw payload) to a PHP ingest endpoint. Packets are stored in MySQL and grouped into *flights*. A Leaflet-based web frontend shows the latest position of each recent flight on a map, and a details page shows the full track with CSV/KML export.

---

## Table of contents

1. [Architecture](#1-architecture)
2. [Repository layout](#2-repository-layout)
3. [Installation and deployment](#3-installation-and-deployment)
4. [Configuration](#4-configuration)
5. [Database](#5-database)
6. [How flights are formed](#6-how-flights-are-formed)
7. [API reference](#7-api-reference)
8. [Frontend pages](#8-frontend-pages)
9. [Security](#9-security)
10. [Time handling](#10-time-handling)
11. [CI](#11-ci)
12. [Known limitations and ideas](#12-known-limitations-and-ideas)

---

## 1. Architecture

```
 ┌──────────────┐  HTTP GET (plain HTTP allowed)   ┌──────────────────────┐
 │ Tracker      │ ───────────────────────────────▶ │ flight_addpoint.php  │
 │ device       │   ?api_key=…&object_id=…&…       │  (ingest)            │
 └──────────────┘                                  └──────────┬───────────┘
                                                              │ INSERT / UPDATE
                                                              ▼
                                                   ┌──────────────────────┐
                                                   │ MySQL                │
                                                   │  Flights, Data,      │
                                                   │  Objects             │
                                                   └──────────┬───────────┘
                                                              │ SELECT
          ┌───────────────────────────────────────────────────┼─────────────────────┐
          ▼                                                   ▼                     ▼
 ┌──────────────────┐                             ┌──────────────────┐   ┌──────────────────┐
 │ flights.php      │                             │ flight_sum.php   │   │ flight_data.php  │
 │ list / one flight│                             │ track positions  │   │ all data points  │
 └────────┬─────────┘                             └────────┬─────────┘   └────────┬─────────┘
          │ JSON (HTTPS)                                   │                      │
          ▼                                                ▼                      ▼
 ┌──────────────────────────────────────────────────────────────────────────────────────────┐
 │ Browser: index.html / index_dump.html / flights_fm23.html / flight_details.html          │
 │ (vanilla JS + Leaflet 1.9.4, OSM and Esri satellite tiles)                               │
 └──────────────────────────────────────────────────────────────────────────────────────────┘
```

- **Backend:** plain PHP (tested target: PHP 8.1) with `mysqli`. No framework, no Composer.
- **Database:** MySQL 5.7 (MyISAM tables).
- **Frontend:** static HTML + vanilla JavaScript, [Leaflet 1.9.4](https://leafletjs.com/) from unpkg, plus a vendored Leaflet fullscreen control.
- **No build step.** Files are uploaded to the web server as-is.

---

## 2. Repository layout

```
PTR_tracker_web/
├── .github/workflows/main.yml     CI: scans commits for leaked passwords
├── SQL/
│   └── dm66733_rocket_tracker.sql phpMyAdmin dump of the schema (no data)
├── tools/
│   └── generate_synthetic_data.py SQL generator for synthetic test flights
├── DOCS.md                        this file
└── tracker.redspark.pl/           web root — deployed to the server as-is
    ├── .htaccess                  HTTPS redirect, access rules, security headers
    ├── config_template.php        template for config.php (credentials, API key)
    ├── db.php                     shared DB helpers (prepared statements, errors)
    │
    ├── flight_addpoint.php        API: ingest one telemetry packet
    ├── flights.php                API: list flights / get one flight
    ├── flight_sum.php             API: track positions of a flight
    ├── flight_data.php            API: all data points of a flight
    │
    ├── index.html  + scripts.js           main live map + table (last 10 days)
    ├── index_dump.html + scripts_dump.js  same, but all flights ever
    ├── flights_fm23.html + scripts_summary.js   static map of the FM23 event
    ├── flight_details.html + scripts_flight_details.js   single flight view
    │
    ├── styles.css                 styles for the map/table pages
    ├── styles_flight_details.css  styles for the details page
    ├── leaflet_fullscreen.js/.css vendored Leaflet.Control.Fullscreen plugin
    ├── fullscreen.png, fullscreen@2x.png   icons for the fullscreen control
    └── favicon.svg
```

Files that exist only on the server and are ignored by git: `config.php`, `error_log`.

---

## 3. Installation and deployment

### Requirements

- Apache (or LiteSpeed) with `mod_rewrite`; `mod_headers` optional (used for security headers).
- PHP 8.x with the `mysqli` extension **built on `mysqlnd`** (needed for `mysqli_stmt::get_result()`; standard on shared hosting).
- MySQL 5.7+ / MariaDB.
- HTTPS certificate for the domain.

### Steps

1. **Create the database** and import the schema:
   ```sh
   mysql -u <user> -p <database> < SQL/dm66733_rocket_tracker.sql
   ```
2. **Upload** the contents of `tracker.redspark.pl/` to the web root of the domain.
3. **Create `config.php`** in the web root from `config_template.php` (see [Configuration](#4-configuration)). Never commit it.
4. **Configure the tracker devices** with the ingest URL and the API key (see [`flight_addpoint.php`](#71-flight_addpointphp--ingest-a-telemetry-packet)).
5. Open `https://<domain>/` and check that the map loads. A packet sent with a valid key should appear within 15 seconds.

### Updating

Upload the changed files. There is no cache busting, so after changing JavaScript files, users may need a hard refresh.

When a change touches both the PHP API and the scripts (e.g. a new field in a JSON response), **upload the PHP first**. Old scripts usually ignore new fields, but new scripts may depend on them.

### Running locally

Any PHP dev server works for the API, e.g.:

```sh
cd tracker.redspark.pl
php -S localhost:8000
```

Note that the frontend builds API URLs as `https://<hostname>/…` (`api_address` in each script), so pages opened over plain `http://localhost` will call `https://localhost/`. For local testing either serve over HTTPS or temporarily change `api_address`. `.htaccess` rules are not applied by the PHP built-in server.

### Synthetic test data

`tools/generate_synthetic_data.py` (Python 3, no dependencies) writes an SQL file with simulated rocket flights launched around Polish cities (pad wait → ascent → parachute descent with wind drift → landing), including GNSS fix loss during boost and occasional lost packets:

```sh
python tools/generate_synthetic_data.py --flights 10 --objects 4 --seed 42 --clean
mysql -u <user> -p <database> < synthetic_data.sql
```

- Timestamps are relative to the import time, spread over the last `--days` (default 14), so some flights appear on the live page and some only in the dump view; one flight has “just landed” (disable with `--no-live`).
- Synthetic objects use `object_id` 9001+ and are named `SIM-n` in `Objects`. `--clean` removes earlier synthetic data first; `--clean-only` produces just the `DELETE` statements.
- The whole file must be imported in one session (it uses `@now` and `LAST_INSERT_ID()`).
- `python tools/generate_synthetic_data.py --help` lists all options.

---

## 4. Configuration

`config.php` (copy of `config_template.php`):

```php
<?php
// config.php
$api_key = 'your_api_key_here';     // shared secret for flight_addpoint.php

$sql_address = "localhost";          // MySQL host
$sql_login   = "sql_login";          // MySQL user
$sql_pass    = "sql_password";       // MySQL password
$sql_dbname  = "sql_database_name";  // database name
?>
```

- `$api_key` must be a long random string. The ingest endpoint **refuses all packets** if it is empty or still `your_api_key_here`.
- Direct HTTP access to `config*.php` is blocked by `.htaccess`.
- The MySQL user only needs `SELECT, INSERT, UPDATE` on the three tables.

---

## 5. Database

Engine MyISAM, default charset latin1. All times are `DATETIME` values written with MySQL `NOW()`, i.e. in the **MySQL server's session time zone** (see [Time handling](#10-time-handling)).

### `Flights` — one row per flight session

| Column           | Type           | Description                                                          |
|------------------|----------------|----------------------------------------------------------------------|
| `flight_id`      | int, PK, AI    | Flight identifier                                                    |
| `object_id`      | int, indexed   | Tracker/object that produced the flight                              |
| `start_time`     | datetime       | Time of the first packet                                             |
| `end_time`       | datetime       | Time of the most recent packet                                       |
| `last_latitude`  | decimal(9,6)   | Last position with a GNSS fix (or the first packet's position)       |
| `last_longitude` | decimal(9,6)   | 〃                                                                   |
| `last_altitude`  | decimal(10,2)  | 〃, metres                                                           |

### `Data` — one row per received packet

| Column         | Type           | Description                                                  |
|----------------|----------------|--------------------------------------------------------------|
| `position_id`  | int, PK, AI    | Row identifier                                               |
| `flight_id`    | int, indexed   | Flight the packet belongs to                                 |
| `object_id`    | int, indexed   | Tracker/object ID                                            |
| `datetime`     | datetime       | Server receive time (`NOW()`)                                |
| `packet_no`    | int            | Packet counter reported by the device                        |
| `latitude`     | decimal(9,6)   | Degrees                                                      |
| `longitude`    | decimal(9,6)   | Degrees                                                      |
| `altitude`     | decimal(10,2)  | Metres                                                       |
| `gnss_sats`    | int            | Number of satellites (low 6 bits of `sats_fix`)              |
| `gnss_fix`     | tinyint        | Fix type (bits 6–7 of `sats_fix`); `0` = no fix              |
| `max_altitude` | decimal(10,2)  | Maximum altitude reported by the device, metres              |
| `vbat`         | decimal(5,2)   | Battery voltage, volts                                       |
| `raw`          | text (utf8_bin)| Raw packet payload as a hex string (≤ 100 characters)        |

### `Objects`

| Column        | Type         | Description   |
|---------------|--------------|---------------|
| `object_id`   | int, PK, AI  | Object ID     |
| `object_name` | varchar(255) | Display name  |

The `Objects` table is currently **not used** by any code — flights display the numeric `object_id`.

---

## 6. How flights are formed

Devices do not send a flight ID; flights are derived on the server in `flight_addpoint.php` (`addDataPoint()`):

0. Take a per-object MySQL named lock (`GET_LOCK('ptr_tracker_object_<id>', 1)`), so concurrent packets from one object are processed one at a time. The tables are MyISAM, so there are no transactions. If the lock can't be taken within 1 s, the packet is rejected with `rate limit`.
1. Find the object's most recent flight (`ORDER BY end_time DESC LIMIT 1`) and compute its age **inside MySQL**: `TIMESTAMPDIFF(SECOND, end_time, NOW())`.
2. If there is no flight, or the last packet is **more than 900 s (15 min)** old → create a new `Flights` row with `start_time = end_time = NOW()` and the packet's position.
3. Otherwise continue the existing flight:
   - with a GNSS fix (`gnss_fix != 0`): set `end_time = NOW()` and update `last_latitude/longitude/altitude`;
   - without a fix: only set `end_time = NOW()` (the last good position is kept).

   **Rate limit:** if the last packet's age is exactly 0 s, a packet from this object was already stored in the current second (every packet sets `end_time = NOW()`). The new packet is dropped with `rate limit`. The result is **at most one stored sample per second per `object_id`**.
4. Insert the packet into `Data` with the resulting `flight_id`.

So a “flight” is simply a sequence of packets from one object with no gap longer than 15 minutes. A tracker switched on at the pad, flown, and recovered within that window produces a single flight.

---

## 7. API reference

All endpoints accept **GET only** (other methods: `405`, or `error` for the ingest endpoint). Read endpoints return `application/json`; errors are short `text/plain` messages. Internal errors are written to the server error log and returned as `500 error` without details.

### JSON value types

Responses are produced from prepared statements via `mysqlnd`, so:

- integer columns (`flight_id`, `object_id`, `position_id`, `packet_no`, `gnss_sats`, `gnss_fix`, `age_seconds`) are JSON **numbers**;
- `DECIMAL` columns (coordinates, altitudes, `vbat`) are JSON **strings** with fixed decimals, e.g. `"50.337335"`, `"312.50"`;
- `DATETIME` columns are strings `"YYYY-MM-DD HH:MM:SS"` in the MySQL server's time zone;
- missing values are `null`.

The frontend relies on coordinates being strings (it uses `.slice(0, -1)` to trim the last digit).

---

### 7.1 `flight_addpoint.php` — ingest a telemetry packet

Called by tracker devices. This is the only endpoint allowed over **plain HTTP** (exempt from the HTTPS redirect), because devices may not support TLS.

```
GET /flight_addpoint.php?api_key=…&object_id=…&packet_no=…&latitude=…&longitude=…
                        &altitude=…&sats_fix=…&vbat=…&max_altitude=…&raw=…
```

| Parameter      | Required | Parsing / validation                                                                 |
|----------------|----------|---------------------------------------------------------------------------------------|
| `api_key`      | yes      | Must equal `$api_key` from `config.php` (constant-time compare)                       |
| `object_id`    | yes      | Integer, `0 … 2147483647`                                                             |
| `packet_no`    | yes      | Integer, 32-bit signed range                                                          |
| `latitude`     | yes      | Float, `-90 … 90`                                                                     |
| `longitude`    | yes      | Float, `-180 … 180`                                                                   |
| `altitude`     | yes      | Float, metres, `±99 999 999.99`                                                       |
| `sats_fix`     | yes      | Integer `0 … 255`: bits 0–5 = satellite count, bits 6–7 = fix type                    |
| `vbat`         | yes      | Float, volts, `±999.99`                                                               |
| `max_altitude` | yes      | Float, metres, `±99 999 999.99`                                                       |
| `raw`          | yes      | Surrounding `"`/`'` quotes are stripped, truncated to 100 chars, must be hex `[0-9A-Fa-f]+` |

Numbers are parsed leniently with PHP `intval()` / `floatval()` (e.g. `"12abc"` → `12`, `"abc"` → `0`), then range-checked; non-finite values (e.g. `1e999`) are rejected. Every parameter must be a single string (array syntax like `raw[]=` is rejected).

**`sats_fix` encoding example:** `202` = `0b11_001010` → fix = `3`, satellites = `10`.

**Responses** (`text/plain`, HTTP 200 unless noted):

| Body            | Meaning                                                                                     |
|-----------------|---------------------------------------------------------------------------------------------|
| `success`       | Packet stored                                                                               |
| `API key error` | Wrong API key, or the server's key is empty / still the template value                     |
| `error`         | Missing parameter, wrong method, value out of range, invalid `raw`                          |
| `rate limit`    | Packet dropped: this `object_id` already stored a packet in the current second (max 1 sample/s per object), or another packet of the same object is still being processed |
| `error` (500)   | Database failure (details only in the server error log)                                     |

**Example:**

```
http://tracker.redspark.pl/flight_addpoint.php?api_key=SECRET&object_id=12&packet_no=345
    &latitude=50.337335&longitude=19.521827&altitude=312.5&sats_fix=202&vbat=3.95
    &max_altitude=1520&raw=A1B2C3D4
→ success
```

---

### 7.2 `flights.php` — list flights or get one flight

| Request                               | Returns                                                                                  |
|---------------------------------------|------------------------------------------------------------------------------------------|
| `GET /flights.php`                    | Flights whose `end_time` is within the last **10 days**, newest first (max 500)          |
| `GET /flights.php?flight_id=<id>`     | Array with the single flight (empty array if it doesn't exist)                           |
| `GET /flights.php?mode=dump`          | All flights, newest first (max **1000** most recent)                                     |
| `GET /flights.php?mode=summary_fm23`  | All flights of every object that had a flight ending between 2023-09-01 and 2023-09-03 (FM23 event) |

`flight_id` takes precedence over `mode`. Invalid `flight_id` → `400 Invalid flight_id`; unknown `mode` → `400 Invalid mode`.

Each flight object contains all `Flights` columns plus **`age_seconds`** — seconds since `end_time`, computed by MySQL:

```json
[
  {
    "flight_id": 123,
    "object_id": 12,
    "start_time": "2026-09-30 10:00:04",
    "end_time": "2026-09-30 10:41:17",
    "last_latitude": "50.337335",
    "last_longitude": "19.521827",
    "last_altitude": "312.50",
    "age_seconds": 42
  }
]
```

The three list variants (everything except `flight_id=`) are served from a **5-second file cache** in `cache/` (`send_cached_json()` in `db.php`), which `.htaccess` blocks from direct web access. However many viewers are polling, the database is queried at most once per 5 s per list. As a result, `age_seconds` can be up to 5 s old.

---

### 7.3 `flight_sum.php` — track positions of a flight

```
GET /flight_sum.php?flight_id=<id>
```

Returns the positions of the flight's packets in the order received (`ORDER BY position_id`, max **20 000** points), used to draw tracks:

```json
[
  { "flight_id": 123, "latitude": "50.337335", "longitude": "19.521827", "altitude": "312.50" }
]
```

Missing/invalid `flight_id` → `400 Invalid flight_id`.

---

### 7.4 `flight_data.php` — all data points of a flight

```
GET /flight_data.php?flight_id=<id>
```

Returns every `Data` column for the flight's packets, **newest first** (`ORDER BY datetime DESC`), max **20 000** rows (about 5.5 h at 1 sample/s):

```json
[
  {
    "position_id": 98765,
    "flight_id": 123,
    "object_id": 12,
    "datetime": "2026-09-30 10:41:17",
    "packet_no": 345,
    "latitude": "50.337335",
    "longitude": "19.521827",
    "altitude": "312.50",
    "gnss_sats": 10,
    "gnss_fix": 3,
    "max_altitude": "1520.00",
    "vbat": "3.95",
    "raw": "A1B2C3D4"
  }
]
```

Missing/invalid `flight_id` → `400 Invalid flight_id`.

---

### 7.5 `db.php` — shared helpers (not an endpoint)

Included by every API file; direct HTTP access is blocked.

| Function                                  | Purpose                                                                                   |
|-------------------------------------------|-------------------------------------------------------------------------------------------|
| `db()`                                    | Returns a single `mysqli` connection per request (reads `config.php`)                     |
| `db_execute($sql, $types, ...$params)`    | Prepares, binds (`bind_param` type string, e.g. `"iddd"`) and executes; returns the statement |
| `db_fetch_all($sql, $types, ...$params)`  | Same, returns all rows as associative arrays                                              |
| `get_id_param($name)`                     | Reads a GET parameter as a non-negative integer, or `null`                                |
| `send_json($data)`                        | Sends `Content-Type: application/json` and the encoded data                               |
| `send_cached_json($key, $ttl, $producer)` | Like `send_json`, but serves `cache/<key>.json` if younger than `$ttl` s; otherwise calls `$producer()` and rewrites the file atomically. Best-effort: the response is still sent if the cache can't be written. |
| `api_fail($status, $message, $e = null)`  | Logs `$e` (if any), sends status + plain-text message, and exits                          |

`mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` makes all DB errors throw; the helpers catch them and call `api_fail(500, "error", $e)`.

**Rule for new code:** never interpolate request values into SQL — always use `?` placeholders through these helpers.

---

## 8. Frontend pages

All pages use Leaflet with two base layers (OpenStreetMap and Esri World Imagery), a layer switcher, a fullscreen button and a scale bar. API calls go to `https://<current hostname>/…`.

### `index.html` (+ `scripts.js`) — live view

- Map centred on Poland (`52.114339, 19.423672`, zoom 6) with a marker at the last known position of every flight from the last 10 days (`flights.php`).
- Marker popup: object ID, last position/altitude, data age, and a **Details** button.
- Table below the map: Flight ID, Object ID, Data Age, Last Latitude/Longitude (5 decimals), Last Altitude (truncated to whole metres), Details button. On screens ≤ 768 px the whole row is clickable.
- **Refreshes every 15 s**: clears the table and all non-tile layers, then redraws.
- Data Age is formatted from the server's `age_seconds` as `Ns`, `Nmin` or `Nh` (`formatDataAge()`).
- Tracks are not drawn on this page, and `flight_sum.php` is not requested (each refresh is a single `flights.php` call).

### `index_dump.html` (+ `scripts_dump.js`)

Identical to the live view but uses `flights.php?mode=dump` (all flights ever). Not linked from other pages.

### `flights_fm23.html` (+ `scripts_summary.js`) — FM23 event summary

- Map only (no table), centred on the FM23 launch site (`50.337335, 19.521827`, zoom 15).
- Uses `flights.php?mode=summary_fm23`, draws markers **and tracks** (orange polylines).
- Loads once — no auto-refresh.

### `flight_details.html?flight_id=<id>` (+ `scripts_flight_details.js`)

- Loads the flight (`flights.php?flight_id=`) and centres the map (zoom 15) on its last position, with a marker whose popup opens Google Maps.
- **Flight Details** table: last latitude, longitude, altitude.
- Draws the full track from `flight_data.php` (points where latitude or longitude is exactly `0` are skipped).
- **Data points** table (newest first): No (`position_id`), Packet No, time of day, Vbat, latitude, longitude, altitude.
- Buttons:
  - **Open Google Maps** — opens the last position in Google Maps.
  - **Download details** — CSV (`;`-separated, all `Data` columns) named `data_tracker_<object>_flight_<flight>.csv`.
  - **Download KML** — KML `LineString` with absolute altitudes, named `data_tracker_<object>_flight_<flight>.kml`, for Google Earth.
  - If the flight has no data points, both download buttons show “No Data Available” and are disabled.

### Shared JS notes

- `scripts.js`, `scripts_dump.js` and `scripts_summary.js` are near-copies; a fix in one usually needs to be applied to all three.
- Fetch errors are handled with `.catch()` on the promise chains and logged to the console.

---

## 9. Security

### Implemented

| Area              | Measure                                                                                               |
|-------------------|-------------------------------------------------------------------------------------------------------|
| SQL injection     | All queries use prepared statements with bound parameters (`db.php`)                                  |
| Input validation  | Type/range checks on every parameter; array parameters rejected; `raw` restricted to hex             |
| Authentication    | Ingest requires `api_key`, compared with `hash_equals()`; empty/template keys rejected               |
| Error disclosure  | DB errors logged server-side only; clients get generic `error` / `500`                                |
| Transport         | All requests redirected to HTTPS, except `flight_addpoint.php`                                         |
| File exposure     | `.htaccess` returns 403 for `config*.php`, `db.php`, `error_log`, `cache/`, `*.sql`, `*.log`, `*.bak`, `*.swp`, `.git`, `.idea` |
| Abuse / DoS       | Ingest limited to 1 sample/s per `object_id`; list and data responses capped (500 / 1000 flights, 20 000 points); 5 s cache for flight lists |
| Headers           | `X-Content-Type-Options: nosniff` (when `mod_headers` is available)                                   |
| Secrets in git    | `config.php` git-ignored; CI scans for committed passwords                                            |

### Remaining risks

- **API key over plain HTTP.** Because devices use HTTP, the key can be read by anyone on the network path. Fix: HTTPS support in the tracker firmware, then remove the redirect exemption.
- **No per-IP rate limiting.** Anyone with the key can still spam through many different `object_id`s, and failed API-key guesses aren't throttled. Per-client throttling is best done by the host (WAF, LiteSpeed/`mod_evasive`).
- **Read APIs are public.** Anyone can list all flights and positions (by design for a public tracker).

---

## 10. Time handling

- All timestamps are generated by MySQL `NOW()` and stored as `DATETIME` **without time zone**, in the MySQL server's session time zone.
- The 15-minute flight gap and the displayed data age are both computed **in MySQL** (`TIMESTAMPDIFF(SECOND, end_time, NOW())`). The result does not depend on PHP's time zone or on the viewer's browser clock or time zone.
- Timestamps shown on the details page (`datetime`) are the raw server-local time of day, with no conversion.
- Packet time is the **server receive time**; the device's own timestamp (if any) is only available inside `raw`.

---

## 11. CI

`.github/workflows/main.yml` runs on every push and on pull requests to `main`, using [`AlicanAkkus/pinder-action`](https://github.com/AlicanAkkus/pinder-action) to detect passwords/secrets committed to the repository. There are no automated tests.

---

## 12. Known limitations and ideas

- **Wrong popup data on tracks:** track popups use `flight.latitude/longitude/altitude`, which don't exist on flight objects (should be the `last_*` fields). Visible only on the FM23 page, where tracks are drawn.
- **Duplicated scripts:** the three map scripts should be merged into one parameterised script.
- **1-second rate limit granularity:** timestamps have 1 s resolution, so a device transmitting at exactly 1 Hz will occasionally have two packets land in the same wall-clock second, and the second one is dropped. Send at slightly under 1 Hz (e.g. every 1.1 s) to avoid this.
- **Truncated history:** `mode=dump` only shows the newest 1000 flights, and very long flights only return 20 000 points.
- **`Objects` table unused:** names could be shown instead of numeric IDs.
- **FM23 hard-coded:** the event date range is fixed in `flights.php`; a generic `?from=&to=` mode would allow summaries of other events.
- **Frontend `.slice(0, -1)`** on coordinates assumes string decimals with 6 digits; a `null` coordinate breaks the details tables.
- **No cache busting** for JS/CSS after deploys.
