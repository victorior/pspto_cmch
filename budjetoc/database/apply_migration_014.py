#!/usr/bin/env python3
"""Aplica migracion 014: indices budget_headers para dashboard encargado."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = Path(__file__).resolve().parent / "migrations" / "014_budget_headers_encargado_indexes.sql"

INDEX_NAMES = (
    "idx_bh_tenant_year_division",
    "idx_bh_tenant_year_department",
    "idx_bh_tenant_year_created_by",
)


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


def main() -> int:
    try:
        import pymysql
    except ImportError:
        print("pip install pymysql", file=sys.stderr)
        return 1

    conn = pymysql.connect(**db_cfg())
    try:
        with conn.cursor() as cur:
            print(f"Aplicando {MIGRATION.name}...")
            for stmt in MIGRATION.read_text(encoding="utf-8").split(";"):
                stmt = strip_comments(stmt)
                if not stmt:
                    continue
                cur.execute(stmt)
                print(f"  OK: {stmt.split(chr(10))[0][:72]}...")
            print("Verificacion indices:")
            for name in INDEX_NAMES:
                cur.execute(
                    "SHOW INDEX FROM budget_headers WHERE Key_name = %s",
                    (name,),
                )
                row = cur.fetchone()
                print(f"  {'OK' if row else 'FALTA'}  {name}")
                if not row:
                    return 1
        conn.commit()
    finally:
        conn.close()
    print("OK: migracion 014 aplicada.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
