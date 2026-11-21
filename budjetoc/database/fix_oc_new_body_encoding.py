#!/usr/bin/env python3
from pathlib import Path

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
t = p.read_text(encoding="utf-8", errors="replace")
t = t.replace(
    'name="folio_year" id="folio_year" value="<?= h($year) ?>"',
    'name="folio_year" id="folio_year"\n                 value="<?= h($folioYearDefault) ?>"<?= $isEdit ? \' readonly\' : \'\' ?>',
)
t = t.replace("N\ufffd OC", "<?= h(t_es('n_ordinal')) ?> OC")
t = t.replace("N\ufffd Cta:", "<?= h(t_es('n_cta')) ?>")
t = t.replace("Uso interno \ufffd Adquisiciones", "<?= h(t_es('uso_interno')) ?>")
p.write_text(t, encoding="utf-8", newline="\n")
print("OK")
