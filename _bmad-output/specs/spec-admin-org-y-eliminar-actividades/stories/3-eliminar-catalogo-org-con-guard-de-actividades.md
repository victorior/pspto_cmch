---
title: 'Eliminar catálogo org con guard de actividades'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '7ce00bad601db8d9afe0dd1dd3abce915204bb99'
context:
  - '{project-root}/_bmad-output/specs/spec-admin-org-y-eliminar-actividades/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-admin-org-y-eliminar-actividades/brownfield.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** El catálogo orgánico admin ya permite alta y rename, pero no hay forma de retirar CC/divisiones/coordinaciones; borrar a mano por tenant desalinearía el conjunto y, sin guard, SET NULL/RESTRICT dejan datos rotos o fallos de FK.

**Approach:** Super admin elimina CC, divisiones y coordinaciones desde el catálogo admin solo si no hay `budget_headers` asociadas en ningún tenant activo (CAP-2); el delete válido borra la entidad por nombre en todos los tenants activos (CAP-1 paridad).

## Boundaries & Constraints

**Always:**
- Actor: solo super admin; no `tenant_admin` ni encargados.
- Scope: `cost_centers`, `departments` type `estandar` / `coordinacion`.
- Guard CAP-2 (bloqueo duro): CC → headers con `cost_center_id` = id local del CC; división → `division_id` o `created_by` = id local; coordinación → `department_id` o `created_by` = id local. Evaluar en **todos** los tenants activos (si alguno tiene asociación → abort total).
- Paridad: tras delete válido, el nombre deja de existir en todos los tenants activos; match por `LOWER(TRIM(name))` (+ `type`).
- Desalineación (falta el nombre en algún tenant activo) → abort sin mutación (igual que rename 1A).
- Nombres receive-only / catalogReceive → bloquear delete (igual que rename 2B); mensaje que cite la dependencia.
- POST + CSRF; confirmación explícita en UI; IDs locales no se reasignan (se borran filas).
- Rama/worktree: `feature/admin-org-y-eliminar-actividades`.

**Never:**
- Soft-delete / archivado.
- Cascade que “ignore” headers asociados; no borrar headers desde este flujo.
- Heal/INSERT al borrar; no tocar portal OC, catálogo `activities`/`account_codes`, ni delete de actividades (story 1).
- Dar CRUD org a `tenant_admin`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Delete OK | Nombre en todos los tenants activos; sin headers asociados; no receive-only | DELETE en todos; nombre ausente en todos | N/A |
| Con actividades | Algún tenant activo tiene headers asociados (CAP-2) | Sin mutación | Mensaje: hay actividades; eliminarlas antes |
| Desalineación | Falta el nombre en algún tenant activo | Sin mutación | Error desalineación / sync |
| Receive-only | Nombre en receive-only / catalogReceive | Sin mutación | Mensaje dependencia |
| No–super-admin | Sesión sin SA | No alcanza mutación | Redirect/403 |
| CSRF inválido | POST sin `_csrf` válido | Sin mutación | 403 |

</frozen-after-approval>

## Code Map

