---
title: 'Editar catálogo org con paridad multi-tenant'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '1cb179e5753f1b5a0c52265e88615a5ff36ba845'
context:
  - '{project-root}/_bmad-output/specs/spec-admin-org-y-eliminar-actividades/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-admin-org-y-eliminar-actividades/brownfield.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** El catálogo orgánico admin (centros de costo, divisiones, coordinaciones) solo permite altas; no hay forma de corregir/renombrar entidades, y el propagate actual es solo INSERT, así que un rename manual en un tenant desalinearía el resto.

**Approach:** Permitir al super admin editar/renombrar CC, divisiones y coordinaciones desde las páginas de catálogo admin, propagando el cambio de nombre a todos los tenants activos y dejando IDs locales intactos (paridad por nombre, CAP-1).

## Boundaries & Constraints

**Always:**
- Actor: solo super admin (`AdminAccess::requireSuperadminSession`); no `tenant_admin` ni encargados.
- Entidades en scope: `cost_centers`, `departments` type `estandar` (divisiones), `departments` type `coordinacion`.
- Paridad: tras un rename válido, el mismo nombre nuevo existe en todos los tenants activos (misma regla de conjunto que el alta).
- Si algún tenant activo no tiene la entidad con el nombre viejo → abortar todo el rename (error de desalineación; exige sync previo). Sin heal/INSERT.
- Bloquear rename si el nombre viejo está en `budget_coord_receive_only_names` o `catalogReceiveCostCenterNames` (match case/trim-insensitive); mensaje que cite esa dependencia. Sin mutar config.
- Match entre tenants por `LOWER(TRIM(name))` (+ `type` en departments); IDs locales no se reasignan ni se tocan FKs.
- POST + CSRF; UI en las tres páginas de catálogo org existentes.
- Rama/worktree: `feature/admin-org-y-eliminar-actividades`.

**Never:**
- Delete de CC/división/coordinación (story 3).
- Editar catálogo `activities` o `account_codes` en esta story.
- Soft-delete, cascade de FKs, o fusionar IDs entre tenants.
- Sanar desalineación insertando el nombre nuevo donde falte el viejo.
- Tocar portal OC, delete de actividades (story 1 ya mergeada), ni modelo dual CC↔departments.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Rename OK | Nombre viejo presente en todos los tenants activos; nuevo distinto y libre; no receive-only | UPDATE name en todos; IDs locales iguales | N/A |
| Desalineación | Algún tenant activo sin el nombre viejo | Sin mutación (rollback) | Error desalineación / sync |
| Receive-only | Nombre viejo en receive-only / catalogReceive | Sin mutación | Mensaje dependencia receive-only |
| Nuevo vacío / solo espacios | `new` trim vacío | Sin mutación | Mensaje nombre requerido |
| Nuevo ya existe | Otro registro en algún tenant activo con mismo `LOWER(TRIM(name))` (+ type) | Sin mutación (rollback) | Mensaje conflicto |
| Mismo nombre (noop) | `new` equivale a `old` (case/trim-insensitive) | Sin cambio o mensaje “sin cambios”; BD intacta | N/A / info |
| No–super-admin | Sesión sin SA | No alcanza mutación | Redirect/403 como hoy |
| CSRF inválido | POST sin `_csrf` válido | Sin mutación | 403 |

</frozen-after-approval>

## Code Map

