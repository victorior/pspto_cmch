- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: Import legado (LegacyOcImportService) no escribe tax_* en purchase_orders
  evidence: Filas importadas quedan en inferencia hasta edición explícita; fuera del flujo create/edit de 1.1

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: Falta test HTTP/CLI del POST create sin tax_doc_type en oc_new.php
  evidence: Sin harness de sesión web; cerrado parcialmente con round-trip OcService; smoke manual pendiente

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: run_verification_tests.py aún no lee db.port numérico
  evidence: Preexistente; apply/verify de migraciones sí leen port; en XAMPP 3307 el script amplio puede fallar conexión

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: activity_view muestra sección Historial de imputaciones reversadas cuando solo deberían verse OC vigentes
  evidence: Hallazgo manual en walkthrough 1.1 (activity_view.php?id=3); fuera del alcance de perfil tributario; no tocado en el diff 1.1 — pendiente post-1.2

- source_spec: `_bmad-output/implementation-artifacts/spec-1-2-factura-afecta-iva-o-exenta-totales-e-impresion.md`
  summary: Sin harness JS para refreshDocTotals rama Factura+Exenta
  evidence: Repo sin runner JS; smoke manual de totales en browser

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-cambiar-perfil-tributario-cualquier-estado.md`
  summary: activity_view Historial imputaciones reversadas (pendiente post-1.4)
  evidence: Hallazgo walkthrough 1.1; no tocado en fix de reglas de cierre/reapertura

- source_spec: `_bmad-output/implementation-artifacts/spec-1-4-cambiar-perfil-tributario-cualquier-estado.md`
  summary: FIX aplicado tras rechazo 1.4 — no reabrir Cerrada/Anulada; cerrar exige invoice_number_in
  evidence: OcService::assertStatusTransition + update.php + UI; test_oc_status_rules.php
