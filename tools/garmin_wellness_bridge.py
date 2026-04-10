#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "garminconnect",
# ]
# ///

"""Fetch Garmin wellness data and write a bridge JSON file for Statistics for Strava."""

from __future__ import annotations

import argparse
import datetime as dt
import getpass
import json
import os
from pathlib import Path
from typing import Any, Iterable

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_OUTPUT = REPO_ROOT / "storage/imports/wellness/garmin-bridge.json"
DEFAULT_TOKENSTORE = REPO_ROOT / "storage/imports/wellness/garmin_tokens.json"
DEFAULT_ALL_START = dt.date(2010, 1, 1)
DEFAULT_LOOKBACK_DAYS = 30
DEFAULT_REFRESH_OVERLAP_DAYS = 7
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
        description="Fetch Garmin wellness data and write storage/imports/wellness/garmin-bridge.json"
    )
    parser.add_argument("--email", help="Garmin account email. Defaults to GARMIN_EMAIL.")
    parser.add_argument("--password", help="Garmin account password. Defaults to GARMIN_PASSWORD.")
    parser.add_argument("--start-date", help="Inclusive start date in YYYY-MM-DD format.")
    parser.add_argument("--end-date", help="Inclusive end date in YYYY-MM-DD format. Defaults to today.")
    parser.add_argument("--days", type=int, help="Fetch the last N days (inclusive).")
    parser.add_argument("--all", action="store_true", help="Fetch from 2010-01-01 until today.")
    parser.add_argument(
        "--output",
        default=os.getenv("GARMIN_OUTPUT_PATH", str(DEFAULT_OUTPUT)),
        help="Path to the bridge JSON file. Defaults to storage/imports/wellness/garmin-bridge.json",
    )
    parser.add_argument(
        "--tokenstore",
        default=os.getenv("GARMIN_TOKENSTORE") or os.getenv("GARMINTOKENS") or str(DEFAULT_TOKENSTORE),
        help="Path to a Garmin tokenstore JSON file, or raw token JSON. Defaults to storage/imports/wellness/garmin_tokens.json",
    )
    parser.add_argument(
        "--di-token",
        default=os.getenv("GARMIN_DI_TOKEN"),
        help="Browser-sourced Garmin DI access token. Can also be provided via GARMIN_DI_TOKEN.",
    )
    parser.add_argument(
        "--di-refresh-token",
        default=os.getenv("GARMIN_DI_REFRESH_TOKEN"),
        help="Browser-sourced Garmin DI refresh token. Can also be provided via GARMIN_DI_REFRESH_TOKEN.",
    )
    parser.add_argument(
        "--di-client-id",
        default=os.getenv("GARMIN_DI_CLIENT_ID"),
        help="Browser-sourced Garmin DI client id. Can also be provided via GARMIN_DI_CLIENT_ID.",
    )
    parser.add_argument(
        "--jwt-web",
        default=os.getenv("GARMIN_JWT_WEB"),
        help="Browser cookie value for JWT_WEB. Can also be provided via GARMIN_JWT_WEB.",
    )
    parser.add_argument(
        "--csrf-token",
        default=os.getenv("GARMIN_CSRF_TOKEN"),
        help="Optional Garmin connect-csrf-token value to pair with JWT_WEB. Can also be provided via GARMIN_CSRF_TOKEN.",
    )
    parser.add_argument(
        "--debug-auth",
        action="store_true",
        help="Print the raw Garmin social profile and user settings used for auth context/debugging.",
    )
    parser.add_argument(
        "--pretty",
        action="store_true",
        help="Pretty-print the output JSON instead of writing a compact file.",
    )
    parser.add_argument(
        "--no-merge",
        action="store_true",
        help="Overwrite the output file with only the fetched date range instead of merging by day.",
    )
    parser.add_argument("--verbose", action="store_true", help="Print one line per fetched day.")

    args = parser.parse_args()

    if args.all and (args.start_date or args.days):
        parser.error("--all cannot be combined with --start-date or --days")

    if args.start_date and args.days:
        parser.error("Use either --start-date or --days, not both")

    return args


def parse_date(value: str) -> dt.date:
    try:
        return dt.date.fromisoformat(value)
    except ValueError as exc:
        raise SystemExit(f'Invalid date "{value}". Use YYYY-MM-DD.') from exc


