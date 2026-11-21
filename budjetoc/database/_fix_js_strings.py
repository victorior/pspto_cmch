# -*- coding: utf-8 -*-
from pathlib import Path
import re

p = Path(__file__).resolve().parents[1] / "public" / "oc" / "js" / "oc_new_legacy.js"
t = p.read_text(encoding="utf-8", errors="replace")
t = re.sub(
    r"\$hint\.text\('Total imputado: ' \+ money0\(al\) \+ ' .*? debe igualar OC '",
    lambda _m: "$hint.text('Total imputado: ' + money0(al) + ' \\u2014 debe igualar OC '",
    t,
)
t = re.sub(
    r"\+ '</strong> .*? ' \+ esc\(acct\)",
    lambda _m: "+ '</strong> \\u2014 ' + esc(acct)",
    t,
)
t = re.sub(r"<label>N.+? req</label>", lambda _m: r"<label>N\u00BA req</label>", t)
t = re.sub(
    r"cuentas contables \(N. Cta\)",
    lambda _m: "cuentas contables (N\\u00BA Cta)",
    t,
)
t = re.sub(
    r"primera cuenta \(N. Cta\)",
    lambda _m: "primera cuenta (N\\u00BA Cta)",
    t,
)
p.write_text(t, encoding="utf-8", newline="\n")
print("ok")
