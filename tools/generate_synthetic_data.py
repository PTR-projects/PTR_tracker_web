#!/usr/bin/env python3
"""
Generate synthetic tracker data (Flights, Data, Objects) as an SQL file.

Each flight is simulated as a small rocket flight launched somewhere in Poland:
    pad wait -> ascent to apogee -> parachute descent with wind drift -> on ground

Timestamps are relative to the moment of import (`@now = NOW()`), so the data
always looks recent: some flights fall in the live view's 10-day window, some
are older (visible only in index_dump.html), and optionally one flight has just
landed so the live map shows a fresh "Data Age".

Synthetic objects use IDs from --object-id-start (default 9001) so they are easy
to remove again (see --clean / --clean-only).

Usage:
    python tools/generate_synthetic_data.py                    # -> synthetic_data.sql
    python tools/generate_synthetic_data.py --flights 20 --objects 5 --seed 42 --clean
    python tools/generate_synthetic_data.py --clean-only -o remove_synthetic.sql

Import:
    mysql -u <user> -p <database> < synthetic_data.sql
    (or phpMyAdmin -> Import; the whole file must run in one session)
"""

import argparse
import math
import random
import struct
import sys

# Launch sites well inside Poland (>= ~40 km from any border, away from the coast).
# Max simulated drift is ~15 km, so all positions stay in the country.
LAUNCH_SITES = [
    ("FM23 site",   50.337335, 19.521827),
    ("Warszawa",    52.2297,   21.0122),
    ("Krakow",      50.0647,   19.9450),
    ("Lodz",        51.7592,   19.4560),
    ("Poznan",      52.4064,   16.9252),
    ("Wroclaw",     51.1079,   17.0385),
    ("Lublin",      51.2465,   22.5684),
    ("Tczew",       54.0924,   18.7779),
    ("Bydgoszcz",   53.1235,   18.0084),
    ("Torun",       53.0138,   18.5984),
    ("Olsztyn",     53.7784,   20.4801),
    ("Bialystok",   53.1325,   23.1688),
    ("Kielce",      50.8661,   20.6286),
    ("Radom",       51.4027,   21.1471),
    ("Rzeszow",     50.0412,   21.9991),
    ("Czestochowa", 50.8118,   19.1203),
    ("Plock",       52.5463,   19.7065),
]

# Rough bounding box of Poland, used as a final sanity check
POLAND_BBOX = (49.0, 54.84, 14.12, 24.15)   # lat_min, lat_max, lon_min, lon_max

M_PER_DEG_LAT = 111_320.0
MIN_GAP_BETWEEN_FLIGHTS_S = 2 * 3600         # per object; server splits flights at 15 min
ROWS_PER_INSERT = 200


def offset_position(lat, lon, east_m, north_m):
    """Move a lat/lon point by metres east/north."""
    dlat = north_m / M_PER_DEG_LAT
    dlon = east_m / (M_PER_DEG_LAT * math.cos(math.radians(lat)))
    return lat + dlat, lon + dlon


