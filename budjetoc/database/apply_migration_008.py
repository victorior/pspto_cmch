#!/usr/bin/env python3
"""Elimina external_budget_detail_fields (migracion 008)."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = Path(__file__).resolve().parent / "migrations" / "008_drop_external_budget_detail_fields.sql"


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


def main() -> int:
    try:
        import pymysql
    except ImportError:
        print("pip install pymysql", file=sys.stderr)
        return 1

    sql = MIGRATION.read_text(encoding="utf-8")
    conn = pymysql.connect(**db_cfg())
    try:
        with conn.cursor() as cur:
            for stmt in (s.strip() for s in sql.split(";") if s.strip()):
                cur.execute(stmt)
        conn.commit()
    finally:
        conn.close()
    print("OK: migracion 008 aplicada (tabla external_budget_detail_fields eliminada si existia).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
