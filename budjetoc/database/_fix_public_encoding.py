# -*- coding: utf-8 -*-
"""Replace mojibake / ASCII-only Spanish labels with t_es() in public PHP."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "public"

REPLACEMENTS: list[tuple[str, str]] = [
    ("$layoutPageHeading = 'Cat", "$layoutPageHeading = t_es('catalogo_proveedores'); // was Cat"),
    ("Raz", "RAZ_PLACEHOLDER"),
]

FILES = [
    ROOT / "oc" / "suppliers.php",
    ROOT / "oc" / "supplier_edit.php",
    ROOT / "oc" / "ajax" / "suppliers_search.php",
    ROOT / "oc" / "inc" / "oc_new_legacy_body.php",
    ROOT / "admin" / "tenant_catalog.php",
    ROOT / "admin" / "tenants.php",
    ROOT / "admin" / "user_departments.php",
    ROOT / "admin" / "inc" / "tenant_catalog_common.php",
    ROOT / "admin" / "tenant_catalog_activities.php",
]


def fix_suppliers(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = re.sub(
        r"\$layoutPageHeading = '[^']*proveedores';",
        "$layoutPageHeading = t_es('catalogo_proveedores');",
        t,
    )
    t = re.sub(
        r"<label for=\"q\">[^<]*</label>",
        lambda _m: "<label for=\"q\">" + "<?= h(t_es('razon_social')) ?>, nombre, email o <?= h(t_es('telefono')) ?></label>",
        t,
        count=1,
    )
    return write_if_changed(path, t, orig)


def fix_supplier_edit(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = re.sub(
        r"<label>Raz[^<]*social \*</label>",
        lambda _m: "<label><?= h(t_es('razon_social')) ?> *</label>",
        t,
    )
    t = re.sub(
        r"<label[^>]*>Tel[^<]*fono</label>",
        lambda _m: "<label><?= h(t_es('telefono')) ?></label>",
        t,
    )
    return write_if_changed(path, t, orig)


def fix_suppliers_search(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = re.sub(
        r"<th>Raz[^<]*social</th>",
        lambda _m: "<th><?= h(t_es('razon_social')) ?></th>",
        t,
    )
    t = re.sub(
        r"<th>Tel[^<]*fono</th>",
        lambda _m: "<th><?= h(t_es('telefono')) ?></th>",
        t,
    )
    return write_if_changed(path, t, orig)


def fix_oc_body(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = t.replace(
        "Nombre / Razon social",
        "Nombre / <?= h(t_es('razon_social')) ?>",
    )
    t = re.sub(
        r'<label for="tel1"[^>]*>Telefono</label>',
        '<label for="tel1" class="col-md-1 control-label"><?= h(t_es(\'telefono\')) ?></label>',
        t,
    )
    t = re.sub(
        r'<label for="prod-code"[^>]*>Codigo</label>',
        '<label for="prod-code" class="col-sm-3 control-label"><?= h(t_es(\'codigo\')) ?></label>',
        t,
    )
    t = t.replace('<option value="4">Credito</option>', '<option value="4"><?= h(t_es(\'credito\')) ?></option>')
    return write_if_changed(path, t, orig)


def fix_admin_catalog(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    subs = [
        ("'Catalogo replicado", "' . t_es('catalogo') . ' replicado"),
        ("'Catalogo del tenant'", "t_es('catalogo') . ' del tenant'"),
        ("'Catalogo compartido", "t_es('catalogo') . ' compartido"),
        ("catalogo a todos", "catalogo') . ' a todos"),  # risky
    ]
    # simpler regex
    t = re.sub(r"'Catalogo", "'. t_es('catalogo') . '", t)
    t = re.sub(r">Catalogo<", "><?= h(t_es('catalogo')) ?><", t)
    t = re.sub(r"cat\ufffdlogo", "catalogo", t)
    t = re.sub(r"cat.logo", "catalogo", t)
    t = re.sub(r"Raz\ufffdn", "razon", t)
    t = re.sub(r"tel\ufffdfono", "telefono", t)
    return write_if_changed(path, t, orig)


def fix_user_departments(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = re.sub(
        r"cat[^']*logo\. No hace falta guardar casillas aqu[^']*'",
        "'. t_es('catalogo') . '. No hace falta guardar casillas ' . t_es('aqui') . '.'",
        t,
    )
    return write_if_changed(path, t, orig)


def fix_tenant_catalog_common(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = t.replace("&larr; Catalogo del tenant", "&larr; <?= h(t_es('catalogo')) ?> del tenant")
    t = t.replace("'label' => 'Resumen catalogo'", "'label' => 'Resumen ' . t_es('catalogo')")
    t = t.replace("catalogo por tenant", t_es('catalogo') + " por tenant")  # invalid in single quotes file
    return write_if_changed(path, t, orig)


def write_if_changed(path: Path, t: str, orig: str) -> bool:
    if t != orig:
        path.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def main() -> None:
    jobs = [
        (ROOT / "oc" / "suppliers.php", fix_suppliers),
        (ROOT / "oc" / "supplier_edit.php", fix_supplier_edit),
        (ROOT / "oc" / "ajax" / "suppliers_search.php", fix_suppliers_search),
        (ROOT / "oc" / "inc" / "oc_new_legacy_body.php", fix_oc_body),
        (ROOT / "admin" / "tenant_catalog.php", fix_admin_catalog),
        (ROOT / "admin" / "tenants.php", fix_admin_catalog),
        (ROOT / "admin" / "user_departments.php", fix_user_departments),
    ]
    for path, fn in jobs:
        print(path.name, "ok" if fn(path) else "skip")


if __name__ == "__main__":
    main()
