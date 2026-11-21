from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# settings.php: quitar byte 0xa9 corrupto en placeholder
p = ROOT / "public" / "oc" / "settings.php"
t = p.read_bytes().decode("utf-8", errors="replace")
t = t.replace('placeholder="\ufffd IAE 2026"', 'placeholder="<?= h(t_es(\'copyright_iae\')) ?> 2026"')
t = t.replace('placeholder="\u00a9 IAE 2026"', 'placeholder="<?= h(t_es(\'copyright_iae\')) ?> 2026"')
if 'placeholder="<?= h(t_es' not in t:
    import re
    t = re.sub(
        r'placeholder="[^"]*IAE 2026"',
        'placeholder="<?= h(t_es(\'copyright_iae\')) ?> 2026"',
        t,
    )
p.write_text(t, encoding="utf-8", newline="\n")
print("settings.php OK")

# suppliers.php
p2 = ROOT / "public" / "oc" / "suppliers.php"
t2 = p2.read_bytes().decode("utf-8", errors="replace")
t2 = t2.replace("$layoutPageHeading = 'Cat\ufffdlogo de proveedores';", "$layoutPageHeading = t_es('catalogo_proveedores');")
t2 = t2.replace(
    '<label for="q">Raz\ufffdn social, nombre, email o tel\ufffdono</label>',
    "<label for=\"q\"><?= h(t_es('razon_social')) ?>, nombre, email o <?= h(t_es('telefono')) ?></label>",
)
import re
t2 = re.sub(
    r"\$layoutPageHeading = 'Cat[^']*logo de proveedores';",
    "$layoutPageHeading = t_es('catalogo_proveedores');",
    t2,
)
t2 = re.sub(
    r'<label for="q">Raz[^<]*</label>',
    "<label for=\"q\"><?= h(t_es('razon_social')) ?>, nombre, email o <?= h(t_es('telefono')) ?></label>",
    t2,
)
p2.write_text(t2, encoding="utf-8", newline="\n")
print("suppliers.php OK")
