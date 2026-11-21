from pathlib import Path
import re

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
t = p.read_text(encoding="utf-8")

# Quitar enlace a catalogo de productos en nueva OC
t2 = re.sub(
    r"\n            <a class=\"btn btn-default\" href=\"products\.php\?year=<\?= h\(\$year\) \?>\">.*?</a>",
    "",
    t,
    count=1,
    flags=re.DOTALL,
)

# Quitar boton/modal nuevo producto si quedara
t2 = re.sub(
    r"\n            <button type=\"button\" class=\"btn btn-default\" data-toggle=\"modal\" data-target=\"#nuevoProducto\">.*?</button>",
    "",
    t2,
    count=1,
    flags=re.DOTALL,
)
t2 = re.sub(
    r"\n    <div class=\"modal fade\" id=\"nuevoProducto\".*?\n    </div>\n\n    <form class=\"form-horizontal\" role=\"form\" id=\"oc-form\"",
    "\n    <form class=\"form-horizontal\" role=\"form\" id=\"oc-form\"",
    t2,
    count=1,
    flags=re.DOTALL,
)

if t2 == t:
    print("no change")
else:
    p.write_text(t2, encoding="utf-8", newline="\n")
    print("ok")
