#!/usr/bin/env python3
"""Reemplaza etiquetas con mojibake por t_es() en oc_new_legacy_body.php."""
from pathlib import Path
import re

path = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
text = path.read_text(encoding="utf-8", errors="replace")

replacements = [
    (
        r'<input type="text" class="form-control" id="qq" placeholder="Ej\. 9815-\<\?= h\(\$year\) \?\>[^"]*" autocomplete="off">',
        '<input type="text" class="form-control" id="qq" placeholder="Ej. 9815-<?= h($year) ?> <?= h(t_es(\'n_actividad_ej\')) ?>" autocomplete="off">',
    ),
    (
        r'<label for="numero_oc" class="col-md-1 control-label">[^<]*OC</label>',
        '<label for="numero_oc" class="col-md-1 control-label"><?= h(t_es(\'n_ordinal\')) ?> OC</label>',
    ),
    (
        r'<label class="col-md-1 control-label">[^<]*Cta:[^<]*</label>',
        '<label class="col-md-1 control-label"><?= h(t_es(\'n_cta\')) ?></label>',
    ),
    (
        r'<p class="text-center text-muted small">Uso interno[^<]*</p>',
        '<p class="text-center text-muted small"><?= h(t_es(\'uso_interno\')) ?></p>',
    ),
]

for pattern, repl in replacements:
    text, n = re.subn(pattern, repl, text)
    print(f"{pattern[:40]}... -> {n}")

path.write_text(text, encoding="utf-8", newline="\n")
print("OK", path)
