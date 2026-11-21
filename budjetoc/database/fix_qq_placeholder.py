#!/usr/bin/env python3
from pathlib import Path
import re

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
t = p.read_text(encoding="utf-8", errors="replace")
t2, n = re.subn(
    r'id="qq" placeholder="[^"]*"',
    'id="qq" placeholder="Ej. 9815-<?= (int) $budgetYear ?> <?= h(t_es(\'n_actividad_ej\')) ?>"',
    t,
    count=1,
)
if n:
    p.write_text(t2, encoding="utf-8", newline="\n")
    print("OK")
else:
    print("NO MATCH")
