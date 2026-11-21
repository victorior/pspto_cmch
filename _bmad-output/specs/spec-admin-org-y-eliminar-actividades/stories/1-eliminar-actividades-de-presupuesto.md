---
title: 'Eliminar actividades de Presupuesto'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '6d6a379269cea069376c1ea7828d58580001d985'
context:
  - '{project-root}/_bmad-output/specs/spec-admin-org-y-eliminar-actividades/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-admin-org-y-eliminar-actividades/brownfield.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Encargados de división/coordinación no pueden eliminar actividades de Presupuesto propias aunque el año esté editable; super admin y tenant admin tampoco tienen un flujo de borrado de cabeceras. Eso impide limpieza operativa y desbloquear borrados de catálogo orgánico.

**Approach:** Añadir eliminación de `budget_headers` (+ detalles) en el portal Presupuesto: encargado solo propias con permiso de edición del año; super admin y tenant admin sin ownership ni gate de año; todos bloqueados si hay imputaciones OC.

## Boundaries & Constraints

**Always:**
- Actividad = `budget_headers` + `budget_details` (CASCADE).
- Encargado: solo actividades propias (`created_by` / fallback legacy `department_id`) y solo si el permiso de edición del año está activo (misma fuente que `canEditActivityHeader`).
- Super admin y tenant admin (`isBudgetTenantAdmin`): pueden eliminar cualquier actividad del `tenant_id` de trabajo; sin ownership ni gate de año.
- Si `BudgetService::headerUtilized` (o equivalente) > 0 → rechazar delete con mensaje claro; no borrar parcial.
- POST + CSRF; realm presupuesto; scope estricto por `tenant_id`.
- Rama/worktree: `feature/admin-org-y-eliminar-actividades`.
- Decisión: tenant admin **sí** elimina (opción B), alineado a quien hoy puede editar a nivel tenant.

**Never:**
- Soft-delete / archivado.
- Borrar actividades (o forzar) con imputación OC.
- Usar `canViewActivityHeader` como gate de delete (es más amplio que edit).
- Tocar OC portal, import Records2026, `reset_operational_data`, ni catálogo orgánico (stories 2–3).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Encargado OK | Propia, año editable, sin OC | Header+details eliminados; redirect a listado | N/A |
| Encargado sin permiso año | Propia, `allow_edit=0` | No delete | 403 / mensaje solo lectura |
| Encargado ajena | `created_by` fuera de sus unidades | No delete | 403 |
| SA / tenant admin | Actividad de otro dept, año “cerrado” para encargados, sin OC | Elimina | N/A |
| Con OC | Cualquier rol, `headerUtilized` > 0 | No delete; entidad intacta | Mensaje imputación OC |
| CSRF inválido | POST sin/ spoof `_csrf` | No delete | 403 |

</frozen-after-approval>

## Code Map

