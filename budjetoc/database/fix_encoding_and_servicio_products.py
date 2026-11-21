# -*- coding: utf-8 -*-
"""Fix UTF-8 text in DB and import servicio boleta catalog products."""
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

CIA35385 = ROOT / "cia35385_OC_IAE.sql"
OC_DUMP = PSPTO / "cna14865_oc_multitenant.sql"
if not OC_DUMP.is_file():
    OC_DUMP = ROOT / "cna14865_oc_multitenant.sql"

SERVICIO_SEED_DEFAULT = [
    ("gif", "Gift card", 1.0, 0),
    ("servicio excento", "Servicio prestado (exento de IVA)", 1.0, 0),
    ("servicio boleta_222", "Servicio prestado (11.5 % Impto. Retenido)", 1.0, 1),
    ("servicio Inscripcion", "Inscripci\u00f3n (exento de IVA)", 1.0, 0),
    ("servicio boleta_22", "Servicio prestado (12.25 % Impto. Retenido)", 1.0, 1),
    (
        "servicio boleta_2b",
        "Servicio de Entrevista e Informe Psicolaboral (11.5 % Impto. Retenido)",
        1.0,
        1,
    ),
    ("servicio boleta3", "Servicios de Asesor\u00edas (11.5 % Impto. Retenido)", 1.0, 1),
    (
        "servicio boleta44",
        "Servicio prestado (14,5% Impto. Retenido+ Ret. Prest. Solidario 2020)",
        1.0,
        1,
    ),
]

MOJIBAKE_MAP = [
    ("\u00c3\u00a1", "\u00e1"),
    ("\u00c3\u00a9", "\u00e9"),
    ("\u00c3\u00ad", "\u00ed"),
    ("\u00c3\u00b3", "\u00f3"),
    ("\u00c3\u00ba", "\u00fa"),
    ("\u00c3\u00b1", "\u00f1"),
    ("\u00c3\u2018", "\u00d1"),
    ("\u00c3\u2030", "\u00c9"),
    ("\u00c3\u201c", "\u00d3"),
    ("\u00c3\u0161", "\u00da"),
    ("\u00e2\u20ac\u2122", "'"),
    ("\u00e2\u20ac\u201c", '"'),
    ("\u00e2\u20ac\u201d", '"'),
    ("\u00e2\u20ac\u201d", "\u2014"),
    ("\u00c2\u00b0", "\u00b0"),
    ("\u00c2\u00ba", "\u00ba"),
]

QUESTION_FIXES = [
    (re.compile(r"Ferreter\?\?a", re.I), "Ferreter\u00eda"),
    (re.compile(r"B\?{3,5}SICO", re.I), "B\u00c1SICO"),
    (re.compile(r"B\?{3,5}SICA", re.I), "B\u00c1SICA"),
    (re.compile(r"(\d)\?{3,5}\s"), lambda m: m.group(1) + "\u00b0 "),
    (re.compile(r"N\?{3,5}"), "N\u00ba"),
    (re.compile(r"BOT\?{3,7}N", re.I), "BOT\u00d3N"),
    (re.compile(r"Asesor\?{3,5}as", re.I), "Asesor\u00edas"),
    (re.compile(r"Reposici\?\?n", re.I), "Reposici\u00f3n"),
    (re.compile(r"Revisi\?\?n", re.I), "Revisi\u00f3n"),
    (re.compile(r"ba\?\?o", re.I), "ba\u00f1o"),
    (re.compile(r"el\?\?ctrico", re.I), "el\u00e9ctrico"),
    (re.compile(r"a\?\?o", re.I), "a\u00f1o"),
    (re.compile(r"per\?\?odicamente", re.I), "peri\u00f3dicamente"),
    (re.compile(r"TERCER BOT\?{3,7}N", re.I), "TERCER BOT\u00d3N"),
]

TEXT_COLUMNS = [
    ("suppliers", "id", ["legal_name", "trade_name", "phone", "email"]),
    ("products", "id", ["code", "name"]),
    ("budget_headers", "id", ["activity_number", "observations"]),
    ("budget_details", "id", ["observation"]),
    ("activities", "id", ["name"]),
    ("departments", "id", ["name"]),
    ("cost_centers", "id", ["name"]),
    ("account_codes", "id", ["name"]),
    ("tenants", "id", ["name"]),
    (
        "tenant_settings",
        "tenant_id",
        ["company_name", "address", "business_line", "print_footer_text"],
    ),
    ("purchase_orders", "id", ["note", "folio_year"]),
]


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


def fix_text(value: str | None) -> str | None:
    if value is None:
        return None
    s = str(value).replace("\r\n", "\n")
    for old, new in MOJIBAKE_MAP:
        s = s.replace(old, new)
    for pat, repl in QUESTION_FIXES:
        s = pat.sub(repl, s)
    s = re.sub(r"N\s*\u00c2\u00b0", "N\u00b0", s, flags=re.I)
    s = s.replace("N&", "N\u00b0")
    return s