- `budjetoc/src/CatalogService.php` — `propagateCostCenter` / `propagateDepartment` / `*Exists` / `insert*IfMissing` (solo INSERT hoy); **añadir** `renameCostCenterAcrossTenants` / `renameDepartmentAcrossTenants` (transacción, abort si falta old name, UPDATE por nombre en tenants activos).
- `budjetoc/src/BudgetActivityRules.php` — `catalogReceiveCostCenterNames` / receive-only set; **reutilizar** para bloquear rename (sin cambiar la lista).
- `budjetoc/public/admin/tenant_catalog_cost_centers.php` — alta `add_cc` + lista; **añadir** action rename + UI por fila.
- `budjetoc/public/admin/tenant_catalog_divisions.php` — alta `add_dept` type `estandar`; **añadir** rename.
- `budjetoc/public/admin/tenant_catalog_coordinations.php` — alta type `coordinacion`; **añadir** rename.
- `budjetoc/public/admin/inc/tenant_catalog_common.php` — bootstrap SA + CSRF helpers; reutilizar, no cambiar gate.
- `budjetoc/src/CostCenterCatalog.php` / `DepartmentCatalog.php` — solo lectura/cache; no writes.
- **Nuevo** `budjetoc/database/test_catalog_org_rename.php` — cubrir I/O matrix multi-tenant (OK, conflicto, desalineación, receive-only).
- No tocar: `activity_delete.php`, OC, story 3 delete, `replicateFromTenant` semantics salvo reutilizar helpers.

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/src/CatalogService.php` -- Añadir rename multi-tenant CC y department (transacción, abort desalineación, exists checks, UPDATE por nombre) -- único punto de mutación de paridad.
- [x] `budjetoc/src/BudgetActivityRules.php` (o helper desde CatalogService) -- Exponer/consultar si un nombre es receive-only/catalogReceive para gate de rename -- bloqueo 2B.
- [x] `budjetoc/public/admin/tenant_catalog_cost_centers.php` -- Action rename + formulario/inline por ítem -- UX CC.
- [x] `budjetoc/public/admin/tenant_catalog_divisions.php` -- Action rename divisiones -- UX divisiones.
- [x] `budjetoc/public/admin/tenant_catalog_coordinations.php` -- Action rename coordinaciones -- UX coordinaciones.
- [x] `budjetoc/database/test_catalog_org_rename.php` -- Cubrir matriz I/O (OK, conflicto, vacío, noop, desalineación, receive-only) -- regresión.

**Acceptance Criteria:**
- Given super admin y nombre presente en todos los tenants activos con nuevo nombre libre y no receive-only, when renombra CC/división/coordinación, then el nombre nuevo aparece en el catálogo de todos los tenants activos y los IDs locales no cambian.
- Given algún tenant activo sin el nombre viejo, when intenta rename, then se aborta sin cambios y se indica desalineación.
- Given nombre viejo receive-only / catalogReceive, when intenta rename, then se rechaza citando esa dependencia y no muta.
- Given nuevo nombre ya usado (case-insensitive) en algún tenant activo, when intenta rename, then se rechaza y no cambia ningún tenant.
- Given actor que no es super admin, when intenta la mutación, then no se aplica.
- Given POST sin CSRF válido, when se envía rename, then 403 y sin mutación.

## Implementation Notes

- Implementado en worktree `pspto_cmch-admin-org-y-eliminar-actividades`: `CatalogService::renameCostCenterAcrossTenants` / `renameDepartmentAcrossTenants`; gate `BudgetActivityRules::isReceiveOnlyOrCatalogReceiveName`; UI rename en tres páginas admin; CSS `.catalog-rename-form`; test CLI 66 ok / 0 fail (post-patches de review).
- Baseline: `1cb179e5753f1b5a0c52265e88615a5ff36ba845`.

## Spec Change Log

## Review Triage Log

- `medium` — Probe HTTP deja `$httpCode=200` en shutdown tras `exit` de CSRF; assertion pasa por fallback de texto. Evidencia: `require_post_csrf` hace `http_response_code(403); exit` y el shutdown no relee el código. → patch
- `false` — Asserción no–SA “por texto” débil: el test también exige ausencia de mutación en BD (`findCostCenterByName` null), que sí demuestra el bloqueo.
- `medium` — Matriz de rechazo de `renameDepartmentAcrossTenants` solo happy-path. Confirmado en test lines ~270–336. → patch (VG)
- `medium` — Sin HTTP success path SA+CSRF para rename_cc. Confirmado: solo CSRF/NSA vía handler. → patch (VG)
- `low` — Formulario rename visible en filas receive-only; rechazo en POST cumple 2B. Rechazado: UX polish con más que un fix directo mínimo.
- `low` — Sin copy de blast radius multi-tenant. Rechazado: cosmético / no defect funcional.
- `false` — Tenants inactivos conservan nombre viejo: el intent/spec limita paridad a tenants activos.
- `low` — Paths fijos del probe en corridas paralelas. Rechazado: uso diario CLI serial; fix añade complejidad.
- `maybe-false` — `mb_strtolower` vs MySQL `LOWER` en noop. Sin caso concreto en utf8mb4_unicode_ci; no se demuestra divergencia.
- `low` — Cleanup omite algunos nombres de intentos fallidos. Rechazado: harness, no producto.
- `false` — Rename CC no renombra coordinación homónima: modelo dual intencional (SPEC non-goals).
- `false` — Rename *hacia* nombre receive-only: si el label ya existe en BD, `*Exists(new)` aborta por conflicto; los labels de config existen en catálogo alineado.
- `defer` — Race check-then-UPDATE concurrente. Preexistente en patrón catalog; SA único hace el escenario raro; fix con locks no es trivial.
- `defer` — Throwable re-lanzado tras rollback: mismo patrón que otros servicios; UI no captura Throwable hoy.
- `low` — `newName` > 255. Columna varchar(255); improbable desde UI admin. Rechazado.
- `medium` — Probe reporta HTTP 200 en exits reales (mismo root que finding 1). → patch
- `medium` — VG: department rejection matrix untested. → patch
- `medium` — VG: HTTP rename success never observed. → patch
- `medium` — VG: divisions/coordinations HTTP handlers unprobed. → patch

## Design Notes

Identidad de paridad = nombre (no id). Flujo: UI admin en tenant de trabajo → lee `id` local solo para mostrar/origen del old name → `CatalogService::rename*` recibe old/new (+ type) y actualiza por nombre en cada tenant activo. UI mínima: input+botón “Renombrar” por fila junto al nombre actual, mismo estilo que el form de alta. No modal framework nuevo.

## Verification

**Commands:**
- `php budjetoc/database/test_catalog_org_rename.php` -- expected: todos los casos PASS (exit 0).

**Manual checks (if no CLI):**
- SA: renombrar un CC y verificar el mismo nombre en otro tenant; conflicto y CSRF fallan; no–SA no ve/alcanza.