def simulate_flight(rng, interval_s):
    """
    Simulate one flight. Returns a list of samples:
        (t_seconds_from_start, lat, lon, alt_m, sats, fix, vbat)
    """
    name, site_lat, site_lon = rng.choice(LAUNCH_SITES)
    # Random field within ~8 km of the site
    pad_lat, pad_lon = offset_position(site_lat, site_lon,
                                       rng.uniform(-8000, 8000), rng.uniform(-8000, 8000))
    ground_alt = rng.uniform(90, 320)                  # m ASL

    apogee_agl   = rng.uniform(400, 3000)              # m
    t_ascent     = math.sqrt(2 * apogee_agl / 9.81) * 1.3
    dual_deploy  = apogee_agl > 1200 and rng.random() < 0.6
    drogue_rate  = rng.uniform(15, 25)                 # m/s
    main_rate    = rng.uniform(4.5, 8.0)               # m/s
    main_alt_agl = rng.uniform(200, 400)
    if not dual_deploy:
        main_alt_agl = apogee_agl

    wind_speed = rng.uniform(1.5, 9.0)                 # m/s
    wind_dir   = rng.uniform(0, 2 * math.pi)           # direction the wind blows towards
    wind_e, wind_n = wind_speed * math.sin(wind_dir), wind_speed * math.cos(wind_dir)

    t_pad    = rng.uniform(120, 600)                   # waiting on the pad
    t_ground = rng.uniform(180, 900)                   # transmitting after landing

    vbat = rng.uniform(3.95, 4.20)
    vbat_drain = rng.uniform(0.00002, 0.00008)         # V per second

    samples = []
    t = 0.0
    east = north = 0.0          # drift from pad, metres
    alt_agl = 0.0
    landed_at = None
    last_fix_pos = None

    while True:
        # --- flight dynamics
        if t < t_pad:
            alt_agl = 0.0
        elif t < t_pad + t_ascent:
            x = (t - t_pad) / t_ascent
            alt_agl = apogee_agl * (1 - (1 - x) ** 2)
            # Rockets weathercock into the wind: small upwind drift on ascent
            east, north = -wind_e * 2.0 * x, -wind_n * 2.0 * x
        elif landed_at is None:
            t_desc = t - (t_pad + t_ascent)
            if dual_deploy:
                t_drogue = (apogee_agl - main_alt_agl) / drogue_rate
                if t_desc < t_drogue:
                    alt_agl = apogee_agl - drogue_rate * t_desc
                else:
                    alt_agl = main_alt_agl - main_rate * (t_desc - t_drogue)
            else:
                alt_agl = apogee_agl - main_rate * t_desc
            east += wind_e * interval_s
            north += wind_n * interval_s
            if alt_agl <= 0:
                alt_agl = 0.0
                landed_at = t
        elif t > landed_at + t_ground:
            break

        # --- GNSS: lose fix under boost acceleration, fewer sats in flight
        in_boost = t_pad <= t < t_pad + min(4.0, t_ascent)
        if in_boost and rng.random() < 0.7:
            fix, sats = 0, rng.randint(0, 4)
        else:
            fix = 3 if rng.random() < 0.93 else 2
            sats = rng.randint(9, 15) if alt_agl == 0 else rng.randint(6, 12)

        lat, lon = offset_position(pad_lat, pad_lon,
                                   east + rng.gauss(0, 2.0), north + rng.gauss(0, 2.0))
        alt = ground_alt + alt_agl + rng.gauss(0, 1.5)

        if fix == 0:
            # Without a fix the tracker keeps reporting its last known position
            lat, lon, alt = last_fix_pos if last_fix_pos else (lat, lon, alt)
        else:
            last_fix_pos = (lat, lon, alt)

        vbat_now = vbat - vbat_drain * t + rng.gauss(0, 0.005)
        samples.append((t, lat, lon, alt, sats, fix, vbat_now))

        t += interval_s + rng.uniform(-0.3, 0.3)

    duration = samples[-1][0]
    info = {"site": name, "apogee_agl": apogee_agl, "dual_deploy": dual_deploy,
            "wind": wind_speed, "duration": duration}
    return samples, info


def encode_raw(object_id, packet_no, lat, lon, alt, sats_fix, vbat, max_alt):
    """Plausible binary packet as hex (25 bytes -> 50 hex chars, limit is 100)."""
    payload = struct.pack("<HIiiiBHi",
                          object_id & 0xFFFF, packet_no & 0xFFFFFFFF,
                          int(round(lat * 1e7)), int(round(lon * 1e7)),
                          int(round(alt * 10)), sats_fix,
                          max(0, int(round(vbat * 100))), int(round(max_alt * 10)))
    return payload.hex().upper()


