#!/usr/bin/env python3
"""Consulta imputaciones OC (po_budget_allocations) para una OC/actividad."""
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

    po_id = int(sys.argv[1]) if len(sys.argv) > 1 else 1
    act = sys.argv[2] if len(sys.argv) > 2 else "0002"

    conn = pymysql.connect(**db_cfg())
    cur = conn.cursor()
    cur.execute(
        """
        SELECT pba.id, pba.budget_detail_id, pba.amount, pba.req_number,
               ac.account_id, po.folio_year, po.numero_oc, po.status
        FROM po_budget_allocations pba
        JOIN budget_details bd ON bd.id = pba.budget_detail_id
        JOIN budget_headers bh ON bh.id = bd.budget_header_id
        JOIN account_codes ac ON ac.id = bd.account_code_id
        JOIN purchase_orders po ON po.id = pba.purchase_order_id AND po.tenant_id = pba.tenant_id
        WHERE pba.purchase_order_id = %s AND bh.activity_number LIKE %s
        ORDER BY pba.id
        """,
        (po_id, f"{act}%"),
    )
    for row in cur.fetchall():
        print(row)
    conn.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
