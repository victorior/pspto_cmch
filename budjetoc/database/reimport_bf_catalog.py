# -*- coding: utf-8 -*-
"""Re-importa catalogo legacy desde cna14865_budgetflow.sql a budjetoc (unica BD)."""
from setup_unified import import_catalog_inline, HOST, USER, PASS, ROOT, PSPTO

DB = "budjetoc"
bf = ROOT / "cna14865_budgetflow.sql"
oc = PSPTO / "cna14865_oc_multitenant.sql"
if not oc.is_file():
    oc = ROOT / "cna14865_oc_multitenant.sql"
import_catalog_inline(HOST, USER, PASS, DB, bf, oc)