def plan_flights(rng, n_flights, object_ids, days, live):
    """Pick (object_id, end_offset_s) for each flight, keeping flights of one object apart."""
    plans = []
    if live and n_flights > 0:
        plans.append((rng.choice(object_ids), rng.uniform(10, 40)))   # just landed
    attempts = 0
    while len(plans) < n_flights:
        attempts += 1
        if attempts > 100_000:
            sys.exit("Could not place flights - increase --days or --objects")
        obj = rng.choice(object_ids)
        end_offset = rng.uniform(600, days * 86400)
        if all(o != obj or abs(e - end_offset) > MIN_GAP_BETWEEN_FLIGHTS_S for o, e in plans):
            plans.append((obj, end_offset))
    # Oldest first so flight_id grows with time, like real data
    return sorted(plans, key=lambda p: -p[1])


def fmt(v, decimals):
    return f"{v:.{decimals}f}"


def clean_sql(id_first, id_last):
    return [
        f"-- Remove previously generated synthetic data (object_id {id_first}..{id_last})",
        f"DELETE FROM Data    WHERE object_id BETWEEN {id_first} AND {id_last};",
        f"DELETE FROM Flights WHERE object_id BETWEEN {id_first} AND {id_last};",
        f"DELETE FROM Objects WHERE object_id BETWEEN {id_first} AND {id_last};",
        "",
    ]


def generate(args):
    rng = random.Random(args.seed)
    object_ids = list(range(args.object_id_start, args.object_id_start + args.objects))
    # Clean-up always covers the whole reserved block, not just this run's objects
    clean_first, clean_last = args.object_id_start, args.object_id_start + 998

    out = [
        "-- Synthetic PTR tracker data, generated by tools/generate_synthetic_data.py",
        f"-- flights={args.flights} objects={args.objects} days={args.days} "
        f"interval={args.interval}s seed={args.seed}",
        "-- Timestamps are relative to the import time.",
        "",
    ]
    if args.clean or args.clean_only:
        out += clean_sql(clean_first, clean_last)
    if args.clean_only:
        return out, []

    out += [
        "SET @now = NOW();",
        "",
        "INSERT INTO Objects (object_id, object_name) VALUES",
        ",\n".join(f"  ({oid}, 'SIM-{i + 1}')" for i, oid in enumerate(object_ids)),
        "ON DUPLICATE KEY UPDATE object_name = VALUES(object_name);",
        "",
    ]

    summary = []
    lat_min, lat_max, lon_min, lon_max = POLAND_BBOX
    for n, (obj, end_offset) in enumerate(plan_flights(rng, args.flights, object_ids, args.days, not args.no_live), 1):
        samples, info = simulate_flight(rng, args.interval)
        duration = samples[-1][0]
        start_offset = end_offset + duration

        def ago(t):  # seconds before @now for sample time t
            return int(round(start_offset - t))

        # Drop a few packets (radio losses); packet counter still advances
        packet_no = rng.randint(0, 50)
        rows = []
        max_alt = -1e9
        last_fixed = None
        for i, (t, lat, lon, alt, sats, fix, vbat) in enumerate(samples):
            packet_no += 1
            if not (lat_min <= lat <= lat_max and lon_min <= lon <= lon_max):
                sys.exit(f"Generated position outside Poland: {lat}, {lon}")
            is_edge = i == 0 or i == len(samples) - 1
            if not is_edge and rng.random() < args.loss:
                continue
            max_alt = max(max_alt, alt)
            sats_fix = (fix << 6) | sats
            raw = encode_raw(obj, packet_no, lat, lon, alt, sats_fix, vbat, max_alt)
            rows.append((ago(t), packet_no, lat, lon, alt, sats, fix, vbat, max_alt, raw))
            if fix != 0:
                last_fixed = (lat, lon, alt)

        first = rows[0]
        last_lat, last_lon, last_alt = last_fixed
        out += [
            f"-- Flight {n}: object {obj}, {info['site']}, apogee {info['apogee_agl']:.0f} m AGL, "
            f"{'dual' if info['dual_deploy'] else 'single'} deploy, wind {info['wind']:.1f} m/s, "
            f"{len(rows)} packets",
            "INSERT INTO Flights (object_id, start_time, end_time, last_latitude, last_longitude, last_altitude) VALUES",
            f"  ({obj}, @now - INTERVAL {first[0]} SECOND, @now - INTERVAL {rows[-1][0]} SECOND, "
            f"{fmt(last_lat, 6)}, {fmt(last_lon, 6)}, {fmt(last_alt, 2)});",
            "SET @fid = LAST_INSERT_ID();",
        ]
        for chunk_start in range(0, len(rows), ROWS_PER_INSERT):
            chunk = rows[chunk_start:chunk_start + ROWS_PER_INSERT]
            out.append("INSERT INTO Data (flight_id, object_id, datetime, packet_no, latitude, longitude, altitude, "
                       "gnss_sats, gnss_fix, vbat, max_altitude, raw) VALUES")
            out.append(",\n".join(
                f"  (@fid, {obj}, @now - INTERVAL {a} SECOND, {p}, {fmt(la, 6)}, {fmt(lo, 6)}, {fmt(al, 2)}, "
                f"{s}, {f}, {fmt(v, 2)}, {fmt(m, 2)}, '{r}')"
                for a, p, la, lo, al, s, f, v, m, r in chunk) + ";")
        out.append("")
        summary.append((n, obj, info["site"], rows[-1][0], info["apogee_agl"], len(rows)))

    return out, summary


