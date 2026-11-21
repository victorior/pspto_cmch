# -*- coding: utf-8 -*-
"""Move modals outside panel-body; strip data-toggle from OC action buttons."""
from pathlib import Path
import re

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
lines = p.read_text(encoding="utf-8").splitlines(True)

# Find modal block: from first product modal through end of nuevoCliente modal
start = None
form_i = None
for i, line in enumerate(lines):
    if start is None and 'id="myModal"' in line and "modal fade" in line:
        start = i
    if 'id="oc-form"' in line:
        form_i = i
        break

if start is None or form_i is None:
    raise SystemExit(f"markers not found start={start} form={form_i}")

modal_block = lines[start:form_i]
body_lines = lines[:start] + lines[form_i:]

# Insert modals after panel closes (before <hr>)
insert_at = None
for i, line in enumerate(body_lines):
    if line.strip() == "<hr>":
        insert_at = i
        break
if insert_at is None:
    insert_at = len(body_lines)

new_lines = body_lines[:insert_at] + ["\n"] + modal_block + ["\n"] + body_lines[insert_at:]

text = "".join(new_lines)
# Bootstrap 3 modal attrs
text = text.replace(
    'id="myModal" tabindex="-1" role="dialog"',
    'id="myModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="true" data-keyboard="true"',
)
text = text.replace(
    'id="myModal_actv" tabindex="-1" role="dialog"',
    'id="myModal_actv" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="true" data-keyboard="true"',
)
text = text.replace(
    'id="nuevoCliente" tabindex="-1" role="dialog"',
    'id="nuevoCliente" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="true" data-keyboard="true"',
)
# Buttons: JS opens modals (no data-toggle)
text = re.sub(
    r'(<button type="button" class="btn btn-default" id="btn_actv") data-toggle="modal" data-target="#myModal_actv"',
    r"\1",
    text,
)
text = re.sub(
    r'(<button type="button" class="btn btn-default" id="btn_add_line") data-toggle="modal" data-target="#myModal"',
    r"\1",
    text,
)
p.write_text(text, encoding="utf-8", newline="\n")
print("ok", "modals at", insert_at, "removed from panel start", start)
