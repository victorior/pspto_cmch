#!/usr/bin/env python3
"""Actualiza print_logo_path en tenant_settings por slug de tenant."""
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
LOGO_URL = "https://www.iae.cl/logistics/upload/logo.php"
DEFAULT_SLUG = "iae"


def load_db_config() -> dict:
    cfg_path = ROOT / "config" / "config.php"
    text = cfg_path.read_text(encoding="utf-8", errors="replace")
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
    slug = (sys.argv[1] if len(sys.argv) > 1 else DEFAULT_SLUG).strip().lower()
    logo = (sys.argv[2] if len(sys.argv) > 2 else LOGO_URL).strip()
    if not logo.startswith("https://"):
        print("ERROR: URL de logo debe ser https://")
        return 1

    db = load_db_config()
    conn = pymysql.connect(**db)
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id, name FROM tenants WHERE slug = %s", (slug,))
            row = cur.fetchone()
            if not row:
                print(f"ERROR: tenant slug '{slug}' no encontrado")
                return 1
            tid, name = row[0], row[1]
            cur.execute(
                "UPDATE tenant_settings SET print_logo_path = %s WHERE tenant_id = %s",
                (logo, tid),
            )
            if cur.rowcount == 0:
                cur.execute(
                    "INSERT INTO tenant_settings (tenant_id, company_name, print_logo_path) VALUES (%s, %s, %s)",
                    (tid, name, logo),
                )
                print(f"INSERT tenant_settings tenant_id={tid} ({name})")
            else:
                print(f"UPDATE tenant_settings tenant_id={tid} ({name})")
            conn.commit()
            cur.execute(
                "SELECT print_logo_path FROM tenant_settings WHERE tenant_id = %s",
                (tid,),
            )
            saved = cur.fetchone()[0]
            print("print_logo_path:", saved)
    finally:
        conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
