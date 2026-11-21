# Brownfield — admin org CRUD y eliminar actividades

## Hoy (antes de este Spec)

| Tema | Comportamiento actual |
|------|------------------------|
| Catálogo CC / divisiones / coordinaciones | Páginas admin `tenant_catalog_cost_centers.php`, `tenant_catalog_divisions.php`, `tenant_catalog_coordinations.php`: **solo alta** + lista. Sin editar ni eliminar en UI. |
| Gate catálogo | `tenant_catalog_common.php` → `AdminAccess::requireSuperadminSession`. |
| Propagación | `CatalogService::propagate*` en INSERT: “solo INSERT, no borra ni actualiza” al replicar entre tenants. |
| Modelo org | `departments.type`: `estandar` = División; `coordinacion` = Coordinación. `cost_centers` = catálogo contable plano (dual vs departments). |
| Actividad Presupuesto | `budget_headers` + `budget_details`. Catálogo de nombres `activities` es otra entidad (fuera de este Spec salvo FKs). |
| FKs relevantes | `cost_center_id` → `cost_centers` **ON DELETE RESTRICT**; `division_id` / `department_id` / `created_by` → `departments` **ON DELETE SET NULL**; detalles CASCADE al borrar cabecera; allocations OC **RESTRICT** sobre líneas. |
| Delete actividad | **No hay** flujo UI/API de `DELETE budget_headers`. Solo quitar líneas en save; bloqueo si hay imputación OC. |
| Permiso edición año | Tabla `tenant_department_edit_policy`; runtime `UserPermissions::canEditActivityHeader` / `canEditActivitiesForDepartment` (defaults: pasado 0, año actual 1, futuro 0). |
| “Propia” actividad | `created_by` = `departments.id` creador; fallback legacy `department_id`. Usado en export (`filterRowsCreatedByDepartment`); edit actual no exige ownership estricto más allá de policy dept ∈ allowed. |
| Roles | Super admin: `super_admins`. Encargado: `user_tenant_roles` + `user_department_permissions`. Tenant admin: edita políticas/usuarios, no el catálogo maestro. |

## Decisiones de este Spec (resumen)

| Tema | Decisión |
|------|----------|
| Actor CRUD org | Solo super admin, en panel admin. |
| Paridad tenants | Todos los tenants tienen las mismas atribuciones; edit/delete/alta de catálogo org deben mantenerse alineados. |
| Delete entidad | Bloqueado si hay `budget_headers` asociadas; hay que borrar esas actividades antes. |
| Delete actividad encargado | Solo propias, con permiso de edición del año; bloqueado si hay imputaciones OC. |
| Delete actividad SA | Sin restricciones de ownership ni permiso de año; único bloqueo: imputaciones OC. |
| Rama | `feature/admin-org-y-eliminar-actividades`. |

## Puntos de código relevantes

- `budjetoc/public/admin/tenant_catalog_*.php`, `inc/tenant_catalog_common.php`
- `budjetoc/src/CatalogService.php`, `CostCenterCatalog.php`, `DepartmentCatalog.php`
- `budjetoc/src/BudgetService.php`, `BudgetActivityRules.php`, `UserPermissions.php`, `EditPolicyService.php`
- `budjetoc/public/budget/activity_view.php`, `activity_edit.php`, `inc/dashboard_view.php`
- `budjetoc/database/schema.sql` — FKs `budget_headers`
- Adoptado: `../../planning-artifacts/architecture-spine-budjetoc.md`