def load_servicio_from_cia35385() -> dict[str, tuple[str, float, int]]:
    out: dict[str, tuple[str, float, int]] = {}
    if not CIA35385.is_file():
        return out
    dump = CIA35385.read_text(encoding="utf-8", errors="replace")
    for tuple_s in extract_tuples(dump, "products"):
        f = parse_tuple(tuple_s)
        if len(f) < 6:
            continue
        code = (f[1] or "").strip()
        name = fix_text(f[2] or "") or ""
        if not code.lower().startswith("servicio") and code != "gif":
            continue
        active = 1 if int(f[3] or 1) else 0
        try:
            price = float(f[5] or 1)
        except ValueError:
            price = 1.0
        out[code] = (name, price, active)
    return out


def load_servicio_from_po_lines(dump: str) -> dict[str, tuple[str, float, int]]:
    out: dict[str, tuple[str, float, int]] = {}
    for tuple_s in extract_tuples(dump, "po_lines"):
        f = parse_tuple(tuple_s)
        if len(f) < 7:
            continue
        code = (f[3] or "").strip()
        name = fix_text(f[4] or "") or ""
        if not code or not re.match(r"^servicio", code, re.I):
            continue
        try:
            price = float(f[6] or 1)
        except ValueError:
            price = 1.0
        if code not in out or len(name) > len(out[code][0]):
            out[code] = (name, price, 1)
    return out


def load_supplier_names_cia35385() -> list[tuple[str, str]]:
    if not CIA35385.is_file():
        return []
    dump = CIA35385.read_text(encoding="utf-8", errors="replace")
    names = []
    for tuple_s in extract_tuples(dump, "person"):
        f = parse_tuple(tuple_s)
        if len(f) < 4:
            continue
        n = fix_text(f[2] or "") or ""
        if n and "??" not in n:
            names.append((n, fix_text(f[3] or "") or ""))
    return names


def normalize_key(s: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", s.lower())


def main() -> None:
    conn = pymysql.connect(
        host=HOST, user=USER, password=PASS, database=DB_NAME, charset="utf8mb4"
    )
    cur = conn.cursor()
    cur.execute("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci")

    updated_rows = 0
    for table, pk, cols in TEXT_COLUMNS:
        col_list = ", ".join(f"`{c}`" for c in cols)
        cur.execute(f"SELECT `{pk}`, {col_list} FROM `{table}`")
        for row in cur.fetchall():
            pk_val = row[0]
            changes, params = [], []
            for i, col in enumerate(cols):
                old = row[i + 1]
                if old is None:
                    continue
                new = fix_text(old)
                if new != old:
                    changes.append(f"`{col}`=%s")
                    params.append(new)
            if changes:
                params.append(pk_val)
                cur.execute(
                    f"UPDATE `{table}` SET {', '.join(changes)} WHERE `{pk}`=%s",
                    params,
                )
                updated_rows += cur.rowcount

    person_names = load_supplier_names_cia35385()
    person_by_key = {normalize_key(n[0]): n for n in person_names}
    cur.execute("SELECT id, legal_name, trade_name FROM suppliers WHERE legal_name LIKE %s", ("%?%",))
    for sid, legal, trade in cur.fetchall():
        key = normalize_key(re.sub(r"\?+", "", legal or ""))
        match = None
        for pk, (pn, pl) in person_by_key.items():
            if key and (key in pk or pk in key):
                match = (pn, pl)
                break
        if match:
            cur.execute(
                "UPDATE suppliers SET legal_name=%s, trade_name=COALESCE(NULLIF(trade_name,''), %s) WHERE id=%s",
                (match[0], match[1] or trade, sid),
            )
            updated_rows += 1

    servicio_catalog: dict[str, tuple[str, float, int]] = {}
    for code, name, price, active in SERVICIO_SEED_DEFAULT:
        servicio_catalog[code] = (name, price, active)
    servicio_catalog.update(load_servicio_from_cia35385())
    if OC_DUMP.is_file():
        servicio_catalog.update(
            load_servicio_from_po_lines(OC_DUMP.read_text(encoding="utf-8", errors="replace"))
        )

    ins = upd = 0
    cur.execute("SELECT id, slug FROM tenants WHERE active=1")
    for tid, slug in cur.fetchall():
        for code, (name, price, active) in servicio_catalog.items():
            cur.execute(
                "INSERT INTO products (tenant_id, code, name, unit_price, active) "
                "VALUES (%s,%s,%s,%s,%s) "
                "ON DUPLICATE KEY UPDATE name=VALUES(name), unit_price=VALUES(unit_price), "
                "active=VALUES(active)",
                (tid, code[:32], name[:255], price, active),
            )
            if cur.rowcount == 1:
                ins += 1
            elif cur.rowcount == 2:
                upd += 1
        print(f"  tenant {slug} (id={tid}): catalogo servicio OK")

    conn.commit()
    cur.execute(
        "SELECT COUNT(*) FROM products WHERE code LIKE %s OR code=%s",
        ("servicio%", "gif"),
    )
    servicio_count = cur.fetchone()[0]
    conn.close()

    print(f"Text rows updated (approx): {updated_rows}")
    print(f"Servicio products inserted/updated: +{ins} / ~{upd}")
    print(f"Total servicio/gif in DB: {servicio_count}")
    print("Done.")


if __name__ == "__main__":
    main()
