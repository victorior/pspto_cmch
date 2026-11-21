# -*- coding: utf-8 -*-
"""One-off: fix U+FFFD / mojibake in OC nueva UI files."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "public" / "oc"


N_ORD = 'N<?= "\\u{00BA}" ?>'
N_ORD_PHP_OC = f'<label for="numero_oc" class="col-md-1 control-label">{N_ORD} OC</label>'
N_ORD_PHP_CTA = f'<label class="col-md-1 control-label">{N_ORD} Cta:</label>'
N_ORD_ACT_PLACEHOLDER = '(N<?= "\\u{00BA}" ?> actividad)'


def fix_body(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = re.sub(
        r'<label for="numero_oc" class="col-md-1 control-label">[^<]*OC</label>',
        lambda _m: N_ORD_PHP_OC,
        t,
    )
    t = re.sub(
        r'<label class="col-md-1 control-label">[^<]*Cta:</label>',
        lambda _m: N_ORD_PHP_CTA,
        t,
    )
    ph_act = f'placeholder="Ej. 9815-<?= h($year) ?> {N_ORD_ACT_PLACEHOLDER}"'
    t = re.sub(
        r'placeholder="Ej\. 9815-<\?= h\(\$year\) \?> \([^"]*actividad\)"',
        lambda _m: ph_act,
        t,
    )
    t = re.sub(r"N\ufffd\s*OC", lambda _m: f"{N_ORD} OC", t)
    t = re.sub(r"N\ufffd\s*Cta:", lambda _m: f"{N_ORD} Cta:", t)
    t = t.replace("N&ordm; OC", f"{N_ORD} OC")
    t = t.replace("N&ordm; Cta:", f"{N_ORD} Cta:")
    t = t.replace("(N&ordm; actividad)", N_ORD_ACT_PLACEHOLDER)
    t = re.sub(r"\(N\ufffd\s*actividad\)", lambda _m: N_ORD_ACT_PLACEHOLDER, t)
    t = t.replace("Uso interno &mdash; Adquisiciones", 'Uso interno <?= "\\u{2014}" ?> Adquisiciones')
    t = re.sub(r"Uso interno \ufffd Adquisiciones", lambda _m: 'Uso interno <?= "\\u{2014}" ?> Adquisiciones', t)
    if t != orig:
        path.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def fix_js(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = t.replace("' \ufffd debe igualar OC '", "' \\u2014 debe igualar OC '")
    t = t.replace("' \ufffd ' + esc(acct)", "' \\u2014 ' + esc(acct)")
    t = t.replace("</strong> \ufffd ' + esc(acct)", "</strong> \\u2014 ' + esc(acct)")
    t = re.sub(r"<label>N\ufffd req</label>", r"<label>N\\u00BA req</label>", t)
    # cp1252 mojibake when UTF-8 was misread
    t = t.replace("' \u2014 ' + esc(acct)", "' \\u2014 ' + esc(acct)")  # already real em dash -> escape
    for bad in ("\u2014", "\u00ba", "\ufffd", "\x97", "\xba"):
        t = t.replace(f"' {bad} ' + esc(acct)", "' \\u2014 ' + esc(acct)")
        t = t.replace(f"<label>N{bad} req</label>", r"<label>N\u00BA req</label>")
    if t != orig:
        path.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def debug_js(path: Path) -> None:
    out = ROOT.parents[1] / "database" / "_fix_debug.txt"
    lines = []
    for i, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
        if i in (87, 216, 221):
            lines.append(f"{i} {line!r}\n")
    out.write_text("".join(lines), encoding="utf-8")


def main() -> None:
    body = ROOT / "inc" / "oc_new_legacy_body.php"
    js = ROOT / "js" / "oc_new_legacy.js"
    print("body:", "ok" if fix_body(body) else "skip")
    print("js:", "ok" if fix_js(js) else "skip")


if __name__ == "__main__":
    main()
