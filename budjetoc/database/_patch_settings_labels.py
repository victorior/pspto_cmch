from pathlib import Path

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "settings.php"
lines = p.read_text(encoding="utf-8", errors="replace").splitlines()
out = []
for line in lines:
    if "Linea 2" in line and "<label>" in line:
        out.append(
            "        <label>Linea 1 del pie - Horario (misma linea que Los productos...)</label>"
        )
    elif "Linea 3" in line and "<label>" in line:
        out.append("        <label>Linea 2 del pie - Direccion (Recepcion por ...)</label>")
    else:
        out.append(line)
p.write_text("\n".join(out) + "\n", encoding="utf-8")
print("ok")
