#!/usr/bin/env python3
"""Reescribe oc_new_legacy_body.php en UTF-8 limpio (lineas criticas)."""
from __future__ import annotations

from pathlib import Path

BODY = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"

REPLACEMENTS = {
    71: '                  <input type="text" class="form-control" id="qq" placeholder="Ej. 9815-<?= (int) $budgetYear ?> <?= h(t_es(\'n_actividad_ej\')) ?>" autocomplete="off">',
    261: '        <label for="numero_oc" class="col-md-1 control-label"><?= h(t_es(\'n_ordinal\')) ?> OC</label>',
    268: '        <label class="col-md-1 control-label"><?= h(t_es(\'n_cta\')) ?></label>',
    402: '<p class="text-center text-muted small"><?= h(t_es(\'uso_interno\')) ?></p>',
}


def main() -> None:
    lines = BODY.read_text(encoding="utf-8", errors="replace").splitlines()
    for num, content in REPLACEMENTS.items():
        if 1 <= num <= len(lines):
            lines[num - 1] = content
    text = "\n".join(lines) + "\n"
    text = text.replace("\ufffd", "")
    BODY.write_text(text, encoding="utf-8", newline="\n")
    print("OK lines", sorted(REPLACEMENTS.keys()))


if __name__ == "__main__":
    main()
