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
  summary: FIX aplicado tras rechazo 1.4 — no reabrir Cerrada/Anulada; cerrar exige invoice_number_in
  evidence: OcService::assertStatusTransition + update.php + UI; test_oc_status_rules.php

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-elegir-tipo-de-documento-y-persistir-perfil-base.md`
  summary: FIX — ocultar Historial OC anuladas en activity_view / oc budget (solo vigentes)
  evidence: Pedido walkthrough 1.1; bloque UI removido; API allocationsForHeaderVoided se mantiene

- source_spec: `_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/1-pantalla-admin-plantilla-csv.md`
  summary: Plantilla CSV de ejemplo no se personaliza con catalogos del tenant elegido
  evidence: sampleCsvContent() es generico; tenant_id en URL es contexto UI; alinear ejemplos a catalogs queda para historias 2-3

- source_spec: `_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/1-pantalla-admin-plantilla-csv.md`
  summary: Nav Carga actividades no conserva tenant_id al navegar
  evidence: AdminAccess::navItems es global; requeriria sesion o query en todos los links; fuera del minimo de Story 1

- source_spec: `_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/1-pantalla-admin-plantilla-csv.md`
  summary: Sin test automatico de alineacion templateHeaders vs plantilla-import.md
  evidence: Contrato documentado; drift se detectara al implementar validacion Story 2

- source_spec: `_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/2-validacion-previa-compacta.md`
  summary: Sin smoke HTTP del POST validate_csv (CSRF, flash, reporte UI) en budget_activities_import.php
  evidence: Repo sin harness de sesion admin; smoke CLI cubre servicio; UI queda a chequeo manual (mismo patron que import usuarios)

- source_spec: `_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/3-confirmar-altas-de-actividades.md`
  summary: Sin smoke HTTP del POST confirm_csv / CTA $showConfirm en budget_activities_import.php
  evidence: Mismo hueco que validate_csv; CLI cubre importUploadedCsv; falta harness sesion admin

- source_spec: `_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/3-confirmar-altas-de-actividades.md`
  summary: syncActivitySequenceFromNumber sin FOR UPDATE (posible race concurrente)
  evidence: Copia el patron Records2026; nextActivityNumber si usa FOR UPDATE; carga SA tipicamente serial

- source_spec: `_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/3-confirmar-altas-de-actividades.md`
  summary: Ventana TOCTOU entre validate OK e INSERT (catalogo/duplicado concurrente)
  evidence: maybe-false medium; re-validate mitiga; no hay test de carrera

- source_spec: `_bmad-output/specs/spec-admin-org-y-eliminar-actividades/stories/2-editar-catalogo-org-con-paridad-multi-tenant.md`
  summary: Race check-then-UPDATE en rename multi-tenant de catálogo org sin locks FOR UPDATE
  evidence: Entre exists checks y UPDATE otro proceso podría insertar/borrar; patrón preexistente del catálogo; SA único hace el escenario raro

- source_spec: `_bmad-output/specs/spec-admin-org-y-eliminar-actividades/stories/2-editar-catalogo-org-con-paridad-multi-tenant.md`
  summary: Throwable en rename de CatalogService se re-lanza tras rollback en lugar de devolver {ok:false}
  evidence: Mismo patrón que otros servicios; handlers admin no capturan Throwable y pueden 500

- source_spec: `_bmad-output/specs/spec-admin-org-y-eliminar-actividades/stories/3-eliminar-catalogo-org-con-guard-de-actividades.md`
  summary: Posible TOCTOU entre check CAP-2 y DELETE de departments (FK SET NULL en budget_headers)
  evidence: maybe-false medium; CC protegido por RESTRICT; asentar con prueba concurrente o SELECT ... FOR UPDATE + re-check en la misma TX
