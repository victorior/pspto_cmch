#!/usr/bin/env python3
"""Aplica migracion 013: renombra Administracion -> Administracioncc."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = Path(__file__).resolve().parent / "migrations" / "013_rename_administracion_cost_center.sql"


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
        print(f"  Filas afectadas: {cur.rowcount}")


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
            run_file(cur, MIGRATION)
            print("Verificacion cost_centers:")
            cur.execute("SELECT id, tenant_id, name FROM cost_centers WHERE name LIKE 'Administraci%'")
            for row in cur.fetchall():
                print(" ", row)
            print("Verificacion departments:")
            cur.execute(
                "SELECT id, tenant_id, name, type FROM departments WHERE name LIKE 'Administraci%'"
            )
            for row in cur.fetchall():
                print(" ", row)
        conn.commit()
    finally:
        conn.close()
    print("OK: migracion 013 aplicada.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
