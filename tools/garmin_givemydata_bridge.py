#!/usr/bin/env python3
"""Fetch Garmin wellness data via garmin-givemydata and emit the local bridge JSON.

This wrapper keeps the existing Statistics for Strava wellness import format while
using garmin-givemydata as the upstream fetcher.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sqlite3
import subprocess
import sys
from pathlib import Path
from typing import Any, Iterable

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_BRIDGE_OUTPUT = REPO_ROOT / "storage/imports/wellness/garmin-bridge.json"
DEFAULT_DATA_DIR = REPO_ROOT / "storage/imports/wellness/givemydata"
DEFAULT_LOOKBACK_DAYS = 30
PLACEHOLDER_VALUES = {
    "replace-me",
    "your-email@example.com",
    "your-password",
    "your-email",
    "your-password-here",
    "changeme",
}


def is_placeholder(value: str | None) -> bool:
    if value is None:
        return True
    return value.strip().lower() in PLACEHOLDER_VALUES


def load_dotenv_file(path: Path) -> None:
    if not path.exists():
        return

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if value and value[0] == value[-1] and value[0] in {'"', "'"}:
            value = value[1:-1]

        current_value = os.environ.get(key)
        if current_value and not is_placeholder(current_value):
            continue

        os.environ[key] = value


def bootstrap_environment() -> None:
    load_dotenv_file(REPO_ROOT / ".env")
    load_dotenv_file(REPO_ROOT / ".env.local")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Fetch Garmin wellness data via garmin-givemydata and write garmin-bridge.json"
    )
    parser.add_argument("--days", type=int, help="Fetch the last N days (inclusive).")
    parser.add_argument("--since", help="Fetch from YYYY-MM-DD.")
    parser.add_argument("--full", action="store_true", help="Force a full historical fetch.")
    parser.add_argument("--skip-fetch", action="store_true", help="Do not run garmin-givemydata; only convert the existing garmin.db.")
    parser.add_argument("--visible", action="store_true", help="Show the upstream browser window for debugging.")
    parser.add_argument("--pretty", action="store_true", help="Pretty-print the output JSON.")
    parser.add_argument("--verbose", action="store_true", help="Print one line per generated bridge record.")
    parser.add_argument(
        "--data-dir",
        default=os.getenv("GARMIN_GIVEMYDATA_DIR", str(DEFAULT_DATA_DIR)),
        help="Where garmin-givemydata should store its SQLite DB/browser profile.",
    )
    parser.add_argument(
        "--bridge-output",
        default=os.getenv("GARMIN_OUTPUT_PATH", str(DEFAULT_BRIDGE_OUTPUT)),
        help="Path to the bridge JSON file to generate.",
    )
    args = parser.parse_args()

    if args.full and (args.days or args.since):
        parser.error("--full cannot be combined with --days or --since")

    if args.days and args.since:
        parser.error("Use either --days or --since, not both")

    return args


def resolve_path(value: str) -> Path:
    path = Path(value)
    if path.is_absolute():
        return path
    return (REPO_ROOT / path).resolve()


def parse_date(value: str) -> dt.date:
    try:
        return dt.date.fromisoformat(value)
    except ValueError as exc:
        raise SystemExit(f'Invalid date "{value}". Use YYYY-MM-DD.') from exc


def daterange(start_date: dt.date, end_date: dt.date) -> Iterable[dt.date]:
    current = start_date
    while current <= end_date:
        yield current
        current += dt.timedelta(days=1)


def determine_date_range(args: argparse.Namespace) -> tuple[dt.date, dt.date]:
    end_date = dt.date.today()
    if args.full:
        return dt.date(2010, 1, 1), end_date
    if args.since:
        return parse_date(args.since), end_date
    if args.days:
        return end_date - dt.timedelta(days=max(args.days - 1, 0)), end_date
    return end_date - dt.timedelta(days=DEFAULT_LOOKBACK_DAYS - 1), end_date


def ensure_credentials() -> None:
    email = os.environ.get("GARMIN_EMAIL")
    password = os.environ.get("GARMIN_PASSWORD")
    if is_placeholder(email) or is_placeholder(password):
        raise SystemExit(
            "GARMIN_EMAIL and GARMIN_PASSWORD must be set to real values in your environment or .env.local before using garmin-givemydata."
        )


def run_givemydata_fetch(args: argparse.Namespace, data_dir: Path) -> None:
    ensure_credentials()

    cmd = [
        "uv",
        "tool",
        "run",
        "--from",
        "garmin-givemydata",
        "garmin-givemydata",
        "--profile",
        "health",
        "--no-files",
    ]
    if args.full:
        cmd.append("--full")
    elif args.since:
        cmd.extend(["--since", args.since])
    elif args.days:
        cmd.extend(["--days", str(args.days)])
    if args.visible:
        cmd.append("--visible")

    env = os.environ.copy()
    env["GARMIN_DATA_DIR"] = str(data_dir)
    data_dir.mkdir(parents=True, exist_ok=True)

    print("Running garmin-givemydata fetch...")
    result = subprocess.run(cmd, env=env, cwd=str(REPO_ROOT), check=False)
    if result.returncode != 0:
        raise SystemExit(result.returncode)


def connect_db(db_path: Path) -> sqlite3.Connection:
    if not db_path.exists():
        raise SystemExit(f"Expected Garmin database at {db_path}, but it does not exist yet.")
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    return conn


def find_first_numeric(payload: Any, candidate_keys: set[str], *, as_float: bool = False) -> float | int | None:
    if isinstance(payload, dict):
        for key, value in payload.items():
            if key in candidate_keys:
                coerced = coerce_float(value) if as_float else coerce_int(value)
                if coerced is not None:
                    return coerced

        for value in payload.values():
            found = find_first_numeric(value, candidate_keys, as_float=as_float)
            if found is not None:
                return found

    if isinstance(payload, list):
        for item in payload:
            found = find_first_numeric(item, candidate_keys, as_float=as_float)
            if found is not None:
                return found

    return None


def coerce_int(value: Any) -> int | None:
    if value in (None, ""):
        return None
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, (int, float)):
        return int(round(float(value)))
    if isinstance(value, str):
        cleaned = value.strip()
        if not cleaned:
            return None
        try:
            return int(round(float(cleaned)))
        except ValueError:
            return None
    return None


def coerce_float(value: Any) -> float | None:
    if value in (None, ""):
        return None
    if isinstance(value, bool):
        return float(value)
    if isinstance(value, (int, float)):
        return float(value)
    if isinstance(value, str):
        cleaned = value.strip()
        if not cleaned:
            return None
        try:
            return float(cleaned)
        except ValueError:
            return None
    return None


def decode_json(raw_value: str | None) -> dict[str, Any] | None:
    if not raw_value:
        return None
    try:
        decoded = json.loads(raw_value)
        return decoded if isinstance(decoded, dict) else None
    except Exception:
        return None


def extract_sleep_score(raw_sleep: dict[str, Any] | None) -> int | None:
    if raw_sleep is None:
        return None

    for path in (
        ["dailySleepDTO", "sleepScores", "overall", "value"],
        ["dailySleepDTO", "sleepScores", "overallScore", "value"],
        ["sleepScores", "overall", "value"],
        ["overallSleepScore", "value"],
        ["overallSleepScore"],
        ["sleepScore"],
    ):
        value = get_nested_value(raw_sleep, path)
        coerced = coerce_int(value)
        if coerced is not None:
            return coerced

    result = find_first_numeric(raw_sleep, {"overallSleepScore", "sleepScore", "overallScore"})
    return None if result is None else int(result)


def get_nested_value(payload: Any, path: list[str]) -> Any | None:
    current = payload
    for key in path:
        if not isinstance(current, dict) or key not in current:
            return None
        current = current[key]
    return current


def fetch_records(conn: sqlite3.Connection, start_date: dt.date, end_date: dt.date) -> list[dict[str, Any]]:
    query = """
        WITH days AS (
            SELECT calendar_date AS day FROM daily_summary WHERE calendar_date BETWEEN ? AND ?
            UNION
            SELECT calendar_date AS day FROM sleep WHERE calendar_date BETWEEN ? AND ?
            UNION
            SELECT calendar_date AS day FROM hrv WHERE calendar_date BETWEEN ? AND ?
        )
        SELECT
            days.day AS day,
            daily_summary.total_steps AS steps_count,
            daily_summary.raw_json AS daily_summary_raw,
            sleep.sleep_time_seconds AS sleep_duration_seconds,
            sleep.raw_json AS sleep_raw,
            hrv.last_night_avg AS hrv,
            hrv.raw_json AS hrv_raw
        FROM days
        LEFT JOIN daily_summary ON daily_summary.calendar_date = days.day
        LEFT JOIN sleep ON sleep.calendar_date = days.day
        LEFT JOIN hrv ON hrv.calendar_date = days.day
        ORDER BY days.day ASC
    """
    rows = conn.execute(
        query,
        (
            start_date.isoformat(),
            end_date.isoformat(),
            start_date.isoformat(),
            end_date.isoformat(),
            start_date.isoformat(),
            end_date.isoformat(),
        ),
    ).fetchall()

    records: list[dict[str, Any]] = []
    for row in rows:
        raw_daily = decode_json(row["daily_summary_raw"])
        raw_sleep = decode_json(row["sleep_raw"])
        raw_hrv = decode_json(row["hrv_raw"])
        record = {
            "date": row["day"],
            "source": "garmin",
            "steps": coerce_int(row["steps_count"]),
            "sleepDurationInSeconds": coerce_int(row["sleep_duration_seconds"]),
            "sleepScore": extract_sleep_score(raw_sleep),
            "hrv": coerce_float(row["hrv"]),
            "payload": {
                "source": "garmin-givemydata",
                "dailySummary": raw_daily,
                "sleep": raw_sleep,
                "hrv": raw_hrv,
            },
        }
        records.append(record)
    return records


def write_records(path: Path, records: list[dict[str, Any]], *, pretty: bool) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        json.dump(records, handle, indent=2 if pretty else None, ensure_ascii=False)
        handle.write("\n")


def main() -> int:
    bootstrap_environment()
    args = parse_args()

    data_dir = resolve_path(args.data_dir)
    bridge_output = resolve_path(args.bridge_output)
    start_date, end_date = determine_date_range(args)

    if not args.skip_fetch:
        run_givemydata_fetch(args, data_dir)

    db_path = data_dir / "garmin.db"
    with connect_db(db_path) as conn:
        records = fetch_records(conn, start_date, end_date)

    write_records(bridge_output, records, pretty=args.pretty)

    print(f"Wrote {len(records)} bridge record(s) to {bridge_output}")
    if args.verbose:
        for record in records:
            print(
                f"- {record['date']}: steps={record['steps']}, sleep={record['sleepDurationInSeconds']}, sleepScore={record['sleepScore']}, hrv={record['hrv']}"
            )

    print("Next run: docker compose run --rm php-cli bin/console app:strava:import-data && docker compose run --rm php-cli bin/console app:strava:build-files")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
