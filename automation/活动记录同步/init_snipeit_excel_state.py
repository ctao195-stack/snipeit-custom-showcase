#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import sys
from pathlib import Path


def main() -> int:
    base_dir = Path(__file__).resolve().parent
    sync_path = base_dir / "sync_snipeit_activity_to_excel.py"

    spec = importlib.util.spec_from_file_location("snipeit_excel_sync", sync_path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Cannot load {sync_path}")

    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)

    module.load_env_file(base_dir / ".env.snipeit-excel")
    config = module.get_config()
    requests_module = module.require_requests()
    session = module.api_session(config, requests_module)

    data = module.api_get(
        session,
        config,
        "/api/v1/reports/activity",
        {"limit": 1, "sort": "created_at", "order": "desc"},
    )
    rows = data.get("rows", [])
    if not rows:
        raise SystemExit("No Snipe-IT activity rows found to initialize state.")

    module.save_state(config.state_path, rows[0])
    workbook, sheet = module.workbook_and_sheet(config)
    module.style_sheet(sheet)
    config.excel_path.parent.mkdir(parents=True, exist_ok=True)
    workbook.save(config.excel_path)

    print(
        "Initialized future-only sync at log id "
        f"{module.row_id(rows[0])}; Excel: {config.excel_path}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