def resolve_date_range(args: argparse.Namespace, output_path: Path) -> tuple[dt.date, dt.date]:
    end_date = parse_date(args.end_date) if args.end_date else dt.date.today()

    if args.all:
        start_date = DEFAULT_ALL_START
    elif args.start_date:
        start_date = parse_date(args.start_date)
    elif args.days:
        start_date = end_date - dt.timedelta(days=max(args.days - 1, 0))
    else:
        existing_dates = sorted(load_existing_records(output_path).keys())
        if existing_dates:
            latest_date = parse_date(existing_dates[-1])
            start_date = latest_date - dt.timedelta(days=DEFAULT_REFRESH_OVERLAP_DAYS - 1)
        else:
            start_date = end_date - dt.timedelta(days=DEFAULT_LOOKBACK_DAYS - 1)

    if start_date > end_date:
        raise SystemExit("Start date cannot be after end date.")

    return start_date, end_date


def daterange(start_date: dt.date, end_date: dt.date) -> Iterable[dt.date]:
    current = start_date
    while current <= end_date:
        yield current
        current += dt.timedelta(days=1)


class GarminBridgeError(RuntimeError):
    pass


def build_inline_tokenstore(di_token: str | None, di_refresh_token: str | None, di_client_id: str | None) -> str | None:
    payload = {
        "di_token": (di_token or "").strip() or None,
        "di_refresh_token": (di_refresh_token or "").strip() or None,
        "di_client_id": (di_client_id or "").strip() or None,
    }

    if not payload["di_token"]:
        return None

    return json.dumps(payload)


def resolve_tokenstore(value: str | None) -> str | None:
    if not value:
        return None

    stripped = value.strip()
    if not stripped:
        return None

    if len(stripped) > 512 or stripped.startswith("{"):
        return stripped

    path = Path(stripped)
    if not path.is_absolute():
        path = (REPO_ROOT / path).resolve()

    return str(path)


def tokenstore_available(tokenstore: str | None) -> bool:
    if not tokenstore:
        return False

    stripped = tokenstore.strip()
    if not stripped:
        return False

    if len(stripped) > 512 or stripped.startswith("{"):
        return True

    return Path(stripped).exists()


def load_auth_context(api: Any) -> dict[str, Any]:
    profile = api.client.connectapi("/userprofile-service/socialProfile")
    settings = api.client.connectapi(api.garmin_connect_user_settings_url)

    if isinstance(profile, dict):
        api.display_name = profile.get("displayName") or api.display_name
        api.full_name = profile.get("fullName", "")

    if isinstance(settings, dict):
        api.unit_system = settings.get("userData", {}).get("measurementSystem")

    return {
        "displayName": getattr(api, "display_name", None),
        "fullName": getattr(api, "full_name", None),
        "unitSystem": getattr(api, "unit_system", None),
        "socialProfile": profile,
        "userSettings": settings,
    }


def create_api(
    email: str,
    password: str,
    tokenstore: str | None,
    jwt_web: str | None = None,
    csrf_token: str | None = None,
):
    try:
        from garminconnect import Garmin
    except ImportError as exc:
        raise GarminBridgeError(
            "The 'garminconnect' package is not available. Run this script with 'uv run tools/garmin_wellness_bridge.py ...'."
        ) from exc

    api = Garmin(email or None, password or None)
    if jwt_web:
        api.client.jwt_web = jwt_web.strip()
        if csrf_token:
            api.client.csrf_token = csrf_token.strip()
        load_auth_context(api)
        return api

    login = getattr(api, "login")

    def prompt_mfa_code() -> str:
        code = os.getenv("GARMIN_MFA_CODE")
        if code:
            return code
        return input("Enter Garmin MFA code: ").strip()

    for kwargs in (
        {"mfa_callback": prompt_mfa_code},
        {"prompt_mfa": prompt_mfa_code},
        {},
    ):
        try:
            login(tokenstore=tokenstore, **kwargs)
            return api
        except TypeError:
            continue
        except Exception as exc:
            if kwargs:
                continue
            message = str(exc)
            if any(token in message for token in ("429", "403", "Cloudflare", "Rate Limit")):
                raise GarminBridgeError(
                    "Garmin is blocking or rate-limiting the login request. If you can capture browser auth from a legitimate Garmin web session, first try JWT_WEB via GARMIN_JWT_WEB or --jwt-web. DI tokens also work via GARMIN_DI_TOKEN (and ideally GARMIN_DI_REFRESH_TOKEN plus GARMIN_DI_CLIENT_ID). Otherwise wait a while before retrying and start with a small run like '--days 7'."
                ) from exc
            raise GarminBridgeError(f"Garmin login failed: {message}") from exc

    login()
    return api


