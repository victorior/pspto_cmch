#!/usr/bin/env python3
"""Revisa encoding en pantallas OC. Exit 1 si hay problemas."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OC = ROOT / "public" / "oc"
SRC = ROOT / "src" / "helpers.php"

BAD_CHARS = {"\ufffd"}
MOJIBAKE_PATTERNS = [
    re.compile(r"<label[^>]*>N[^\w<]{0,3}\s*OC", re.I),
    re.compile(r"<label[^>]*>N[^\w<]{0,3}\s*Cta", re.I),
    re.compile(r"<th>N[^\w<]{0,3}\s*OC", re.I),
]
SCAN_GLOBS = ["**/*.php", "**/*.js"]


def file_issues(path: Path, text: str) -> list[str]:
    issues: list[str] = []
    rel = path.relative_to(ROOT).as_posix()

    for i, line in enumerate(text.splitlines(), 1):
        if any(ch in line for ch in BAD_CHARS):
            issues.append(f"{rel}:{i}: U+FFFD")
        if "\x00" in line:
            issues.append(f"{rel}:{i}: byte NUL")

    for pat in MOJIBAKE_PATTERNS:
        for m in pat.finditer(text):
            line = text[: m.start()].count("\n") + 1
            issues.append(f"{rel}:{line}: mojibake N ({m.group(0)[:40]!r})")

    if path.suffix == ".php" and "helpers.php" not in rel:
        for i, line in enumerate(text.splitlines(), 1):
            if "\u2014" in line or "\u2013" in line:
                if "t_es(" not in line and "&mdash;" not in line:
                    issues.append(f"{rel}:{i}: guion largo literal")
            if re.search(r":\s*'[^']*[\ufffd]", line):
                issues.append(f"{rel}:{i}: placeholder corrupto")

        for i, line in enumerate(text.splitlines(), 1):
            if "t_es(" in line:
                continue
            if re.search(r"N[\u00ba\uFFFD]|N\u00ba", line) and any(
                x in line for x in ("OC", "Cta", "Actv", "empresa", "actividad")
            ):
                issues.append(f"{rel}:{i}: N ordinal literal sin t_es()")

    return issues


def verify_helpers() -> list[str]:
    issues: list[str] = []
    text = SRC.read_text(encoding="utf-8", errors="replace")
    for key in ("n_ordinal", "n_cta", "sin_valor", "em_dash", "n_ordinal_colon"):
        if f"'{key}'" not in text:
            issues.append(f"src/helpers.php: falta t_es('{key}')")
    if "\ufffd" in text:
        issues.append("src/helpers.php: U+FFFD")
    return issues


def main() -> int:
    all_issues: list[str] = []
    all_issues.extend(verify_helpers())

    for pattern in SCAN_GLOBS:
        for path in sorted(OC.glob(pattern)):
            if not path.is_file():
                continue
            try:
                text = path.read_bytes().decode("utf-8")
            except UnicodeDecodeError as e:
                all_issues.append(f"{path.relative_to(ROOT)}: no UTF-8 ({e})")
                continue
            all_issues.extend(file_issues(path, text))

    if all_issues:
        print("FALLO encoding OC (%d):" % len(all_issues))
        for item in all_issues:
            print("  -", item)
        return 1

    print("OK: encoding OC sin problemas.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
