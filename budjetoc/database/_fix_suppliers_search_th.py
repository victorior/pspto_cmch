# -*- coding: utf-8 -*-
import re
from pathlib import Path

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "ajax" / "suppliers_search.php"
t = p.read_text(encoding="utf-8", errors="replace")
th_rs = "<th><?= h(t_es('razon_social')) ?></th>"
th_tel = "<th><?= h(t_es('telefono')) ?></th>"
t = re.sub(r"<th>Raz[^<]*social</th>", th_rs, t)
t = re.sub(r"<th>Tel[^<]*fono</th>", th_tel, t)
p.write_text(t, encoding="utf-8")
print("suppliers_search: ok")