def call_api(api: Any, method_names: list[str], day: dt.date, *, suppress_errors: bool = False) -> Any | None:
    last_error: Exception | None = None

    for method_name in method_names:
        method = getattr(api, method_name, None)
        if not callable(method):
            continue

        for args in ((day.isoformat(),), (day,), ()):
            try:
                return method(*args)
            except TypeError:
                continue
            except Exception as exc:
                last_error = exc
                break

    if last_error is not None and not suppress_errors:
        raise GarminBridgeError(f"Failed to fetch Garmin data for {day.isoformat()}: {last_error}") from last_error

    return None


def get_nested_value(payload: Any, path: list[str]) -> Any | None:
    current = payload
    for key in path:
        if not isinstance(current, dict) or key not in current:
            return None
        current = current[key]
    return current


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


def extract_steps(summary: Any) -> int | None:
    for path in (["totalSteps"], ["steps"], ["wellnessData", "steps"]):
        value = coerce_int(get_nested_value(summary, path))
        if value is not None:
            return value

    result = find_first_numeric(summary, {"totalSteps", "steps", "stepCount"})
    return None if result is None else int(result)


def extract_sleep_duration(sleep_data: Any) -> int | None:
    for path in (
        ["dailySleepDTO", "sleepTimeSeconds"],
        ["sleepTimeSeconds"],
        ["sleepDurationInSeconds"],
        ["sleepDuration"],
        ["durationInSeconds"],
    ):
        value = coerce_int(get_nested_value(sleep_data, path))
        if value is not None:
            return value

    result = find_first_numeric(
        sleep_data,
        {"sleepTimeSeconds", "sleepDurationInSeconds", "sleepDuration", "durationInSeconds", "totalSleepSeconds"},
    )
    return None if result is None else int(result)


def extract_sleep_score(sleep_data: Any) -> int | None:
    for path in (
        ["dailySleepDTO", "sleepScores", "overall", "value"],
        ["dailySleepDTO", "sleepScores", "overallScore", "value"],
        ["sleepScores", "overall", "value"],
        ["overallSleepScore", "value"],
        ["overallSleepScore"],
        ["sleepScore"],
    ):
        value = coerce_int(get_nested_value(sleep_data, path))
        if value is not None:
            return value

    result = find_first_numeric(sleep_data, {"overallSleepScore", "sleepScore", "overallScore"})
    return None if result is None else int(result)


def extract_hrv(hrv_data: Any) -> float | None:
    for path in (
        ["hrvSummary", "lastNightAvg"],
        ["lastNightAvg"],
        ["overnightAvg"],
        ["nightlyAvg"],
        ["average"],
        ["weeklyAvg"],
    ):
        value = coerce_float(get_nested_value(hrv_data, path))
        if value is not None:
            return value

    result = find_first_numeric(hrv_data, {"lastNightAvg", "overnightAvg", "nightlyAvg", "average", "weeklyAvg"}, as_float=True)
    return None if result is None else float(result)


def make_json_safe(value: Any) -> Any:
    if value is None or isinstance(value, (str, int, float, bool)):
        return value

    if isinstance(value, (dt.date, dt.datetime)):
        return value.isoformat()

    if isinstance(value, dict):
        return {str(key): make_json_safe(item) for key, item in value.items()}

    if isinstance(value, (list, tuple, set)):
        return [make_json_safe(item) for item in value]

    return str(value)


def build_record(day: dt.date, summary: Any, sleep_data: Any, hrv_data: Any) -> dict[str, Any] | None:
    steps = extract_steps(summary)
    sleep_duration = extract_sleep_duration(sleep_data)
    sleep_score = extract_sleep_score(sleep_data)
    hrv = extract_hrv(hrv_data)

    if steps is None and sleep_duration is None and sleep_score is None and hrv is None:
        return None

    payload: dict[str, Any] = {
        "source": "garminconnect-python-bridge",
        "summary": make_json_safe(summary),
        "sleep": make_json_safe(sleep_data),
        "hrvData": make_json_safe(hrv_data),
    }

    return {
        "date": day.isoformat(),
        "source": "garmin",
        "steps": steps,
        "sleepDurationInSeconds": sleep_duration,
        "sleepScore": sleep_score,
        "hrv": hrv,
        "payload": payload,
    }


