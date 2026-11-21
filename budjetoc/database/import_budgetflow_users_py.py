#!/usr/bin/env python3
"""Importa usuarios desde cna14865_budgetflow.sql a budjetoc (sin PHP CLI)."""

from __future__ import annotations

import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "database"))

from sql_import_helpers import (  # noqa: E402
    bud_legacy_source_tenants,
    bud_sql_extract_insert_tuples,
    bud_sql_parse_tuple,
    load_budjetoc_config,
)

try:
    import pymysql
    from pymysql.cursors import DictCursor
except ImportError:
    print("Instale pymysql: pip install pymysql", file=sys.stderr)
    sys.exit(1)

CONFIG = load_budjetoc_config(ROOT)
DB = CONFIG["db"]
DUMP = Path(CONFIG["legacy_sql_dump"])
PASSWORD = str(CONFIG["legacy_user_import_password"])
ONLY_SLUG = os.getenv("BUD_IMPORT_TENANT", "all")


def connect():
    return pymysql.connect(
        host=DB["host"],
        user=DB["user"],
        password=DB["pass"],
        database=DB["name"],
        charset=DB.get("charset", "utf8mb4"),
        cursorclass=DictCursor,
        autocommit=False,
    )


def password_hash_php_compatible(password: str) -> str:
    try:
        import bcrypt

        return bcrypt.hashpw(password.encode(), bcrypt.gensalt()).decode()
    except ImportError:
        pass
    for php in (
        r"C:\xampp\php\php.exe",
        r"C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe",
        r"C:\laragon\bin\php\php-8.2.12-Win32-vs16-x64\php.exe",
        "php",
    ):
        if php != "php" and not Path(php).is_file():
            continue
        code = "echo password_hash($argv[1], PASSWORD_DEFAULT);"
        r = subprocess.run(
            [php, "-r", code, password],
            capture_output=True,
            text=True,
            cwd=str(ROOT),
        )
        if r.returncode == 0 and r.stdout.strip():
            return r.stdout.strip()
    subprocess.check_call([sys.executable, "-m", "pip", "install", "bcrypt", "-q"])
    import bcrypt

    return bcrypt.hashpw(password.encode(), bcrypt.gensalt()).decode()