- `budjetoc/src/CatalogService.php` — patrón `rename*AcrossTenants`; **añadir** `deleteCostCenterAcrossTenants` / `deleteDepartmentAcrossTenants` (TX, desalineación, receive-only, guard headers, DELETE).
- `budjetoc/src/BudgetActivityRules.php` — reutilizar `isReceiveOnlyOrCatalogReceiveName`.
- `budjetoc/database/schema.sql` — FKs: CC RESTRICT; dept SET NULL en BH; UDP/edit_policy CASCADE al borrar dept (efecto de esquema).
- `budjetoc/public/admin/tenant_catalog_cost_centers.php` / `divisions.php` / `coordinations.php` — **añadir** action delete + botón Eliminar (confirm).
- `budjetoc/public/css/app.css` — estilos mínimos si hace falta junto al form rename.
- **Nuevo** `budjetoc/database/test_catalog_org_delete.php` — matriz I/O (OK, con headers, desalineación, receive-only, CSRF/NSA).
- Reutilizar plantilla de `test_catalog_org_rename.php` / fixtures de `test_budget_activity_delete.php`.
- No tocar: `activity_delete.php`, OC, soft-delete.

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/src/CatalogService.php` -- Añadir delete multi-tenant CC/dept con guard CAP-2 en todos los tenants activos -- mutación única.
- [x] `budjetoc/public/admin/tenant_catalog_cost_centers.php` -- Action delete + UI Eliminar con confirm -- UX CC.
- [x] `budjetoc/public/admin/tenant_catalog_divisions.php` -- Action delete divisiones -- UX.
- [x] `budjetoc/public/admin/tenant_catalog_coordinations.php` -- Action delete coordinaciones -- UX.
- [x] `budjetoc/database/test_catalog_org_delete.php` -- Cubrir matriz I/O -- regresión.

**Acceptance Criteria:**
- Given SA y entidad sin headers asociados en ningún tenant activo, when elimina, then el nombre desaparece del catálogo de todos los tenants activos.
- Given headers asociados en algún tenant activo, when intenta delete, then se rechaza sin cambios y el mensaje indica actividades.
- Given desalineación o nombre receive-only, when intenta delete, then se rechaza sin mutación.
- Given no–SA o CSRF inválido, when POST delete, then sin mutación (403 / gate admin).

## Implementation Notes

## Spec Change Log

## Review Triage Log

| verdict | finding | evidence |
|---------|---------|----------|
| medium | confirm() embebe nombre con `h()` en string JS (CC/div/coord) | Verificado: `h()` no escapa `'`; un nombre con comilla rompe/inyecta el `onsubmit`. |
| medium | CAP-2 `created_by` no cubierto de forma independiente en tests | Verificado: fixtures pasan el mismo id en `division_id`/`department_id` y `created_by`; quitar `OR created_by` seguiría verde. |
| medium | CAP-2 en tenant activo no-work sin test | Verificado: headers CAP-2 solo en `$workTenant`; el loop del servicio sí cubre todos, pero la regresión no lo prueba. |
| medium | DELETE por `id` + `LIMIT 1` deja hermanos duplicados con el mismo nombre | Verificado: no hay UNIQUE(name); rename actualiza por `LOWER(TRIM(name))`; delete solo borra un id — AC exige que el nombre desaparezca. |
| maybe-false | TOCTOU: header insert entre check CAP-2 y DELETE dept (SET NULL) | Sin evidencia de carrera en el diff; CC tiene RESTRICT. Haría falta prueba concurrente / `FOR UPDATE` para asentarlo. |
| false | CAP-2 división debe incluir `department_id` (sub-área) | Intent CAP-2: división → `division_id` o `created_by` solamente; `department_id` no aplica a ese guard. |
| false | Receive-only omitido para type `estandar` | Mismo helper `isReceiveOnlyOrCatalogReceiveName`; rama compartida ya ejercida en CC y coord. |
| false | Tenants inactivos quedan con el nombre tras delete | Intent/Always: solo tenants activos; paridad alineada con rename. |
| false | Tasks no listan `app.css` | Code Map lo marca opcional (“si hace falta”); no es defecto de comportamiento. |
| false | Secciones Implementation Notes / Spec Change Log vacías = defecto | Process/docs; el triage se completa aquí; no implica fallo de producto. |
| low | CSRF/NSA no repetidos por cada entidad HTTP | Gate compartido; CSRF en CC+div y NSA en CC cubren el patrón; rechazo — poco probable en uso diario. |
| low | Manual checks solo mencionan CC | Doc de verificación; no defect runtime. |
| low | Falta aria-label / affordance destructiva en Eliminar | Cosmético a11y; fix no trivial vs. impacto diario SA. |
| low | Strings sin tilde «Coordinacion» vs confirm con tilde | Inconsistencia cosmética; patrón ya usado en mensajes not-found del admin. |
| low | Nombres solo-whitespace en BD no borrables | Insert vía UI/`trim` no crea ese caso en flujo normal; rechazo. |
| low | Throwable no capturado → 500 | CAP-2 + RESTRICT/SET NULL cubren FKs de BH; rename tampoco envuelve try/catch de usuario. |
| low | Probes `_tmp_catalog_delete_*` race/leftover | Patrón de harness; cleanup con `@unlink` ya presente; poco impacto diario. |

## Design Notes

Reutilizar el esqueleto de rename: preflight existencia en todos → gates → mutación. Guard: resolver id local por nombre(+type) en cada tenant y `EXISTS` sobre `budget_headers` según CAP-2. UI: botón Eliminar con `confirm()` junto al rename; no modal nuevo. Al borrar dept, CASCADE de UDP/edit_policy es comportamiento de esquema (no soft-delete de permisos).

## Verification

**Commands:**
- `php budjetoc/database/test_catalog_org_delete.php` -- expected: todos PASS (exit 0).

**Manual checks (if no CLI):**
- SA: borrar CC sin actividades y ver ausencia en otro tenant; con actividades / receive-only fallan.
