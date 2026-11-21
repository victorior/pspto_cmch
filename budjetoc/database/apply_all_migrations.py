#!/usr/bin/env python3
"""Aplica migraciones pendientes 001-014 (idempotente donde es posible)."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "database" / "migrations"


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
        if "--" in line:
            line = line.split("--", 1)[0]
        s = line.strip()
        if s == "":
            continue
        lines.append(line)
    return "\n".join(lines).strip()


def index_exists(cur, table: str, index_name: str) -> bool:
    cur.execute(f"SHOW INDEX FROM `{table}` WHERE Key_name = %s", (index_name,))
    return cur.fetchone() is not None


def column_exists(cur, table: str, column: str) -> bool:
    cur.execute(f"SHOW COLUMNS FROM `{table}` LIKE %s", (column,))
    return cur.fetchone() is not None


def table_exists(cur, table: str) -> bool:
    cur.execute("SHOW TABLES LIKE %s", (table,))
    return cur.fetchone() is not None


def run_sql_file(cur, path: Path) -> None:
    if not path.is_file():
        return
    sql = path.read_text(encoding="utf-8")
    for stmt in sql.split(";"):
        stmt = strip_comments(stmt)
        if not stmt:
            continue
        try:
            cur.execute(stmt)
            print(f"  OK {path.name}: {stmt.split(chr(10))[0][:70]}...")
        except Exception as exc:
            msg = str(exc).lower()
            if "duplicate" in msg or "already exists" in msg:
                print(f"  SKIP {path.name} (ya aplicado)")
            else:
                raise


def main() -> int:
    try:
        import pymysql
    except ImportError:
        print("pip install pymysql", file=sys.stderr)
        return 1

    conn = pymysql.connect(**db_cfg())
    try:
        with conn.cursor() as cur:
            print("=== Migraciones estructurales ===")
            for name in sorted(MIGRATIONS.glob("*.sql")):
                if name.name.startswith("009_") or name.name.startswith("013_"):
                    continue
                if name.name == "014_budget_headers_encargado_indexes.sql":
                    for idx in (
                        "idx_bh_tenant_year_division",
                        "idx_bh_tenant_year_department",
                        "idx_bh_tenant_year_created_by",
                    ):
                        if index_exists(cur, "budget_headers", idx):
                            print(f"  SKIP {idx} (existe)")
                            continue
                run_sql_file(cur, name)

            print("\n=== Datos opcionales ===")
            if column_exists(cur, "purchase_orders", "voided_at"):
                run_sql_file(cur, MIGRATIONS / "009_backfill_voided_at.sql")
            else:
                print("  SKIP 009 (columna voided_at ausente)")

            rename = MIGRATIONS / "013_rename_administracion_cost_center.sql"
            if rename.is_file():
                print("  INFO 013: ejecute py database/apply_migration_013.py si aplica a su tenant")

            print("\n=== Limpieza legacy ===")
            for tbl in ("external_budget_detail_fields", "external_activity_fields"):
                if table_exists(cur, tbl):
                    cur.execute(f"DROP TABLE `{tbl}`")
                    print(f"  DROP {tbl}")
                else:
                    print(f"  OK {tbl} ya eliminada")

        conn.commit()
    finally:
        conn.close()

    print("\nEjecute: py database/verify_migrations.py")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
