#!/usr/bin/env python3
"""Aplica migraciones 010-012 (budget_year, indice OC, drop external_activity_fields)."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = [
    Path(__file__).resolve().parent / "migrations" / "010_budget_year.sql",
    Path(__file__).resolve().parent / "migrations" / "011_po_list_index.sql",
    Path(__file__).resolve().parent / "migrations" / "012_drop_external_activity_fields.sql",
]


def db_cfg() -> dict:
    text = (ROOT / "config" / "config.php").read_text(encoding="utf-8", errors="replace")

    def grab(key: str, default: str = "") -> str:
        m = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
        return m.group(1) if m else default

    return {
        "host": grab("host", "127.0.0.1"),
        "user": grab("user", "root"),
        "password": grab("pass", ""),
        "database": grab("name", "budjetoc"),
        "charset": "utf8mb4",
    }


def strip_comments(block: str) -> str:
    lines = []
    for line in block.splitlines():
        s = line.strip()
        if s == "" or s.startswith("--"):
            continue
        lines.append(line)

    return "\n".join(lines).strip()


def run_file(cur, path: Path) -> None:
    sql = path.read_text(encoding="utf-8")
    for stmt in sql.split(";"):
        stmt = strip_comments(stmt)
        if not stmt:
            continue
        cur.execute(stmt)


def main() -> int:
    try:
        import pymysql
    except ImportError:
        print("pip install pymysql", file=sys.stderr)
        return 1

    conn = pymysql.connect(**db_cfg())
    try:
        with conn.cursor() as cur:
            for mig in MIGRATIONS:
                print(f"Aplicando {mig.name}...")
                run_file(cur, mig)
        conn.commit()
    finally:
        conn.close()
    print("OK: migraciones 010-012 aplicadas.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