- `budjetoc/src/UserPermissions.php` — `canEditActivityHeader`, `isBudgetTenantAdmin`, `resolvePolicyDepartmentId`; **añadir** `canDeleteActivityHeader` (encargado exige “propia”; SA/tenant_admin no).
- `budjetoc/src/BudgetService.php` — `headerUtilized` / `utilizedSumsByHeaderIds`; **añadir** `deleteActivity` (check utilized, DELETE header; details CASCADE).
- `budjetoc/src/BudgetService.php` (~`filterRowsCreatedByDepartment`) — definición de “propia”.
- `budjetoc/public/budget/activity_view.php` — **añadir** Eliminar + form POST si puede borrar y sin OC.
- `budjetoc/public/budget/inc/dashboard_view.php` — **añadir** Eliminar en columna Acción.
- `budjetoc/public/budget/activity_edit.php` — patrón POST+CSRF+redirect.
- **Nuevo** `budjetoc/public/budget/activity_delete.php` — handler POST delete.
- `budjetoc/database/test_budget_activity_delete.php` — cubrir matriz (encargado OK/fail, SA/tenant_admin OK, OC block).
- No tocar: `Records2026ImportService`, `reset_operational_data.php`, páginas OC, stories 2–3.

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/src/UserPermissions.php` -- Añadir `canDeleteActivityHeader` (encargado: propia + permiso año; SA y tenant_admin: true) -- ownership solo para encargado.
- [x] `budjetoc/src/BudgetService.php` -- Añadir `deleteActivity`: tenant, `headerUtilized==0`, DELETE header -- único punto de mutación.
- [x] `budjetoc/public/budget/activity_delete.php` -- POST+CSRF, realm budget, permiso, service, redirect -- entrada HTTP.
- [x] `budjetoc/public/budget/activity_view.php` -- Eliminar si puede borrar y sin OC; confirmación simple -- UX detalle.
- [x] `budjetoc/public/budget/inc/dashboard_view.php` -- Acción Eliminar con mismos gates -- UX listado.
- [x] `budjetoc/database/test_budget_activity_delete.php` -- Cubrir I/O matrix -- regresión.

**Acceptance Criteria:**
- Given encargado con permiso de edición del año y actividad propia sin OC, when confirma Eliminar, then la actividad desaparece del listado y de la BD.
- Given encargado sin permiso de año o actividad ajena, when intenta delete, then se rechaza y el header permanece.
- Given super admin o tenant admin y actividad de cualquier unidad sin OC, when elimina, then el header se borra.
- Given cualquier actor autorizado y actividad con imputación OC, when intenta eliminar, then se rechaza con mensaje y no se borra nada.
- Given POST sin CSRF válido, when se envía delete, then 403 y sin mutación.

## Implementation Notes

- 2026-11-21: `canDeleteActivityHeader` + `canDeleteFlagsForHeaders`; `BudgetService::deleteActivity`; `activity_delete.php`; UI en `activity_view`/`dashboard_view`/`index`.
- Review patches: deleteActivity en TX + bloqueo si existe cualquier allocation; dashboard exige occupied_amount; tests handler/CSRF/flags/legacy/anulada; cleanup fixtures. CLI 44/44 PASS.
- `config.php` copiado al worktree solo para tests (gitignored).

## Spec Change Log

## Review Triage Log

- false — SPEC padre vs tenant_admin: el intent congelado de esta story (opción B) otorga delete a tenant_admin; el desalineamiento del SPEC padre no es defecto del código de esta story.
- false — UI epsilon 0.005 vs `headerUtilized > 0`: utilization se redondea a 2 decimales; el umbral 0.005 es equivalente a cero efectivo.
- false — `occupied_amount` null en dashboard: `enrichDashboardRows` siempre setea `occupied_amount`; el `|| null` no se alcanza en el listado normal.
- false — 403 texto plano: la matriz I/O admite 403; no es desviación.
- false — omitir `division_id` en ownership: alineado a assumptions (created_by / department_id).
- low (reject) — copy confirm / estilo botón peligro: cosmético; fix no trivial de design system.
- medium → patch — allocations de OC **anulada** siguen en BD; `headerUtilized` las ignora y DELETE choca FK RESTRICT → error genérico.
- medium → patch — TOCTOU entre check utilized y DELETE sin transacción/lock.
- medium → patch (verification-gap) — `activity_delete.php` nunca ejecutado en test (authz+mutación).
- medium → patch (verification-gap) — CSRF no ejercita el handler real.
- medium → patch (verification-gap) — `canDeleteFlagsForHeaders` sin asserts ejecutables.
- medium → patch (verification-gap) — fallback legacy `department_id` sin fixture.
- low (reject) — falta fixture coordinación: misma lógica type-agnostic; unlikely everyday bug.
- low → patch — fixtures de test pueden filtrar departments/suppliers.

## Design Notes

Gate distinto de `canEditActivityHeader`: encargado debe ser dueño (`created_by`); SA/tenant_admin no. Flujo: UI → `activity_delete.php` → `canDeleteActivityHeader` → `BudgetService::deleteActivity` (re-check utilized). Confirmación: `confirm()` o botón explícito, sin modal framework nuevo.

## Verification

**Commands:**
- `php budjetoc/database/test_budget_activity_delete.php` -- expected: todos los casos PASS (exit 0).

**Manual checks (if no CLI):**
- Encargado: propia OK; año cerrado / ajena / con OC fallan. SA o tenant admin: borran ajena con año “cerrado”.
