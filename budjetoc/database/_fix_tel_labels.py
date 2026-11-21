from pathlib import Path

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
t = p.read_text(encoding="utf-8")
t = t.replace(
    '<label for="prov-phone" class="col-sm-3 control-label">Telefono</label>',
    '<label for="prov-phone" class="col-sm-3 control-label"><?= h(t_es(\'telefono\')) ?></label>',
)
t = t.replace(
    '<label for="tel1" class="col-md-1 control-label">Telefono</label>',
    '<label for="tel1" class="col-md-1 control-label"><?= h(t_es(\'telefono\')) ?></label>',
)
p.write_text(t, encoding="utf-8")
print("ok")
