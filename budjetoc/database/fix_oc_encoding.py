#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Corrige bytes corruptos y etiquetas OC. Ejecutar antes de check_oc_encoding.py."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HELPERS = ROOT / "src" / "helpers.php"
PRINT = ROOT / "public" / "oc" / "print.php"
BODY = ROOT / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
INDEX = ROOT / "public" / "oc" / "index.php"
BUDGET = ROOT / "public" / "oc" / "budget.php"

SIN_VALOR_PHP = "<?= h(t_es('sin_valor')) ?>"
EM_DASH_PHP = "<?= h(t_es('em_dash')) ?>"


def fix_helpers() -> bool:
    t = HELPERS.read_text(encoding="utf-8", errors="replace")
    orig = t
    if "\ufffd" in t:
        lines = []
        for line in t.splitlines():
            if line.strip().startswith("return") and "\ufffd" in line:
                lines.append("    return t_es('sin_valor');")
            else:
                lines.append(line)
        t = "\n".join(lines) + "\n"
    if "'n_ordinal_colon'" not in t:
        t = t.replace(
            "'n_ordinal' => \"N\\u{00BA}\",",
            "'n_ordinal' => \"N\\u{00BA}\",\n        'n_ordinal_colon' => \"N\\u{00BA}:\",\n"
            "        'n_actv_monto' => \"N\\u{00BA} Actv, Monto:\",\n"
            "        'orden_compra' => \"ORDEN DE COMPRA\",\n"
            "        'factura' => \"Factura\",\n"
            "        'sin_valor' => \"\\u{2014}\",\n"
            "        'em_dash' => \"\\u{2014}\",",
        )
    t = re.sub(r"return\s+'[\ufffd\u2014\-]';", "return t_es('sin_valor');", t)
    if t != orig:
        HELPERS.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def fix_print() -> bool:
    t = PRINT.read_text(encoding="utf-8", errors="replace")
    orig = t

    t = re.sub(
        r"<h2>ORDEN DE COMPRA\s*<\?=\s*h\(t_es\('n_ordinal'\)\)\s*\?>\s*<\?=\s*\$numeroOc\s*\?></h2>",
        "<h2><?= h(t_es('orden_compra')) ?> <?= h(t_es('n_ordinal')) ?> <?= $numeroOc ?></h2>",
        t,
    )
    t = re.sub(
        r"<p><strong>Boleta de honorarios</strong>\s*(?:&mdash;|[^<]*)\s*<strong>\s*<\?=\s*h\(t_es\('n_ordinal'\)\)\s*\?>:</strong>\s*"
        r"<\?=\s*\$invoiceNumberIn\s*>\s*0\s*\?\s*h\(\(string\)\s*\$invoiceNumberIn\)\s*:\s*'[^']*'\s*\?></p>",
        "<p><strong>Boleta de honorarios</strong> " + EM_DASH_PHP + " <strong><?= h(t_es('n_ordinal_colon')) ?></strong>\n"
        "        <?= $invoiceNumberIn > 0 ? h((string) $invoiceNumberIn) : " + SIN_VALOR_PHP + " ?></p>",
        t,
    )
    t = re.sub(
        r"<p><strong>Factura\s*(?:&mdash;|[^<]*)\s*<\?=\s*h\(t_es\('n_ordinal'\)\)\s*\?>:</strong>\s*"
        r"<\?=\s*\$invoiceNumberIn\s*>\s*0\s*\?\s*h\(\(string\)\s*\$invoiceNumberIn\)\s*:\s*'[^']*'\s*\?></p>",
        "<p><strong><?= h(t_es('factura')) ?></strong> " + EM_DASH_PHP + " <strong><?= h(t_es('n_ordinal_colon')) ?></strong>\n"
        "        <?= $invoiceNumberIn > 0 ? h((string) $invoiceNumberIn) : " + SIN_VALOR_PHP + " ?></p>",
        t,
    )
    t = re.sub(
        r"<\?=\s*\$reqNro\s*!==\s*''\s*\?\s*h\(\$reqNro\)\s*:\s*'[^']*'\s*\?>",
        "<?= $reqNro !== '' ? h($reqNro) : " + SIN_VALOR_PHP + " ?>",
        t,
    )
    t = re.sub(
        r"<\?=\s*\$budgetInline\s*!==\s*''\s*\?\s*\$budgetInline\s*:\s*'[^']*'\s*\?>",
        "<?= $budgetInline !== '' ? $budgetInline : " + SIN_VALOR_PHP + " ?>",
        t,
    )
    t = re.sub(
        r"<p><strong><\?=\s*h\(t_es\('n_ordinal'\)\)\s*\?>\s*Actv,\s*Monto:</strong>",
        "<p><strong><?= h(t_es('n_actv_monto')) ?></strong>",
        t,
    )

    if t != orig:
        PRINT.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def fix_body() -> bool:
    t = BODY.read_text(encoding="utf-8", errors="replace")
    orig = t
    reps = [
        (
            r'<label for="numero_oc" class="col-md-1 control-label">[^<]*OC</label>',
            '<label for="numero_oc" class="col-md-1 control-label"><?= h(t_es(\'n_ordinal\')) ?> OC</label>',
        ),
        (
            r'<label class="col-md-1 control-label">[^<]*Cta:</label>',
            '<label class="col-md-1 control-label"><?= h(t_es(\'n_cta\')) ?></label>',
        ),
        (
            r'placeholder="Ej\. 9815-<\?= h\(\$year\) \?>[^"]*"',
            'placeholder="Ej. 9815-<?= h($year) ?> <?= h(t_es(\'n_actividad_ej\')) ?>"',
        ),
        (
            r'<p class="text-center text-muted small">Uso interno[^<]*</p>',
            '<p class="text-center text-muted small"><?= h(t_es(\'uso_interno\')) ?></p>',
        ),
    ]
    for pat, repl in reps:
        t = re.sub(pat, repl, t)
    if t != orig:
        BODY.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def fix_index() -> bool:
    t = INDEX.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = re.sub(r"<th>N[^<]*OC</th>", "<th><?= h(t_es('n_ordinal')) ?> OC</th>", t)
    if t != orig:
        INDEX.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def main() -> int:
    changed = []
    for name, fn in [
        ("helpers.php", fix_helpers),
        ("print.php", fix_print),
        ("oc_new_legacy_body.php", fix_body),
        ("index.php", fix_index),
    ]:
        if fn():
            changed.append(name)
    print("Corregido:" if changed else "Sin cambios:", ", ".join(changed) or "(nada)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
