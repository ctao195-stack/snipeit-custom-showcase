#!/usr/bin/env python3
from __future__ import annotations

import argparse
import importlib.util
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path


ALERT_LOG_NAME = "alerts.log"
LATEST_ALERT_NAME = "latest-alert.txt"
AUTH_RETRY_SECONDS = 300
EXCEL_LOCK_RETRY_SECONDS = 60
ERROR_RETRY_SECONDS = 60
RATE_LIMIT_MIN_SECONDS = 60
MAX_BACKOFF_SECONDS = 300


def load_sync_module():
    base_dir = Path(__file__).resolve().parent
    sync_path = base_dir / "sync_snipeit_activity_to_excel.py"
    spec = importlib.util.spec_from_file_location("snipeit_excel_sync", sync_path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Cannot load {sync_path}")

    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run the Snipe-IT to Excel sync continuously."
    )
    parser.add_argument("--env", default=".env.snipeit-excel")
    parser.add_argument("--interval", type=int, default=10)
    return parser.parse_args()


def log(message: str) -> None:
    stamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{stamp}] {message}", flush=True)


def alert(config, message: str, throttle_key: str | None = None, throttle_seconds: int = 300) -> None:
    now = time.time()
    if throttle_key:
        last_alert = getattr(alert, "_last_alerts", {})
        if now - last_alert.get(throttle_key, 0) < throttle_seconds:
            return
        last_alert[throttle_key] = now
        setattr(alert, "_last_alerts", last_alert)

    stamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{stamp}] ALERT: {message}"
    log(f"ALERT: {message}")

    try:
        alert_dir = config.state_path.parent
        alert_dir.mkdir(parents=True, exist_ok=True)
        with (alert_dir / ALERT_LOG_NAME).open("a", encoding="utf-8") as handle:
            handle.write(line + "\n")
        (alert_dir / LATEST_ALERT_NAME).write_text(line + "\n", encoding="utf-8")
    except OSError as exc:
        log(f"Could not write alert log: {exc}")


def run_once(module, config, session) -> tuple[int, int, int, int]:
    state = module.load_state(config.state_path)
    rows = module.fetch_activity_rows(session, config, state.get("last_seen_key", ""))

    excel_existed = config.excel_path.exists()
    workbook, sheet = module.workbook_and_sheet(config)
    schema_changed = getattr(sheet, "_snipeit_schema_changed", False)
    appended = module.append_rows(sheet, rows, module.existing_log_ids(sheet), session, config)
    routed_rows = module.excel_rows_by_log_ids(sheet, {module.row_id(row) for row in rows})

    if appended or not excel_existed or schema_changed:
        config.excel_path.parent.mkdir(parents=True, exist_ok=True)
        module.style_sheet(sheet)
        workbook.save(config.excel_path)

    routed_count = module.append_status_workbooks(config, routed_rows)
    deleted_count = module.append_delete_workbook(config, routed_rows)
    fallback_count = sum(
        1
        for row in routed_rows
        if not module.is_delete_excel_row(row)
        if module.status_bucket(module.row_status(row)) == module.FALLBACK_STATUS_LABEL
    )

    if rows:
        module.save_state(config.state_path, rows[-1])

    if appended or routed_count or deleted_count or schema_changed:
        refresh_lifecycle_report(config)

    return len(appended), routed_count, deleted_count, fallback_count


def refresh_lifecycle_report(config) -> None:
    script = Path(__file__).resolve().parent / "generate_asset_lifecycle_report.py"
    if not script.exists():
        return

    output = config.excel_path.parent / "资产生命周期查询.xlsx"
    command = [
        sys.executable,
        str(script),
        "--source",
        str(config.excel_path),
        "--output",
        str(output),
    ]
    try:
        result = subprocess.run(
            command,
            capture_output=True,
            text=True,
            timeout=120,
            check=False,
        )
    except Exception as exc:
        log(f"Lifecycle report refresh failed: {exc}")
        return

    if result.returncode == 0:
        message = (result.stdout or "").strip()
        log(f"Refreshed lifecycle report: {message or output}")
        return

    error_text = (result.stderr or result.stdout or "").strip()
    log(f"Lifecycle report refresh failed: {error_text or 'unknown error'}")