def human_age(seconds):
    if seconds < 3600:
        return f"{seconds // 60} min"
    if seconds < 86400:
        return f"{seconds / 3600:.1f} h"
    return f"{seconds / 86400:.1f} days"


def main():
    p = argparse.ArgumentParser(description="Generate synthetic PTR tracker data as SQL (positions in Poland).")
    p.add_argument("-o", "--output", default="synthetic_data.sql", help="output SQL file ('-' for stdout)")
    p.add_argument("--flights", type=int, default=10, help="number of flights (default 10)")
    p.add_argument("--objects", type=int, default=4, help="number of tracker objects (default 4)")
    p.add_argument("--days", type=float, default=14, help="spread flights over the last N days (default 14)")
    p.add_argument("--interval", type=float, default=2.0, help="seconds between packets (default 2)")
    p.add_argument("--loss", type=float, default=0.03, help="fraction of packets dropped (default 0.03)")
    p.add_argument("--object-id-start", type=int, default=9001, help="first synthetic object_id (default 9001)")
    p.add_argument("--seed", type=int, default=None, help="random seed for reproducible output")
    p.add_argument("--no-live", action="store_true", help="don't include a flight that just landed")
    p.add_argument("--clean", action="store_true", help="delete earlier synthetic data before inserting")
    p.add_argument("--clean-only", action="store_true", help="only output the DELETE statements")
    args = p.parse_args()

    if args.objects < 1 or args.objects > 999:
        p.error("--objects must be between 1 and 999")
    if args.flights < 0 or args.interval <= 0 or not 0 <= args.loss < 1 or args.days <= 0:
        p.error("invalid --flights/--interval/--loss/--days")

    lines, summary = generate(args)
    sql = "\n".join(lines) + "\n"

    if args.output == "-":
        sys.stdout.write(sql)
    else:
        with open(args.output, "w", encoding="utf-8", newline="\n") as f:
            f.write(sql)

    log = sys.stderr
    for n, obj, site, end_ago, apogee, packets in summary:
        print(f"  flight {n:>2}: object {obj}  {site:<12} apogee {apogee:5.0f} m  "
              f"{packets:4d} packets  ended {human_age(end_ago)} ago", file=log)
    total = sum(s[5] for s in summary)
    target = "stdout" if args.output == "-" else args.output
    print(f"Wrote {len(summary)} flights, {total} data points -> {target}", file=log)


if __name__ == "__main__":
    main()
