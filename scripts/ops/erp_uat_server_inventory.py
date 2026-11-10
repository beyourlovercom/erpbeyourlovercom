#!/usr/bin/env python3
"""Read-only BYL ERP UAT server inventory; never stops, cleans, or restarts jobs."""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import subprocess
from pathlib import Path

ARTISAN = re.compile(r"(?:^|\s)(?:\S*/)?php\s+artisan\s+serve\b")
BUILTIN = re.compile(r"(?:^|\s)(?:\S*/)?php\s+-S\s+127\.0\.0\.1:(\d+)")
PORT = re.compile(r"--port(?:=|\s+)(\d+)")


def parse_processes(output: str) -> list[dict]:
    """ps -axo pid=,ppid=,etime=,rss=,command= (not CSV)."""
    rows = []
    for line in output.splitlines():
        match = re.match(r"\s*(\d+)\s+(\d+)\s+(\S+)\s+(\d+)\s+(.*)", line)
        if match:
            pid, ppid, etime, rss, args = match.groups()
            rows.append({
                "pid": int(pid), "ppid": int(ppid), "elapsed": etime,
                "rss_kb": int(rss), "args": args,
            })
    return rows


def process_cwd(pid: int) -> Path | None:
    output = subprocess.run(
        ["lsof", "-a", "-p", str(pid), "-d", "cwd", "-Fn"],
        capture_output=True, text=True, timeout=8, check=False,
    ).stdout
    for line in output.splitlines():
        if line.startswith("n") and len(line) > 1:
            return Path(line[1:]).resolve()
    return None


def under_root(path: Path, root: Path) -> bool:
    return path == root or root in path.parents


def inventory(root: Path, ps_output: str | None = None,
              cwd_lookup=None) -> dict:
    root = root.resolve()
    if ps_output is None:
        ps_output = subprocess.run(
            ["ps", "-axo", "pid=,ppid=,etime=,rss=,command="],
            capture_output=True, text=True, timeout=10, check=True,
        ).stdout
    cwd_lookup = cwd_lookup or process_cwd
    all_processes = parse_processes(ps_output)
    launchers: dict[int, dict] = {}
    for proc in all_processes:
        if not ARTISAN.search(proc["args"]):
            continue
        cwd = cwd_lookup(proc["pid"])
        if cwd is None or not under_root(cwd, root):
            continue
        match = PORT.search(proc["args"])
        launchers[proc["pid"]] = {
            "pid": proc["pid"],
            "elapsed": proc["elapsed"],
            "port": int(match.group(1)) if match else None,
            "uat_path": str(cwd),
            "rss_kb": proc["rss_kb"],
            "http_children": [],
            "action": "REVIEW_ONLY",
        }
    orphans = []
    for proc in all_processes:
        match = BUILTIN.search(proc["args"])
        if not match:
            continue
        if proc["ppid"] in launchers:
            launchers[proc["ppid"]]["http_children"].append(proc["pid"])
            continue
        # Unparented HTTP processes can be UAT services too. Only include
        # if their server script lives under the permitted UAT root.
        args = proc["args"]
        if str(root) not in args:
            continue
        orphans.append({
            "pid": proc["pid"], "ppid": proc["ppid"],
            "elapsed": proc["elapsed"], "port": int(match.group(1)),
            "rss_kb": proc["rss_kb"], "action": "REVIEW_ONLY",
        })
    return {
        "checked_at_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
        "uat_root": str(root),
        "artisan_launchers": sorted(launchers.values(), key=lambda x: x["pid"]),
        "orphan_php_http_candidates": sorted(orphans, key=lambda x: x["pid"]),
        "counts": {
            "artisan_launchers": len(launchers),
            "paired_php_http_children": sum(
                len(p["http_children"]) for p in launchers.values()
            ),
            "orphan_php_http_candidates": len(orphans),
        },
        "safety": "INVENTORY_ONLY_DO_NOT_AUTO_TERMINATE",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path("/Users/a/ai/uat"))
    parser.add_argument("--summary", action="store_true", help="Show counts, not PIDs")
    args = parser.parse_args()
    try:
        report = inventory(args.root)
    except (OSError, subprocess.SubprocessError) as exc:
        print(json.dumps({"read_only": True, "error": type(exc).__name__}))
        return 1
    if args.summary:
        report = {
            "uat_root": report["uat_root"],
            "counts": report["counts"],
            "safety": report["safety"],
        }
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
