#!/usr/bin/env python3
"""Read-only BYL ERP runtime/release drift inventory. Never restarts or writes."""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import subprocess
from pathlib import Path

SCHEDULE_COMMAND = re.compile(r"""Schedule::command\(\s*['"]([^'"]+)""")
WORKER_COMMAND = re.compile(r"\bartisan\s+(queue:work|queue:listen|schedule:work)\b")
DEV_COMMAND = re.compile(r"\bartisan\s+serve\b")
PHP_SERVER_COMMAND = re.compile(r"\bphp(?:\d+(?:\.\d+)?)?\s+-S\s+127\.0\.0\.1:")


def process_rows(output: str) -> list[tuple[int, int, str]]:
    rows = []
    for line in output.splitlines():
        match = re.match(r"\s*(\d+)\s+(\d+)\s+(.*)$", line)
        if match:
            rows.append((int(match[1]), int(match[2]), match[3]))
    return rows


def scheduled_commands(release: Path) -> set[str]:
    source = release / "routes" / "console.php"
    if not source.is_file():
        return set()
    return {match.group(1).split()[0] for match in SCHEDULE_COMMAND.finditer(
        source.read_text(encoding="utf-8", errors="replace")
    )}


def process_cwd(pid: int) -> Path | None:
    result = subprocess.run(
        ["lsof", "-a", "-p", str(pid), "-d", "cwd", "-Fn"],
        capture_output=True, text=True, check=False, timeout=8,
    )
    for line in result.stdout.splitlines():
        if line.startswith("n") and len(line) > 1:
            return Path(line[1:]).resolve()
    return None


def inventory(root: Path, ps_output: str | None = None,
              cwd_lookup=None) -> dict:
    root = root.resolve()
    current = (root / "current").resolve(strict=True)
    if ps_output is None:
        ps_output = subprocess.run(
            ["ps", "-axo", "pid=,rss=,args="],
            capture_output=True, text=True, check=True, timeout=10,
        ).stdout
    cwd_lookup = cwd_lookup or process_cwd
    releases = root / "releases"
    current_jobs = scheduled_commands(current)
    workers = []
    dev_launchers = dev_http = dev_rss_kb = 0
    for pid, rss_kb, args in process_rows(ps_output):
        if DEV_COMMAND.search(args):
            dev_launchers += 1
            dev_rss_kb += rss_kb
        elif PHP_SERVER_COMMAND.search(args):
            dev_http += 1
            dev_rss_kb += rss_kb
        match = WORKER_COMMAND.search(args)
        if not match:
            continue
        cwd = cwd_lookup(pid)
        if cwd is None:
            continue
        cwd = cwd.resolve()
        in_production = cwd == current or cwd == releases or releases in cwd.parents
        if not in_production:
            continue  # Local Dev worker is not a Production worker.
        drift = cwd != current
        row = {
            "pid": pid,
            "role": match[1],
            "release": str(cwd),
            "matches_current": not drift,
        }
        if match[1] == "schedule:work":
            row["missing_scheduled_commands"] = sorted(
                current_jobs - scheduled_commands(cwd)
            )
        workers.append(row)
    return {
        "checked_at_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
        "production_root": str(root),
        "current_release": str(current),
        "production_workers": sorted(workers, key=lambda w: (w["role"], w["pid"])),
        "production_worker_drift": any(not w["matches_current"] for w in workers),
        "dev_artisan_launchers": dev_launchers,
        "dev_php_http_processes": dev_http,
        "dev_process_total_rss_mb_non_unique": round(dev_rss_kb / 1024, 1),
        "read_only": True,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path,
                        default=Path("/Users/a/ai/production/erp.beyourlover.com"))
    parser.add_argument("--strict", action="store_true",
                        help="Exit 2 if a production worker has a release mismatch")
    args = parser.parse_args()
    try:
        report = inventory(args.root)
    except (OSError, subprocess.SubprocessError, ValueError) as exc:
        print(json.dumps({"error": type(exc).__name__, "read_only": True}))
        return 1
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 2 if args.strict and report["production_worker_drift"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
