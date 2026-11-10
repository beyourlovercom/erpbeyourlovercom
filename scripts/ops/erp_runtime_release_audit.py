#!/usr/bin/env python3
"""Read-only BYL ERP runtime/release drift inventory. Never restarts or writes."""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
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



def backend_fingerprint(release: Path) -> str | None:
    """Fingerprint worker-relevant source, not UI JS/CSS or generated assets.

    A web-only AG Grid build must not require restarting unchanged PHP workers.
    Missing core source trees fail closed as unknown backend compatibility.
    """
    if not (release / "app").is_dir() or not (release / "routes").is_dir():
        return None
    digest = hashlib.sha256()
    directories = ("app", "routes", "config", "database/migrations",
                   "resources/views", "lang")
    paths = []
    for dirname in directories:
        directory = release / dirname
        if directory.exists():
            paths.extend(p for p in directory.rglob("*.php") if p.is_file())
    for filename in ("artisan", "composer.json", "composer.lock",
                     "bootstrap/app.php", "bootstrap/providers.php"):
        item = release / filename
        if item.is_file():
            paths.append(item)
    for path in sorted(paths, key=lambda p: p.relative_to(release).as_posix()):
        digest.update(path.relative_to(release).as_posix().encode())
        digest.update(b"\\0")
        digest.update(path.read_bytes())
        digest.update(b"\\0")
    return digest.hexdigest()


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
    fingerprints: dict[Path, str | None] = {current: backend_fingerprint(current)}
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
        if cwd not in fingerprints:
            fingerprints[cwd] = backend_fingerprint(cwd)
        compatible = (
            fingerprints[current] is not None
            and fingerprints[cwd] == fingerprints[current]
        )
        row = {
            "pid": pid,
            "role": match[1],
            "release": str(cwd),
            "matches_current": not drift,
            "backend_compatible": compatible,
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
        "production_worker_backend_drift": any(not w["backend_compatible"] for w in workers),
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
                        help="Exit 2 on any Worker release-path mismatch (legacy behavior)")
    parser.add_argument("--strict-backend", action="store_true",
                        help="Exit 2 only on worker-relevant PHP/source mismatch")
    args = parser.parse_args()
    try:
        report = inventory(args.root)
    except (OSError, subprocess.SubprocessError, ValueError) as exc:
        print(json.dumps({"error": type(exc).__name__, "read_only": True}))
        return 1
    print(json.dumps(report, ensure_ascii=False, indent=2))
    if args.strict and report["production_worker_drift"]:
        return 2
    if args.strict_backend and report["production_worker_backend_drift"]:
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
