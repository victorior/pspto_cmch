- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: Import legado (LegacyOcImportService) no escribe tax_* en purchase_orders
  evidence: Filas importadas quedan en inferencia hasta edición explícita; fuera del flujo create/edit de 1.1

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: Falta test HTTP/CLI del POST create sin tax_doc_type en oc_new.php
  evidence: Sin harness de sesión web; cerrado parcialmente con round-trip OcService; smoke manual pendiente

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: run_verification_tests.py aún no lee db.port numérico
  evidence: Preexistente; apply/verify de migraciones sí leen port; en XAMPP 3307 el script amplio puede fallar conexión
