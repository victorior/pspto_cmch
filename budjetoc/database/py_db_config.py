"""Lee config/config.php. En env=local exige db.name=budjetoc."""
from __future__ import annotations

import re
import sys
from pathlib import Path

LOCAL_REQUIRED_DB = "budjetoc"


def project_root() -> Path:
    return Path(__file__).resolve().parents[1]


def db_cfg(root: Path | None = None) -> dict:
    root = root or project_root()
    text = (root / "config" / "config.php").read_text(encoding="utf-8", errors="replace")

    def grab(key: str, default: str = "") -> str:
        m = re.search(rf"'{re.escape(key)}'\s*=>\s*'([^']*)'", text)
        return m.group(1) if m else default

    env = grab("env", "local")
    name = grab("name", LOCAL_REQUIRED_DB)
    if env == "local" and name != LOCAL_REQUIRED_DB:
        print(
            f"ERROR: en local db.name debe ser '{LOCAL_REQUIRED_DB}', no '{name}'.",
            file=sys.stderr,
        )
        sys.exit(1)
    if not re.fullmatch(r"[A-Za-z0-9_]+", name or ""):
        print("ERROR: db.name invalido o vacio.", file=sys.stderr)
        sys.exit(1)

    port_m = re.search(r"'port'\s*=>\s*(\d+)", text)
    cfg = {
        "host": grab("host", "127.0.0.1"),
        "user": grab("user", "root"),
        "password": grab("pass", ""),
        "database": name,
        "charset": grab("charset", "utf8mb4"),
    }
    if port_m:
        cfg["port"] = int(port_m.group(1))
    return cfg
