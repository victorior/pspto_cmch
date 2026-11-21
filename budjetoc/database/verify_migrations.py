#!/usr/bin/env python3
"""Verifica columnas e indices clave de migraciones 001-014."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


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

    conn = pymysql.connect(**db_cfg())
    ok = True
    checks: list[tuple[str, str]] = [
        ("budget_headers.created_by", "SHOW COLUMNS FROM budget_headers LIKE 'created_by'"),
        ("budget_headers.budget_year", "SHOW COLUMNS FROM budget_headers LIKE 'budget_year'"),
        ("idx_bh_tenant_year_month", "SHOW INDEX FROM budget_headers WHERE Key_name = 'idx_bh_tenant_year_month'"),
        ("idx_bh_tenant_year_division", "SHOW INDEX FROM budget_headers WHERE Key_name = 'idx_bh_tenant_year_division'"),
        ("idx_bh_tenant_year_department", "SHOW INDEX FROM budget_headers WHERE Key_name = 'idx_bh_tenant_year_department'"),
        ("idx_bh_tenant_year_created_by", "SHOW INDEX FROM budget_headers WHERE Key_name = 'idx_bh_tenant_year_created_by'"),
        ("idx_po_tenant_year_status_num", "SHOW INDEX FROM purchase_orders WHERE Key_name = 'idx_po_tenant_year_status_num'"),
        ("idx_tenant_detail", "SHOW INDEX FROM po_budget_allocations WHERE Key_name = 'idx_tenant_detail'"),
        ("purchase_orders.voided_at", "SHOW COLUMNS FROM purchase_orders LIKE 'voided_at'"),
        ("account_codes.account_id", "SHOW COLUMNS FROM account_codes LIKE 'account_id'"),
        ("users.google_id", "SHOW COLUMNS FROM users LIKE 'google_id'"),
    ]

    legacy_absent = [
        "external_budget_detail_fields",
        "external_activity_fields",
    ]

    try:
        with conn.cursor() as cur:
            for label, sql in checks:
                cur.execute(sql)
                row = cur.fetchone()
                if row:
                    print(f"OK  {label}")
                else:
                    ok = False
                    print(f"FALTA  {label}")
            for tbl in legacy_absent:
                cur.execute("SHOW TABLES LIKE %s", (tbl,))
                if cur.fetchone():
                    ok = False
                    print(f"FALTA  drop legacy {tbl}")
                else:
                    print(f"OK  sin tabla legacy {tbl}")
    finally:
        conn.close()

    if ok:
        print("\nTodas las comprobaciones pasaron.")
        return 0
    print("\nAplique las migraciones pendientes en database/migrations/", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
