# Security Audit — PTR Rocket Tracker

| | |
|---|---|
| **Target** | `PTR-projects/PTR_tracker_web` — website https://tracker.redspark.pl |
| **Date** | 2026-09-30 |
| **Revision** | branch `bartekM-pl-patch-timediff-fix` @ `99dbf02` **plus uncommitted working-tree changes** (API hardening, `db.php`, `.htaccess` rules) |
| **Type** | White-box source review (static). No testing against the production server was performed. |

---

## 1. Scope and method

**In scope**

- PHP API: `flight_addpoint.php`, `flights.php`, `flight_sum.php`, `flight_data.php`, `db.php`, `config_template.php`
- Web server configuration: `.htaccess`
- Frontend: all `*.html` pages and `scripts*.js`, third-party asset loading
- Database schema: `SQL/dm66733_rocket_tracker.sql`
- CI: `.github/workflows/main.yml`
- Git history of all branches (secret leakage)
- `tools/generate_synthetic_data.py`

**Out of scope / not verifiable from source**

- Actual server configuration (PHP version, `display_errors`, DB user privileges, TLS setup, file permissions, hosting panel)
- Tracker device firmware
- Live penetration testing

**Method:** manual code review of every request path from input to database and from database to DOM; review of web-server rules; `git log --all -p` scan for credentials; review of dependencies and CI.

---

## 2. Executive summary

The application is small and its attack surface is limited: one authenticated write endpoint and three public read endpoints. The uncommitted changes on this branch fix the code-level weaknesses (SQL built by string interpolation, type-juggling API key comparison, error disclosure, exposed log/config files). SQL injection and XSS are not exploitable in the reviewed code.

The main remaining risks are architectural:

