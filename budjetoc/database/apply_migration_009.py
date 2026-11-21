#!/usr/bin/env python3
"""Backfill voided_at en OCs anuladas (migracion 009)."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = Path(__file__).resolve().parent / "migrations" / "009_backfill_voided_at.sql"


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
            cur.execute(sql.strip().rstrip(";"))
        conn.commit()
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) FROM purchase_orders WHERE status = 2 AND voided_at IS NULL"
            )
            pending = int(cur.fetchone()[0])
    finally:
        conn.close()
    print(f"OK: migracion 009 aplicada. OCs anuladas sin voided_at: {pending}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