def load_existing_records(output_path: Path) -> dict[str, dict[str, Any]]:
    if not output_path.exists():
        return {}

    decoded = json.loads(output_path.read_text(encoding="utf-8"))
    if isinstance(decoded, dict):
        decoded = decoded.get("records", [])

    if not isinstance(decoded, list):
        raise GarminBridgeError(f"Existing bridge file at {output_path} must contain a list of records.")

    records: dict[str, dict[str, Any]] = {}
    for item in decoded:
        if isinstance(item, dict) and isinstance(item.get("date"), str):
            records[item["date"]] = item
    return records


def write_records(output_path: Path, records: dict[str, dict[str, Any]], *, pretty: bool) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    ordered = [records[key] for key in sorted(records.keys())]
    with output_path.open("w", encoding="utf-8") as handle:
        json.dump(ordered, handle, indent=2 if pretty else None, ensure_ascii=False)
        handle.write("\n")


def get_credentials(args: argparse.Namespace, tokenstore: str | None) -> tuple[str, str]:
    email = args.email or os.getenv("GARMIN_EMAIL") or ""
    password = args.password or os.getenv("GARMIN_PASSWORD") or ""

    if is_placeholder(email):
        email = ""

    if is_placeholder(password):
        password = ""

    if tokenstore_available(tokenstore) and (not email or not password):
        return email, password

    if not email:
        email = input("Garmin email: ").strip()

    if not password:
        password = getpass.getpass("Garmin password: ")

    if not email or not password:
        raise SystemExit("Garmin email and password are required.")

    return email, password


def main() -> int:
    bootstrap_environment()
    args = parse_args()

    output_path = Path(args.output)
    if not output_path.is_absolute():
        output_path = (REPO_ROOT / output_path).resolve()

    tokenstore = build_inline_tokenstore(args.di_token, args.di_refresh_token, args.di_client_id)
    if tokenstore is None:
        tokenstore = resolve_tokenstore(args.tokenstore)

    start_date, end_date = resolve_date_range(args, output_path)
    email, password = get_credentials(args, tokenstore)

    if args.jwt_web:
        print("Using browser JWT_WEB cookie...")
    elif tokenstore and args.di_token:
        print("Using browser-sourced Garmin token payload...")
    else:
        print(f"Logging into Garmin for {email}...")
    api = create_api(email, password, tokenstore, args.jwt_web, args.csrf_token)

    if args.debug_auth:
        print("Auth context snapshot:")
        print(json.dumps(load_auth_context(api), indent=2, ensure_ascii=False))

    fetched: dict[str, dict[str, Any]] = {}
    total_days = (end_date - start_date).days + 1
    print(f"Fetching wellness data for {total_days} day(s): {start_date.isoformat()} → {end_date.isoformat()}")

    for day in daterange(start_date, end_date):
        summary = call_api(api, ["get_user_summary", "get_stats"], day)
        sleep_data = call_api(api, ["get_sleep_data"], day, suppress_errors=True)
        hrv_data = call_api(api, ["get_hrv_data"], day, suppress_errors=True)

        record = build_record(day, summary, sleep_data, hrv_data)
        if record is None:
            if args.verbose:
                print(f"- {day.isoformat()}: no usable wellness metrics")
            continue

        fetched[record["date"]] = record
        if args.verbose:
            steps = record.get("steps")
            sleep_score = record.get("sleepScore")
            hrv = record.get("hrv")
            print(f"- {day.isoformat()}: steps={steps}, sleepScore={sleep_score}, hrv={hrv}")

    if args.no_merge:
        merged = fetched
    else:
        merged = load_existing_records(output_path)
        merged.update(fetched)

    write_records(output_path, merged, pretty=args.pretty)
    print(f"Wrote {len(merged)} record(s) to {output_path}")
    print("Next run: docker compose run --rm php-cli bin/console app:strava:import-data && docker compose run --rm php-cli bin/console app:strava:build-files")

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        raise SystemExit("Interrupted.")
    except GarminBridgeError as exc:
        raise SystemExit(str(exc))