1. **The ingest API key is sent in cleartext in the URL** (HTTP allowed for devices), so anyone on the network path, or with access to logs, can obtain it and inject or forge data for any tracker.
2. **The hardening is not deployed.** Production still runs the code from `main`, which has the weaknesses listed in [section 5](#5-issues-fixed-in-the-working-tree-pending-deploy).
3. **No abuse protection:** no rate limiting, unbounded queries, and self-amplifying polling make the site easy to overload.
4. **Outdated platform:** PHP 8.1 and MySQL 5.7 (per the schema dump) are past end of life.

### Findings overview

| ID   | Severity | Title                                                                  | Status |
|------|----------|------------------------------------------------------------------------|--------|
| H-1  | High     | API key sent in cleartext and in the URL query string                  | Open   |
| H-2  | High     | Security fixes not yet committed or deployed                           | Open   |
| M-1  | Medium   | Single shared API key; no per-device authentication or revocation      | Open   |
| M-2  | Medium   | No replay protection on ingest                                         | Open   |
| M-3  | Medium   | No rate limiting; unbounded queries; polling amplification (DoS)       | Mostly fixed (per-IP limiting open) |
| M-4  | Medium   | Third-party scripts loaded without SRI; no Content-Security-Policy     | Open   |
| M-5  | Medium   | End-of-life runtime (PHP 8.1, MySQL 5.7)                               | Verify |
| L-1  | Low      | Full location history of all trackers is public                        | Open   |
| L-2  | Low      | No HSTS; HTTP→HTTPS redirect is temporary (302)                        | Open   |
| L-3  | Low      | No clickjacking protection                                             | Open   |
| L-4  | Low      | PHP `display_errors` state unknown                                     | Verify |
| L-5  | Low      | Directory listing not explicitly disabled                              | Open   |
| L-6  | Low      | Race condition on flight creation (no transactions, MyISAM)            | Fixed  |
| L-7  | Low      | CI: unpinned actions and default token permissions                     | Open   |
| L-8  | Low      | DOM sinks (`innerHTML`, inline `onclick`) fed with API data            | Open   |
| I-1  | Info     | `window.open` without `noopener`                                       | Open   |
| I-2  | Info     | Hosting account name disclosed in the schema file name                 | Open   |
| I-3  | Info     | DB user privileges not documented or enforced                          | Verify |
| I-4  | Info     | Lenient numeric parsing on ingest                                      | Open   |
| I-5  | Info     | `hash_equals` reveals API key length                                   | Accept |
| I-6  | Info     | Synthetic-data clean-up deletes the object_id range 9001–9999          | Note   |

Severity reflects likelihood × impact for this deployment: a public hobby/club tracker with no personal accounts. The worst realistic impact is forged or deleted-looking tracking data during an event, service unavailability, or disclosure of recovery locations.

---

## 3. Positive observations

- **No secrets in git.** `config.php` has never been committed on any branch; a full-history scan found no credentials. `SQL/` contains the schema only, no data.
- **Parameterised SQL everywhere** (working tree): all queries go through `db_execute` / `db_fetch_all` with bound parameters.
- **Strict input handling on ingest:** type checks reject array parameters, values are range-checked against the DB column types, and `raw` is restricted to ≤100 hex characters.
- **Constant-time API key comparison**, and the endpoint refuses to run with an empty or template key.
- **Generic error responses;** details go only to the server log.
- **HTTPS enforced** for everything except the device endpoint.
- **Sensitive files blocked** in `.htaccess` (`config*.php`, `db.php`, `error_log`, dumps, `.git`).
- **No cookies, sessions or user accounts**, so there is no session hijacking or CSRF-against-users surface on the read side.
- **CI secret scanning** is present.

---

## 4. Findings

### H-1 — API key sent in cleartext and in the URL query string

- **Severity:** High
- **Location:** `.htaccess` (HTTPS exemption for `/flight_addpoint.php`), `flight_addpoint.php` (`$_GET["api_key"]`)

**Description.** Tracker devices call the ingest endpoint over plain HTTP, and the key is a GET parameter. The full URL, key included, is therefore:

- visible to anyone on the network path (mobile carrier, Wi-Fi at the launch site, gateways);
- written to the web server access log and possibly to hosting-panel statistics;
- possibly stored by any HTTP proxy or gateway in between.

**Impact.** Anyone who obtains the key can post arbitrary positions for **any** `object_id` (see M-1). During an event this could show fake flights, move a rocket's last known position (misleading recovery teams), or flood the database.

**Recommendation.**

1. Enable TLS in the tracker firmware (or its gateway) and remove the HTTPS exemption from `.htaccess`.
2. Move the key out of the URL: send it in a header (e.g. `X-Api-Key`) or use a POST body.
3. If the devices cannot do TLS, authenticate each packet with an **HMAC** over the parameters plus a timestamp/counter using a per-device secret, so the secret itself is never sent (this also addresses M-1 and M-2).
4. Rotate the key after fixing, and treat the current key as compromised if it has ever been used over HTTP on shared networks.

---

### H-2 — Security fixes not yet committed or deployed

- **Severity:** High (process)
- **Location:** production server; `main` branch

**Description.** The hardening in this revision exists only as uncommitted changes in a local working tree. Production (and `main`) still contains the weaknesses listed in [section 5](#5-issues-fixed-in-the-working-tree-pending-deploy). Of those, the ones most likely to matter are the exposed `error_log`, error messages sent to clients, and the loose API key comparison.

**Recommendation.** Review, commit and deploy the changes (PHP files together with the new `db.php`). Then check manually that:

- `https://tracker.redspark.pl/error_log`, `/config.php` and `/db.php` return **403**;
- the live page still loads and a test packet returns `success`.

If `error_log` was reachable before, review its contents for anything sensitive, then rotate the DB password and API key if they could have appeared there.

---

### M-1 — Single shared API key; no per-device authentication or revocation

- **Severity:** Medium
- **Location:** `flight_addpoint.php`, `config.php`

**Description.** All devices share one key, and `object_id` is taken from the request without being bound to the key. One device's key (e.g. read from a lost or disassembled tracker, or sniffed per H-1) allows impersonating every tracker. A single tracker cannot be revoked; the key must be changed on all of them.

**Recommendation.** Issue a key per device, stored hashed in the DB (e.g. a `devices` table: `object_id`, `key_hash`, `enabled`), and accept packets only for the `object_id` that matches the key. Use the existing unused `Objects` table for this.

---

### M-2 — No replay protection on ingest

- **Severity:** Medium
- **Location:** `flight_addpoint.php`

**Description.** A captured request stays valid forever and can be replayed. `packet_no` is stored but not checked, and the server stamps each packet with `NOW()`, so a replayed old packet appears as a fresh position.

**Impact.** Combined with H-1, an attacker can make a recovered tracker appear to be transmitting from an old position, or keep a flight "alive".

**Recommendation.** Reject packets whose `packet_no` is not greater than the last one for that object in the current flight (allowing for counter resets on a new flight). Better, include a device timestamp or counter in an HMAC (see H-1).

---

### M-3 — No rate limiting; unbounded queries; polling amplification (DoS)

- **Severity:** Medium
- **Location:** all endpoints; `flights.php?mode=dump`; `flight_data.php`; `scripts.js` / `scripts_dump.js`

**Description.**

- No endpoint limits requests per client.
- `flights.php?mode=dump` returns every flight ever recorded, and `flight_data.php` returns every packet of a flight, with no `LIMIT`.
- Each open live page requests `flights.php` **plus one `flight_sum.php` per flight** every 15 s. The tracks are then not even drawn. With 30 recent flights and 50 viewers during an event, that is about 100 requests per second from legitimate users alone.
- MyISAM uses table-level locks, so heavy reads also delay ingest writes.

**Impact.** The site is easy to slow down or take offline, possibly during the event when it is most needed. Spamming the ingest endpoint (with a leaked key) can fill the database.

**Recommendation.**

1. Remove the unused `flight_sum.php` polling from `scripts.js` / `scripts_dump.js`.
2. Add `LIMIT`s or pagination to `mode=dump` and `flight_data.php` (or restrict `dump` to authenticated users).
3. Rate-limit per IP: hosting WAF / `mod_evasive` / LiteSpeed per-client throttling, or a simple counter table.
4. Cache `flights.php` responses for a few seconds (e.g. a file cache or `Cache-Control: max-age=5`).
5. Consider switching tables to InnoDB (row locks, crash safety).

**Status: mostly fixed** (working tree, not yet deployed):

| Recommendation | Done |
|---|---|
| Ingest rate limit | **Max 1 stored sample per second per `object_id`.** `flight_addpoint.php` drops a packet with `rate limit` if `Flights.end_time` for that object is already the current second. A per-object `GET_LOCK` makes the check atomic under concurrent requests. There's no extra query: the latest-flight lookup already existed. |
| 1. Polling | `flight_sum.php` is no longer requested by `scripts.js` / `scripts_dump.js`. A live page now makes one request per 15 s instead of 1 + N. |
| 2. Limits | Recent list max 500 flights, `mode=dump` and FM23 max 1000; `flight_data.php` / `flight_sum.php` max 20 000 points. The FM23 query now uses `IN` instead of a row-multiplying `JOIN`. |
| 4. Cache | Flight lists are served from a 5 s file cache (`cache/`, blocked in `.htaccess`), so the DB sees at most one list query per 5 s regardless of viewer count. |
| 3. Per-IP limiting | **Open.** It needs host support (WAF / LiteSpeed / `mod_evasive`). With a leaked key, an attacker can still insert about 1 row/s for *each* `object_id` they invent. Per-device keys (M-1) close this. |
| 5. InnoDB | **Open** (optional). |

---

### M-4 — Third-party scripts loaded without SRI; no Content-Security-Policy

- **Severity:** Medium
- **Location:** `index.html`, `index_dump.html`, `flights_fm23.html`, `flight_details.html`

**Description.** Leaflet JS/CSS are loaded from `unpkg.com` without `integrity` attributes, and there is no CSP. If unpkg or the npm package were compromised, or the response tampered with, arbitrary script would run on the site.

**Recommendation.**

- Add SRI to the Leaflet tags, or self-host Leaflet next to the vendored fullscreen plugin:
  ```html
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
          integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  ```
  These hashes were computed from the files currently served by unpkg on 2026-09-30 and match Leaflet's published values for 1.9.4.
- Add a CSP. The inline `loadPage();` scripts and inline `onclick` handlers must be moved to JS files first, or allowed by hash. For example:
  ```
  Content-Security-Policy: default-src 'self'; script-src 'self' https://unpkg.com;
    style-src 'self' https://unpkg.com; img-src 'self' data: https://*.tile.openstreetmap.org
    https://server.arcgisonline.com https://unpkg.com; connect-src 'self'; frame-ancestors 'none'
  ```

---

### M-5 — End-of-life runtime (PHP 8.1, MySQL 5.7)

- **Severity:** Medium
- **Status:** verify on the server
- **Location:** hosting; versions taken from the header of `SQL/dm66733_rocket_tracker.sql` (September 2023)

**Description.** The schema dump reports MySQL 5.7.41 and PHP 8.1.16. MySQL 5.7 reached end of life in October 2023, and PHP 8.1 security support ended on 31 December 2025. Neither receives security fixes any more, unless the host backports them (CloudLinux `-cll-lve` builds sometimes do).

**Recommendation.** Check the current versions in the hosting panel. Upgrade to a supported PHP (8.3+) and MySQL 8.0+/8.4 LTS or MariaDB LTS. The code uses only standard `mysqli` and should run unchanged.

---

### L-1 — Full location history of all trackers is public

- **Severity:** Low (by design, but worth a conscious decision)
- **Location:** `flights.php?mode=dump`, `flight_data.php`, `index_dump.html`

**Description.** Anyone can download every recorded position of every tracker since the service started, including positions transmitted *after landing* while the tracker is carried back or left on (e.g. at a team member's car or home).

**Recommendation.** Limit public data to recent flights (as the main page already does), protect `mode=dump` and old flights behind authentication, and/or truncate tracks some minutes after landing. Add a data-retention policy.

---

### L-2 — No HSTS; HTTP→HTTPS redirect is temporary (302)

- **Severity:** Low
- **Location:** `.htaccess`

**Description.** `RewriteRule … [R,L]` issues a 302, and there is no `Strict-Transport-Security` header. A visitor's first plain-HTTP request can be intercepted (SSL stripping) on hostile networks.

**Recommendation.** Use `[R=301,L]` and add:
```apache
Header always set Strict-Transport-Security "max-age=31536000" "expr=%{HTTPS} == 'on'"
```
Do this after H-1 is resolved, or keep the device endpoint on a separate host name, because HSTS applies to the whole host name.

---

### L-3 — No clickjacking protection

- **Severity:** Low
- **Location:** `.htaccess`

**Description.** Pages can be framed by other sites. There are no sensitive actions to trigger, so impact is limited to misleading embedding.

**Recommendation.** `Header always set X-Frame-Options "DENY"`, or `frame-ancestors 'none'` in the CSP (M-4).

---

### L-4 — PHP `display_errors` state unknown

- **Severity:** Low
- **Status:** verify on the server
- **Location:** server configuration

**Description.** The code now catches DB errors, but any other PHP warning or fatal error would be printed to the client if `display_errors` is on, disclosing file paths. The existence of `error_log` in the web root suggests logging to file is on; the display setting is unknown.

**Recommendation.** Set in `.user.ini` (PHP-FPM/LSAPI) or the hosting panel:
```ini
display_errors = Off
log_errors = On
error_log = /home/<account>/logs/php_error.log   ; outside the web root
```

---

### L-5 — Directory listing not explicitly disabled

- **Severity:** Low
- **Location:** `.htaccess`

**Description.** The web root has no subdirectories today, but any folder uploaded later (backups, `tools/`, a `.git` checkout) could be listed if the server default allows indexes.

**Recommendation.** Add `Options -Indexes` to `.htaccess`. Keep `tools/`, `SQL/` and docs out of the web root, as they are now.

---

### L-6 — Race condition on flight creation (no transactions, MyISAM)

- **Severity:** Low (data integrity)
- **Location:** `flight_addpoint.php` → `addDataPoint()`

**Description.** "Find the latest flight" followed by "insert a new flight" is not atomic. Two packets from the same object arriving at the same time after a gap longer than 15 min can create two flights. MyISAM also has no crash recovery for partial writes.

**Recommendation.** Convert tables to InnoDB and wrap the logic in a transaction with `SELECT … FOR UPDATE`, or serialise per object with `GET_LOCK('obj_<id>', 2)`.

**Status: fixed** (working tree): `flight_addpoint.php` takes `GET_LOCK('ptr_tracker_object_<id>', 1)` before the lookup/insert. The lock is released when the connection closes at the end of the request. Crash safety still requires InnoDB.

---

### L-7 — CI: unpinned actions and default token permissions

- **Severity:** Low
- **Location:** `.github/workflows/main.yml`

**Description.** `actions/checkout@master` follows a moving branch, and `AlicanAkkus/pinder-action@1.0.0` is a third-party action pinned only by tag. Tags can be moved. The workflow runs with the default `GITHUB_TOKEN` permissions.

**Recommendation.**
```yaml
permissions:
  contents: read
steps:
  - uses: actions/checkout@<full commit SHA>      # e.g. v4
  - uses: AlicanAkkus/pinder-action@<full commit SHA>
```
Also consider enabling GitHub's built-in secret scanning and push protection.

---

### L-8 — DOM sinks (`innerHTML`, inline `onclick`) fed with API data

- **Severity:** Low (defence in depth; not exploitable today)
- **Location:** `scripts.js:75-83,197`, `scripts_dump.js`, `scripts_summary.js:175`, `scripts_flight_details.js:58,84`

**Description.** Table rows and popups are built with template strings assigned to `innerHTML`, and the Details button uses an inline `onclick="openFlightDetails(${flight.flight_id}, …)"`. Every interpolated value is currently a number, a DECIMAL string or a server-generated datetime. `raw` (the only free-form field, limited to hex) is never rendered, so no XSS is possible. Any future text field (e.g. object names from `Objects`) rendered the same way would become stored XSS.

**Recommendation.** Build cells with `textContent` / `document.createElement`, and attach handlers with `addEventListener` (this also helps the CSP in M-4).

---

### I-1 — `window.open` without `noopener`

- **Location:** `scripts_flight_details.js:284,323`
- **Description.** Google Maps is opened with `window.open(url, "_blank")`, which gives the new tab a reference to this page via `window.opener`. The target is Google, so there is no practical risk.
- **Recommendation.** Use `window.open(url, "_blank", "noopener")` for hygiene.

### I-2 — Hosting account name disclosed in the schema file name

- **Location:** `SQL/dm66733_rocket_tracker.sql`
- **Description.** `dm66733` looks like the hosting account / DB prefix, which helps targeted attacks on the hosting panel if the repository is public.
- **Recommendation.** Rename to `SQL/schema.sql` and strip the phpMyAdmin header. Make sure the hosting panel uses a strong password and 2FA.

### I-3 — DB user privileges not documented or enforced

- **Description.** It is unknown whether the application's MySQL user has more than it needs.
- **Recommendation.** Grant only `SELECT, INSERT, UPDATE` on `Flights`, `Data` and `Objects`, with no `DROP`, `DELETE`, `ALTER`, `FILE` or `GRANT`. Ideally use a separate read-only user for the three read endpoints.

### I-4 — Lenient numeric parsing on ingest

- **Location:** `flight_addpoint.php` (`intval` / `floatval`)
- **Description.** `"12abc"` is accepted as `12` and `"abc"` as `0`. This was kept for firmware compatibility. It is not exploitable (values are range-checked and bound as numbers), but malformed packets are stored as plausible-looking data.
- **Recommendation.** Once the firmware format is confirmed, switch to `filter_var(…, FILTER_VALIDATE_INT/FLOAT)` and reject malformed values.

### I-5 — `hash_equals` reveals API key length

- **Description.** `hash_equals` returns early when lengths differ, so timing can reveal the key's length. With a long random key this is negligible.
- **Recommendation.** Accept. Optionally compare `hash('sha256', …)` of both values.

### I-6 — Synthetic-data clean-up deletes the object_id range 9001–9999

- **Location:** `tools/generate_synthetic_data.py` (`--clean`, `--clean-only`)
- **Description.** The generated `DELETE` statements remove **all** rows with `object_id` 9001–9999. If a real tracker ever uses an ID in that range, its data would be deleted.
- **Recommendation.** Make sure real devices don't use that range, and never run the generated SQL against production unless test data there is intended.

---

## 5. Issues fixed in the working tree (pending deploy)

These affect `main` and, presumably, production today. They are resolved by the uncommitted changes reviewed above (see H-2).

| ID   | Former severity | Issue on `main`                                                                                                                                                                                                         | Fix in working tree |
|------|-----------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------|
| F-1  | Medium          | **Log file publicly downloadable.** `error_log` is written in the web root (per `.gitignore`) and nothing blocks it; it can contain SQL errors, file paths and failed request data.                                     | `.htaccess` returns 403 for `error_log`, `*.log`, `*.sql`, `config*.php`, `db.php`, `.git`, `.idea` |
| F-2  | Medium          | **Type-juggling API key check.** `$_GET["api_key"] != $api_key` is a loose comparison: in PHP 8 two different numeric strings such as `"1e3"` and `"1000"` compare equal. This only matters for a numeric-looking key, but it is a latent bypass. | `hash_equals()` with string type check; empty/template keys rejected |
| F-3  | Medium          | **SQL built by string interpolation.** Safe only because every value was cast (`intval`/`floatval`/hex regex) first; one missed cast would be SQL injection.                                                              | Prepared statements with bound parameters in all endpoints |
| F-4  | Low             | **Error disclosure.** `die("Connection failed: " . $conn->connect_error)` sends DB host/user details to the client.                                                                                                     | Errors logged server-side; client gets `error` / HTTP 500 |
| F-5  | Low             | **Array parameters cause fatal errors.** `?raw[]=x` or `?api_key[]=x` triggers a PHP `TypeError`; with `display_errors` on, this reveals file paths.                                                                   | All parameters must be strings |
| F-6  | Low             | **Unhandled mode.** `flights.php?mode=foo` uses an undefined variable (warning, possible path disclosure) and returns `null`.                                                                                            | `400 Invalid mode` |
| F-7  | Low             | **Out-of-range and non-finite values** (`1e999`, latitude 500) reach the DB, where they are clipped or cause query errors.                                                                                              | Range checks matching column types |
| F-8  | Low             | **No `X-Content-Type-Options`** header.                                                                                                                                                                                  | `nosniff` set in `.htaccess` |

---

## 6. Recommended remediation order

1. **Deploy the working-tree fixes** (H-2), then check `error_log` / `config.php` / `db.php` return 403 and review any exposed log (F-1).
2. **Rotate the API key and DB password** if the log was reachable or the key was used over shared networks.
3. **Firmware roadmap:** TLS or per-packet HMAC, key out of the URL, per-device keys (H-1, M-1, M-2).
4. ~~Remove the `flight_sum.php` polling, and add limits and caching~~ (M-3, done in working tree). Ask the host about per-IP throttling.
5. **SRI + CSP + security headers:** HSTS after step 3, `X-Frame-Options`, `Options -Indexes` (M-4, L-2, L-3, L-5).
6. **Check the hosting platform:** PHP/MySQL versions, `display_errors`, DB user privileges (M-5, L-4, I-3).
7. **Housekeeping:** CI pinning, `textContent` rendering, InnoDB, schema file rename (L-7, L-8, I-2).

---

## 7. Disclosure note

This report lists open, exploitable weaknesses (notably H-1 and M-3). If the GitHub repository is public, consider keeping this file out of the repository, or committing it only after the High and Medium items are resolved.
