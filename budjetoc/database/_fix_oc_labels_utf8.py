# -*- coding: utf-8 -*-
"""Fix mojibake labels in OC UI; use t_es() for all Spanish ordinals and accents."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "public" / "oc"


def fix_body(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t

    replacements = [
        (r'<label for="numero_oc" class="col-md-1 control-label">[^<]*OC</label>',
         '<label for="numero_oc" class="col-md-1 control-label"><?= h(t_es(\'n_ordinal\')) ?> OC</label>'),
        (r'<label class="col-md-1 control-label">[^<]*Cta:</label>',
         '<label class="col-md-1 control-label"><?= h(t_es(\'n_cta\')) ?></label>'),
        (r'placeholder="Ej\. 9815-<\?= h\(\$year\) \?> \([^"]*actividad\)"',
         'placeholder="Ej. 9815-<?= h($year) ?> <?= h(t_es(\'n_actividad_ej\')) ?>"'),
        (r'<label for="prov-legal" class="col-sm-3 control-label">Nombre / Razon social</label>',
         '<label for="prov-legal" class="col-sm-3 control-label">Nombre / <?= h(t_es(\'razon_social\')) ?></label>'),
        (r'<label for="prov-trade" class="col-sm-3 control-label">N\. empresa</label>',
         '<label for="prov-trade" class="col-sm-3 control-label"><?= h(t_es(\'n_empresa\')) ?></label>'),
        (r'<label for="prov-phone" class="col-sm-3 control-label">Telefono</label>',
         '<label for="prov-phone" class="col-sm-3 control-label"><?= h(t_es(\'telefono\')) ?></label>'),
        (r'<label for="id_vendedor" class="col-md-1 control-label">Nro\. Req\.</label>',
         '<label for="id_vendedor" class="col-md-1 control-label"><?= h(t_es(\'nro_req\')) ?></label>'),
        (r'placeholder="Nro\. de Requerimiento"',
         'placeholder="<?= h(t_es(\'nro_req_ph\')) ?>"'),
        (r'<option value="6">Pago a 15 dias</option>',
         '<option value="6"><?= h(t_es(\'pago_15_dias\')) ?></option>'),
        (r'<option value="5">Pago a 7 dias</option>',
         '<option value="5"><?= h(t_es(\'pago_7_dias\')) ?></option>'),
        (r'<option value="2">Pago a 30 dias</option>',
         '<option value="2"><?= h(t_es(\'pago_30_dias\')) ?></option>'),
        (r'<option value="4">Credito</option>',
         '<option value="4"><?= h(t_es(\'credito\')) ?></option>'),
        (r'Uso interno[^<]*Adquisiciones',
         '<?= h(t_es(\'uso_interno\')) ?>'),
    ]
    for pat, repl in replacements:
        t = re.sub(pat, repl, t)

    if t != orig:
        path.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def fix_index(path: Path) -> bool:
    t = path.read_text(encoding="utf-8", errors="replace")
    orig = t
    t = re.sub(r"<th>N[^<]*OC</th>", "<th><?= h(t_es('n_ordinal')) ?> OC</th>", t)
    if t != orig:
        path.write_text(t, encoding="utf-8", newline="\n")
        return True
    return False


def main() -> None:
    body = ROOT / "inc" / "oc_new_legacy_body.php"
    idx = ROOT / "index.php"
    print("body:", "ok" if fix_body(body) else "skip")
    print("index:", "ok" if fix_index(idx) else "skip")


if __name__ == "__main__":
    main()