def valid_email(email: str) -> bool:
    return bool(re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", email))


def resolve_tenant_map(cur) -> dict[int, int]:
    mapping: dict[int, int] = {}
    for old_id, meta in bud_legacy_source_tenants().items():
        cur.execute("SELECT id FROM tenants WHERE slug = %s LIMIT 1", (meta["slug"],))
        row = cur.fetchone()
        if row:
            new_id = int(row["id"])
        else:
            cur.execute(
                "INSERT INTO tenants (name, slug, budget_enabled, oc_enabled, active) "
                "VALUES (%s, %s, 1, 1, 1)",
                (meta["name"], meta["slug"]),
            )
            new_id = int(cur.lastrowid)
            cur.execute(
                "INSERT INTO tenant_settings (tenant_id, company_name) VALUES (%s, %s)",
                (new_id, meta["name"]),
            )
        mapping[old_id] = new_id
        print(f"Tenant {meta['name']}: legacy {old_id} -> {new_id}")
    return mapping


def build_dept_map(
    cur, dump: str, tenant_map: dict[int, int], only_legacy: int | None
) -> dict[int, int]:
    out: dict[int, int] = {}
    for tuple_s in bud_sql_extract_insert_tuples(dump, "departments"):
        f = bud_sql_parse_tuple(tuple_s)
        if len(f) < 3:
            continue
        legacy_dept = int(f[0] or 0)
        legacy_tenant = int(f[1] or 0)
        name = str(f[2] or "")
        if only_legacy is not None and legacy_tenant != only_legacy:
            continue
        if not name or legacy_tenant not in tenant_map:
            continue
        cur.execute(
            "SELECT id FROM departments WHERE tenant_id = %s AND name = %s LIMIT 1",
            (tenant_map[legacy_tenant], name),
        )
        row = cur.fetchone()
        if row:
            out[legacy_dept] = int(row["id"])
    return out


def legacy_emails(dump: str) -> dict[int, str]:
    m: dict[int, str] = {}
    for tuple_s in bud_sql_extract_insert_tuples(dump, "users"):
        f = bud_sql_parse_tuple(tuple_s)
        if len(f) < 2:
            continue
        email = str(f[1] or "").strip().lower()
        if email:
            m[int(f[0] or 0)] = email
    return m


def main() -> None:
    if not DUMP.is_file():
        print(f"ERROR: no existe {DUMP}", file=sys.stderr)
        sys.exit(1)
    if len(PASSWORD) < 8:
        print(
            "ERROR: legacy_user_import_password debe tener 8+ caracteres",
            file=sys.stderr,
        )
        sys.exit(1)

    only_legacy: int | None = None
    if ONLY_SLUG != "all":
        for oid, meta in bud_legacy_source_tenants().items():
            if meta["slug"] == ONLY_SLUG:
                only_legacy = oid
                break
        if only_legacy is None:
            print("BUD_IMPORT_TENANT debe ser cmch, iae o all", file=sys.stderr)
            sys.exit(1)

    dump = DUMP.read_text(encoding="utf-8", errors="replace")
    pw_hash = password_hash_php_compatible(PASSWORD)
    legacy_email = legacy_emails(dump)

    stats = {
        "users_created": 0,
        "users_updated": 0,
        "roles": 0,
        "depts": 0,
        "superadmins": 0,
        "skipped": 0,
    }
    conn = connect()
    try:
        with conn.cursor() as cur:
            tenant_map = resolve_tenant_map(cur)
            if only_legacy is not None and only_legacy not in tenant_map:
                raise ValueError(f"Tenant legacy no reconocido: {only_legacy}")
            dept_map = build_dept_map(cur, dump, tenant_map, only_legacy)
            email_to_id: dict[str, int] = {}
            cur.execute("SELECT id, email FROM users")
            for row in cur.fetchall():
                email_to_id[str(row["email"]).lower()] = int(row["id"])

            for tuple_s in bud_sql_extract_insert_tuples(dump, "users"):
                f = bud_sql_parse_tuple(tuple_s)
                if len(f) < 2:
                    continue
                email = str(f[1] or "").strip().lower()
                if not email or not valid_email(email):
                    stats["skipped"] += 1
                    continue
                name = str(f[2] or "").strip() or email
                if email not in email_to_id:
                    cur.execute(
                        "INSERT INTO users (email, password_hash, full_name, active) "
                        "VALUES (%s, %s, %s, 1)",
                        (email, pw_hash, name),
                    )
                    email_to_id[email] = int(cur.lastrowid)
                    stats["users_created"] += 1
                else:
                    cur.execute(
                        "UPDATE users SET full_name = %s WHERE id = %s AND full_name = ''",
                        (name, email_to_id[email]),
                    )
                    stats["users_updated"] += 1

            for tuple_s in bud_sql_extract_insert_tuples(dump, "user_tenant_roles"):
                f = bud_sql_parse_tuple(tuple_s)
                if len(f) < 3:
                    continue
                legacy_uid = int(f[1] or 0)
                legacy_tid = int(f[2] or 0)
                if only_legacy is not None and legacy_tid != only_legacy:
                    continue
                if legacy_tid not in tenant_map:
                    continue
                email = legacy_email.get(legacy_uid)
                if not email or email not in email_to_id:
                    stats["skipped"] += 1
                    continue
                role = str(f[3] or "encargado")
                if role not in ("encargado", "tenant_admin"):
                    role = "encargado"
                cur.execute(
                    "INSERT IGNORE INTO user_tenant_roles "
                    "(user_id, tenant_id, app, role) VALUES (%s, %s, 'budget', %s)",
                    (email_to_id[email], tenant_map[legacy_tid], role),
                )
                if cur.rowcount:
                    stats["roles"] += 1

            for tuple_s in bud_sql_extract_insert_tuples(
                dump, "user_department_permissions"
            ):
                f = bud_sql_parse_tuple(tuple_s)
                if len(f) < 4:
                    continue
                legacy_uid = int(f[1] or 0)
                legacy_tid = int(f[2] or 0)
                legacy_dept = int(f[3] or 0)
                if only_legacy is not None and legacy_tid != only_legacy:
                    continue
                if legacy_tid not in tenant_map or legacy_dept not in dept_map:
                    continue
                email = legacy_email.get(legacy_uid)
                if not email or email not in email_to_id:
                    continue
                can_edit = 1 if int(f[4] or 1) else 0
                cur.execute(
                    "INSERT IGNORE INTO user_department_permissions "
                    "(user_id, tenant_id, department_id, can_edit) "
                    "VALUES (%s, %s, %s, %s)",
                    (
                        email_to_id[email],
                        tenant_map[legacy_tid],
                        dept_map[legacy_dept],
                        can_edit,
                    ),
                )
                if cur.rowcount:
                    stats["depts"] += 1

            for tuple_s in bud_sql_extract_insert_tuples(dump, "super_admins"):
                f = bud_sql_parse_tuple(tuple_s)
                if not f:
                    continue
                legacy_uid = int(f[0] or 0)
                email = legacy_email.get(legacy_uid)
                if not email or email not in email_to_id:
                    continue
                uid = email_to_id[email]
                cur.execute("SELECT 1 FROM super_admins WHERE user_id = %s LIMIT 1", (uid,))
                if not cur.fetchone():
                    cur.execute("INSERT INTO super_admins (user_id) VALUES (%s)", (uid,))
                    stats["superadmins"] += 1

        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

    print("Importacion completada.")
    print(f"  Usuarios creados: {stats['users_created']}")
    print(f"  Usuarios existentes (email): {stats['users_updated']}")
    print(f"  Roles presupuesto: {stats['roles']}")
    print(f"  Permisos division/coord: {stats['depts']}")
    print(f"  Superadmins: {stats['superadmins']}")
    print(f"  Omitidos: {stats['skipped']}")
    print(f"  Contrasena inicial nuevos usuarios: {PASSWORD}")


if __name__ == "__main__":
    main()
