#!/usr/bin/env python3
"""Crea o reinicia la base budjetoc (unica permitida) e importa catalogo legacy."""
from __future__ import annotations

import os
import re
import sys
from pathlib import Path

try:
    import pymysql
except ImportError:
    print("pip install pymysql", file=sys.stderr)
    sys.exit(1)

ROOT = Path(__file__).resolve().parent.parent
PSPTO = ROOT.parent / "pspto"
DB_NAME = os.environ.get("BUD_DB_NAME", "budjetoc")
HOST = os.environ.get("BUD_DB_HOST", "127.0.0.1")
USER = os.environ.get("BUD_DB_USER", "root")
PASS = os.environ.get("BUD_DB_PASS", "")


def split_sql(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8", errors="replace")
    parts = re.split(r";\s*\n", text)
    out = []
    for stmt in parts:
        s = stmt.strip()
        if not s or s.startswith("--"):
            continue
        out.append(s)
    return out


def main() -> None:
    if DB_NAME != "budjetoc":
        print(f"ERROR: solo se permite BUD_DB_NAME=budjetoc (recibido: {DB_NAME})", file=sys.stderr)
        sys.exit(1)
    if "--reset" not in sys.argv:
        print("Use --reset para DROP+CREATE de budjetoc (destructivo).", file=sys.stderr)
        sys.exit(1)

    conn = pymysql.connect(host=HOST, user=USER, password=PASS, charset="utf8mb4")
    cur = conn.cursor()
    cur.execute(f"DROP DATABASE IF EXISTS `{DB_NAME}`")
    cur.execute(
        f"CREATE DATABASE `{DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    )
    conn.commit()
    conn.close()
    print(f"Base {DB_NAME} creada.")

    conn = pymysql.connect(
        host=HOST, user=USER, password=PASS, database=DB_NAME, charset="utf8mb4"
    )
    cur = conn.cursor()
    cur.execute("SET FOREIGN_KEY_CHECKS = 0")
    for stmt in split_sql(ROOT / "database" / "schema.sql"):
        cur.execute(stmt)
    for mig in sorted((ROOT / "database" / "migrations").glob("*.sql")):
        if mig.name.startswith("00"):
            try:
                for stmt in split_sql(mig):
                    cur.execute(stmt)
            except pymysql.err.OperationalError as e:
                if "Duplicate" not in str(e):
                    print(f"  aviso {mig.name}: {e}")
    cur.execute("SET FOREIGN_KEY_CHECKS = 1")
    conn.commit()
    print("Schema aplicado.")

    conn.close()

    # Actualizar config temporal para imports
    os.environ["BUD_DB_NAME"] = DB_NAME
    bf = ROOT / "cna14865_budgetflow.sql"
    oc = PSPTO / "cna14865_oc_multitenant.sql"
    if not bf.is_file():
        bf = PSPTO / "cna14865_budgetflow.sql"
    if not oc.is_file():
        oc = ROOT / "cna14865_oc_multitenant.sql"

    import_catalog_inline(HOST, USER, PASS, DB_NAME, bf, oc)
    create_superadmin(HOST, USER, PASS, DB_NAME)
    print("Listo. BD:", DB_NAME, "| config.php db.name debe ser budjetoc")


def create_superadmin(host, user, password, db) -> None:
    import hashlib
    import secrets

    email = os.environ.get("BUD_ADMIN_EMAIL", "admin@local.test")
    plain = os.environ.get("BUD_ADMIN_PASS", "Admin1234!")
    name = os.environ.get("BUD_ADMIN_NAME", "Super Admin")
    # password_hash compatible con password_verify (PHP bcrypt $2y$)
    try:
        import bcrypt

        pw_hash = bcrypt.hashpw(plain.encode(), bcrypt.gensalt()).decode()
        if pw_hash.startswith("$2b$"):
            pw_hash = "$2y$" + pw_hash[4:]
    except ImportError:
        pw_hash = hashlib.sha256(plain.encode()).hexdigest()

    conn = pymysql.connect(
        host=host, user=user, password=password, database=db, charset="utf8mb4"
    )
    cur = conn.cursor()
    cur.execute("SELECT id FROM users WHERE email=%s", (email,))
    row = cur.fetchone()
    if row:
        uid = row[0]
    else:
        cur.execute(
            "INSERT INTO users (email, password_hash, full_name, active) VALUES (%s,%s,%s,1)",
            (email, pw_hash, name),
        )
        uid = cur.lastrowid
    cur.execute("INSERT IGNORE INTO super_admins (user_id) VALUES (%s)", (uid,))
    conn.commit()
    conn.close()
    print(f"Superadmin: {email}")


def import_catalog_inline(host, user, password, db, bf_path, oc_path):
    conn = pymysql.connect(
        host=host, user=user, password=password, database=db, charset="utf8mb4"
    )
    cur = conn.cursor()

    def extract_tuples(dump: str, table: str) -> list[str]:
        pat = rf"INSERT INTO `{table}`\s*\([^)]+\)\s*VALUES\s*(.*?);"
        m = re.search(pat, dump, re.S)
        if not m:
            return []
        body = m.group(1)
        rows, i, n = [], 0, len(body)
        while i < n:
            while i < n and body[i] in " \n\r\t,":
                i += 1
            if i >= n or body[i] != "(":
                break
            depth, start, ins, esc = 0, i, False, False
            while i < n:
                c = body[i]
                if ins:
                    if esc:
                        esc = False
                    elif c == "\\":
                        esc = True
                    elif c == "'":
                        ins = False
                elif c == "'":
                    ins = True
                elif c == "(":
                    depth += 1
                elif c == ")":
                    depth -= 1
                    if depth == 0:
                        rows.append(body[start : i + 1])
                        i += 1
                        break
                i += 1
        return rows

    def parse_tuple(t: str) -> list:
        t = t.strip().strip("()")
        fields, buf, ins, esc = [], "", False, False
        for c in t:
            if ins:
                if esc:
                    buf += c
                    esc = False
                elif c == "\\":
                    esc = True
                elif c == "'":
                    ins = False
                else:
                    buf += c
            elif c == "'":
                ins = True
            elif c == ",":
                v = buf.strip()
                fields.append(None if v.upper() == "NULL" else v)
                buf = ""
            else:
                buf += c
        v = buf.strip()
        fields.append(None if v.upper() == "NULL" else v)
        return fields

    tenants_meta = {1: ("Colegio CMCH", "cmch"), 2: ("IAE", "iae")}
    tenant_map = {}
    for old_id, (name, slug) in tenants_meta.items():
        cur.execute("SELECT id FROM tenants WHERE slug=%s", (slug,))
        row = cur.fetchone()
        if row:
            tenant_map[old_id] = row[0]
        else:
            cur.execute(
                "INSERT INTO tenants (name, slug, budget_enabled, oc_enabled, active) VALUES (%s,%s,1,1,1)",
                (name, slug),
            )
            tid = cur.lastrowid
            cur.execute(
                "INSERT INTO tenant_settings (tenant_id, company_name) VALUES (%s,%s)",
                (tid, name),
            )
            tenant_map[old_id] = tid
        print(f"tenant {slug} -> id {tenant_map[old_id]}")

    dump_bf = bf_path.read_text(encoding="utf-8", errors="replace")
    for tid in tenant_map.values():
        for tbl in ("account_codes", "activities", "cost_centers", "departments"):
            cur.execute(f"DELETE FROM {tbl} WHERE tenant_id=%s", (tid,))

    for tuple_s in extract_tuples(dump_bf, "cost_centers"):
        f = parse_tuple(tuple_s)
        if len(f) < 3:
            continue
        ot = int(f[1])
        if ot not in tenant_map:
            continue
        cur.execute(
            "INSERT INTO cost_centers (tenant_id, name) VALUES (%s,%s)",
            (tenant_map[ot], f[2]),
        )
    print("cost_centers OK")

    for tuple_s in extract_tuples(dump_bf, "departments"):
        f = parse_tuple(tuple_s)
        if len(f) < 4:
            continue
        ot = int(f[1])
        if ot not in tenant_map:
            continue
        typ = "coordinacion" if f[3] == "coordinacion" else "estandar"
        cur.execute(
            "INSERT INTO departments (tenant_id, name, type) VALUES (%s,%s,%s)",
            (tenant_map[ot], f[2], typ),
        )
    print("departments OK")

    for tuple_s in extract_tuples(dump_bf, "activities"):
        f = parse_tuple(tuple_s)
        if len(f) < 3:
            continue
        ot = int(f[1])
        if ot not in tenant_map:
            continue
        cur.execute(
            "INSERT INTO activities (tenant_id, name) VALUES (%s,%s)",
            (tenant_map[ot], f[2]),
        )
    print("activities catalog OK")

    for tuple_s in extract_tuples(dump_bf, "account_codes"):
        f = parse_tuple(tuple_s)
        if len(f) < 4:
            continue
        ot, lid = int(f[1]), int(f[0])
        if ot not in tenant_map:
            continue
        code = f[2] or ""
        aid = int(code) if str(code).isdigit() else lid
        if aid <= 0:
            continue
        try:
            cur.execute(
                "INSERT INTO account_codes (tenant_id, account_id, name) VALUES (%s,%s,%s)",
                (tenant_map[ot], aid, f[3]),
            )
        except pymysql.err.IntegrityError:
            pass
    print("account_codes OK")

    if oc_path.is_file():
        dump_oc = oc_path.read_text(encoding="utf-8", errors="replace")
        cur.execute("DELETE FROM plan_cuenta")
        for tuple_s in extract_tuples(dump_oc, "plan_cuenta"):
            f = parse_tuple(tuple_s)
            if len(f) >= 2:
                cur.execute("INSERT INTO plan_cuenta (id, name) VALUES (%s,%s)", (int(f[0]), f[1]))
        for tuple_s in extract_tuples(dump_oc, "tenant_settings"):
            f = parse_tuple(tuple_s)
            if len(f) < 15:
                continue
            ot = int(f[0])
            if ot not in tenant_map:
                continue
            cur.execute(
                """UPDATE tenant_settings SET company_name=%s, address=%s, tax_id=%s, phone=%s,
                   business_line=%s, print_contact_name=%s, print_contact_role=%s,
                   print_contact_email=%s, print_delivery_hours=%s, print_delivery_address=%s,
                   print_invoice_to=%s, print_footer_text=%s, print_logo_path=%s, tax_percent=%s
                   WHERE tenant_id=%s""",
                (*[f[i] for i in range(1, 15)], tenant_map[ot]),
            )
        for tid in tenant_map.values():
            cur.execute("DELETE FROM suppliers WHERE tenant_id=%s", (tid,))
            cur.execute("DELETE FROM products WHERE tenant_id=%s", (tid,))
        for tuple_s in extract_tuples(dump_oc, "suppliers"):
            f = parse_tuple(tuple_s)
            if len(f) < 7:
                continue
            ot = int(f[1])
            if ot not in tenant_map:
                continue
            cur.execute(
                "INSERT INTO suppliers (tenant_id, legal_name, trade_name, phone, email) VALUES (%s,%s,%s,%s,%s)",
                (tenant_map[ot], f[3], f[4], f[5], f[6]),
            )
        for tuple_s in extract_tuples(dump_oc, "products"):
            f = parse_tuple(tuple_s)
            if len(f) < 7:
                continue
            ot = int(f[1])
            if ot not in tenant_map:
                continue
            try:
                cur.execute(
                    "INSERT INTO products (tenant_id, code, name, unit_price, active) VALUES (%s,%s,%s,%s,%s)",
                    (tenant_map[ot], f[2], f[3], float(f[6] or 0), 1 if int(f[4] or 1) else 0),
                )
            except pymysql.err.IntegrityError:
                pass
        print("OC catalog OK")

    conn.commit()
    for tbl in (
        "budget_headers",
        "purchase_orders",
        "po_budget_allocations",
    ):
        cur.execute(f"SELECT COUNT(*) FROM {tbl}")
        print(f"  {tbl}: {cur.fetchone()[0]} (debe ser 0)")
    conn.close()


if __name__ == "__main__":
    main()
