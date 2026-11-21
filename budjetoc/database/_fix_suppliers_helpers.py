# -*- coding: utf-8 -*-
from pathlib import Path
import re

hp = Path(__file__).resolve().parents[1] / "src" / "helpers.php"
lines = hp.read_text(encoding="utf-8", errors="replace").splitlines(True)
for i, line in enumerate(lines):
    if line.strip().startswith("return ") and i > 150 and "function" not in line:
        if "division_name" in lines[i - 5] or (i > 190 and "}" in lines[i + 1]):
            lines[i] = '    return "\\u{2014}";\n'
            break
hp.write_text("".join(lines), encoding="utf-8")

sp = Path(__file__).resolve().parents[1] / "public" / "oc" / "suppliers.php"
st = sp.read_text(encoding="utf-8", errors="replace")
st = re.sub(
    r"\$layoutPageHeading = '[^']*';",
    "$layoutPageHeading = t_es('catalogo_proveedores');",
    st,
    count=1,
)
st = re.sub(
    r'<label for="q">[^<]*</label>',
    lambda _m: '<label for="q"><?= h(t_es(\'razon_social\')) ?>, nombre, email o <?= h(t_es(\'telefono\')) ?></label>',
    st,
    count=1,
)
sp.write_text(st, encoding="utf-8", newline="\n")
print("done")
