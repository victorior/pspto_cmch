"""Helpers para leer cna14865_budgetflow.sql (port de sql_import_helpers.php)."""

from __future__ import annotations

import re
from typing import Any


def bud_sql_extract_insert_tuples(dump: str, table: str) -> list[str]:
    pattern = (
        r"INSERT INTO `"
        + re.escape(table)
        + r"`\s*\([^)]+\)\s*VALUES\s*(.*?);"
    )
    m = re.search(pattern, dump, re.DOTALL)
    if not m:
        return []
    body = m.group(1)
    rows: list[str] = []
    length = len(body)
    i = 0
    while i < length:
        while i < length and body[i] in " \n\r\t,":
            i += 1
        if i >= length or body[i] != "(":
            break
        depth = 0
        start = i
        in_string = False
        escape = False
        while i < length:
            c = body[i]
            if in_string:
                if escape:
                    escape = False
                elif c == "\\":
                    escape = True
                elif c == "'":
                    in_string = False
                i += 1
                continue
            if c == "'":
                in_string = True
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


def bud_sql_trim_field(v: str) -> str | None:
    v = v.strip()
    if v.upper() == "NULL":
        return None
    return v


def bud_sql_parse_tuple(tuple_s: str) -> list[str | None]:
    tuple_s = tuple_s.strip()
    if tuple_s.startswith("("):
        tuple_s = tuple_s[1:-1]
    fields: list[str | None] = []
    buf: list[str] = []
    in_string = False
    escape = False
    for c in tuple_s:
        if in_string:
            if escape:
                buf.append(c)
                escape = False
            elif c == "\\":
                escape = True
            elif c == "'":
                in_string = False
            else:
                buf.append(c)
            continue
        if c == "'":
            in_string = True
            continue
        if c == ",":
            fields.append(bud_sql_trim_field("".join(buf)))
            buf = []
            continue
        buf.append(c)
    fields.append(bud_sql_trim_field("".join(buf)))
    return fields


def bud_legacy_source_tenants() -> dict[int, dict[str, str]]:
    return {
        1: {"name": "Colegio CMCH", "slug": "cmch"},
        2: {"name": "IAE", "slug": "iae"},
    }


def load_budjetoc_config(root: Any) -> dict[str, Any]:
    """Lee config/config.php (solo claves usadas por import)."""
    from pathlib import Path

    path = Path(root) / "config" / "config.php"
    text = path.read_text(encoding="utf-8")

    def quoted(key: str, default: str = "") -> str:
        m = re.search(rf"'{re.escape(key)}'\s*=>\s*'([^']*)'", text)
        return m.group(1) if m else default

    db = {
        "host": quoted("host", "127.0.0.1"),
        "name": quoted("name", "budjetoc"),
        "user": quoted("user", "root"),
        "pass": quoted("pass", ""),
        "charset": quoted("charset", "utf8mb4"),
    }
    dump = Path(root) / "cna14865_budgetflow.sql"
    if "legacy_sql_dump" in text:
        m = re.search(r"'legacy_sql_dump'\s*=>\s*dirname\(__DIR__\)\s*\.\s*'([^']+)'", text)
        if m:
            dump = Path(root) / m.group(1).lstrip("/\\")
    return {
        "db": db,
        "legacy_sql_dump": dump,
        "legacy_user_import_password": quoted(
            "legacy_user_import_password", "BudgetOC2026!"
        ),
    }
