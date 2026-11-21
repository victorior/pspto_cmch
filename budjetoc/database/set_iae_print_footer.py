#!/usr/bin/env python3
"""Actualiza pie de pagina IAE (horario recepcion completo + copyright) como PDF legacy."""
from __future__ import annotations

import re
import sys
from pathlib import Path

try:
    import pymysql
except ImportError:
    print("ERROR: pip install pymysql")
    sys.exit(1)

ROOT = Path(__file__).resolve().parents[1]
DELIVERY_HOURS = (
    "Lunes a Viernes 9:00 a 13:00, tarde: 15:30 a 17:00."
)
DELIVERY_ADDR = "Baquedano 648."


def load_db_config() -> dict:
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
    slug = sys.argv[1] if len(sys.argv) > 1 else "iae"
    year = sys.argv[2] if len(sys.argv) > 2 else "2026"
    # Copyright sign UTF-8 (no depender de encoding del .py en Windows)
    footer = "\u00a9 IAE " + year

    conn = pymysql.connect(**load_db_config())
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM tenants WHERE slug = %s", (slug,))
            row = cur.fetchone()
            if not row:
                print(f"ERROR: tenant '{slug}' no encontrado")
                return 1
            tid = row[0]
            cur.execute(
                """UPDATE tenant_settings SET
                   print_delivery_hours = %s,
                   print_delivery_address = %s,
                   print_footer_text = %s
                   WHERE tenant_id = %s""",
                (DELIVERY_HOURS, DELIVERY_ADDR, footer, tid),
            )
            conn.commit()
            print(f"OK tenant_id={tid}: horario, direccion, pie '{footer}'")
    finally:
        conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
