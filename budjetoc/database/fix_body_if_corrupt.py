#!/usr/bin/env python3
"""Si quedaron literales rotos en oc_new_legacy_body.php, fuerza t_es()."""
from __future__ import annotations

import re
from pathlib import Path

BODY = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"

FIXES = [
    (
        re.compile(
            r'<label for="numero_oc" class="col-md-1 control-label">[^<]*OC</label>'
        ),
        '        <label for="numero_oc" class="col-md-1 control-label"><?= h(t_es(\'n_ordinal\')) ?> OC</label>',
    ),
    (
        re.compile(
            r'<label class="col-md-1 control-label">[^<]*Cta:?</label>'
        ),
        '        <label class="col-md-1 control-label"><?= h(t_es(\'n_cta\')) ?></label>',
    ),
    (
        re.compile(r'placeholder="Ej\. 9815-[^"]*"'),
        'placeholder="Ej. 9815-<?= (int) $budgetYear ?> <?= h(t_es(\'n_actividad_ej\')) ?>"',
    ),
    (
        re.compile(r'<p class="text-center text-muted small">[^<]*Adquisiciones</p>'),
        '<p class="text-center text-muted small"><?= h(t_es(\'uso_interno\')) ?></p>',
    ),
]


def main() -> None:
    text = BODY.read_text(encoding="utf-8", errors="replace")
    changed = 0
    for pat, repl in FIXES:
        new, n = pat.subn(repl, text, count=1)
        if n:
            text = new
            changed += n
    text = text.replace("\ufffd", "")
    if "t_es('n_cta')" not in text:
        raise SystemExit("n_cta missing after fix")
    BODY.write_text(text, encoding="utf-8", newline="\n")
    print("patched", changed, "patterns")


if __name__ == "__main__":
    main()
