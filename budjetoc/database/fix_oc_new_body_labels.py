#!/usr/bin/env python3
from pathlib import Path

BODY = Path(__file__).resolve().parents[1] / "public" / "oc" / "inc" / "oc_new_legacy_body.php"
NUMERO_OC = "        <label for=\"numero_oc\" class=\"col-md-1 control-label\"><?= h(t_es('n_ordinal')) ?> OC</label>"
N_CTA = "        <label class=\"col-md-1 control-label\"><?= h(t_es('n_cta')) ?></label>"
PIE = "<p class=\"text-center text-muted small\"><?= h(t_es('uso_interno')) ?></p>"


def main() -> None:
    lines = BODY.read_text(encoding="utf-8", errors="replace").splitlines()
    for i, line in enumerate(lines):
        if "numero_oc" in line and "control-label" in line:
            lines[i] = NUMERO_OC
            print("numero_oc line", i + 1)
        stripped = line.strip()
        if stripped.startswith("<label class=\"col-md-1 control-label\"") and "Cta" in line:
            lines[i] = N_CTA
            print("n_cta line", i + 1)
        if stripped.startswith("<p class=\"text-center text-muted small\">"):
            lines[i] = PIE
            print("pie line", i + 1)
    text = "\n".join(lines) + "\n"
    text = text.replace("\ufffd", "")
    BODY.write_text(text, encoding="utf-8", newline="\n")
    print("written", BODY)


if __name__ == "__main__":
    main()