def main() -> int:
    args = parse_args()
    module = load_sync_module()
    module.load_env_file(Path(args.env))
    config = module.get_config()
    requests_module = module.require_requests()
    session = module.api_session(config, requests_module)

    interval = max(3, args.interval)
    log(f"Started continuous sync every {interval} second(s).")
    backoff_seconds = RATE_LIMIT_MIN_SECONDS

    while True:
        sleep_seconds = interval
        try:
            appended_count, routed_count, deleted_count, fallback_count = run_once(
                module, config, session
            )
            backoff_seconds = RATE_LIMIT_MIN_SECONDS
            if appended_count or routed_count or deleted_count:
                log(
                    f"Appended {appended_count} new row(s) to {config.excel_path}; "
                    f"routed {routed_count} status row(s), "
                    f"{deleted_count} delete row(s) to {config.status_dir}"
                )
            if fallback_count:
                alert(
                    config,
                    f"{fallback_count} row(s) were routed to 未分类.xlsx. Please check asset status labels.",
                    throttle_key="fallback-status",
                    throttle_seconds=60,
                )
        except KeyboardInterrupt:
            log("Stopped.")
            return 0
        except module.SnipeItApiError as exc:
            status_code = getattr(exc, "status_code", None)
            if status_code == 429:
                retry_after = getattr(exc, "retry_after", None)
                sleep_seconds = min(
                    max(retry_after or backoff_seconds, RATE_LIMIT_MIN_SECONDS),
                    MAX_BACKOFF_SECONDS,
                )
                backoff_seconds = min(sleep_seconds * 2, MAX_BACKOFF_SECONDS)
                alert(
                    config,
                    f"Snipe-IT API rate limit hit (429). Waiting {sleep_seconds}s before retry.",
                    throttle_key="api-429",
                    throttle_seconds=60,
                )
            elif status_code in {401, 403}:
                sleep_seconds = AUTH_RETRY_SECONDS
                alert(
                    config,
                    f"Snipe-IT API authentication/permission failed ({status_code}). Check API token and user permissions.",
                    throttle_key=f"api-auth-{status_code}",
                    throttle_seconds=AUTH_RETRY_SECONDS,
                )
            else:
                sleep_seconds = ERROR_RETRY_SECONDS
                alert(
                    config,
                    f"Snipe-IT API error: {exc}",
                    throttle_key="api-error",
                    throttle_seconds=ERROR_RETRY_SECONDS,
                )
        except PermissionError as exc:
            sleep_seconds = EXCEL_LOCK_RETRY_SECONDS
            alert(
                config,
                f"Excel write failed, file may be open or locked: {exc}. Retrying in {sleep_seconds}s.",
                throttle_key="excel-locked",
                throttle_seconds=EXCEL_LOCK_RETRY_SECONDS,
            )
        except requests_module.RequestException as exc:
            sleep_seconds = ERROR_RETRY_SECONDS
            alert(
                config,
                f"Snipe-IT network/API request failed: {exc}. Retrying in {sleep_seconds}s.",
                throttle_key="request-error",
                throttle_seconds=ERROR_RETRY_SECONDS,
            )
        except Exception as exc:
            sleep_seconds = ERROR_RETRY_SECONDS
            alert(
                config,
                f"Sync failed: {exc}. Retrying in {sleep_seconds}s.",
                throttle_key="sync-error",
                throttle_seconds=ERROR_RETRY_SECONDS,
            )

        time.sleep(sleep_seconds)


if __name__ == "__main__":
    raise SystemExit(main())
