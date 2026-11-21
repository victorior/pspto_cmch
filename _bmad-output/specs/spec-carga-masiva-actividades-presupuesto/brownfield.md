# Brownfield — carga masiva actividades Presupuesto

## Hoy (antes de este Spec)

| Tema | Comportamiento actual |
|------|------------------------|
| Creación UI | `budjetoc/public/budget/activity_new.php` → `BudgetService::createActivity`; portal Presupuesto, roles `encargado` / `tenant_admin`. |
| Quién crea | Unidad creadora = `departments`: `estandar` (División) o `coordinacion`. Encargados limitados por `user_department_permissions`. |
| Reglas | `BudgetActivityRules`: División exige `cost_center_id`; Coordinación exige `division_id` destino y deja `cost_center_id` NULL; coordinaciones solo-recepción no crean. |
| Modelo datos | `budget_headers` (actividad) + `budget_details` (líneas cuenta/qty/precio); catálogo nombres `activities`; numeración `activity_sequences` → `N-YYYY`. |
| Catálogo dual | `departments` (org/permisos) vs `cost_centers` (CC contables, sin FK a departments). |
| Import presupuesto | Solo CLI: `Records2026ImportService` + `database/import_records2026.php` (CSV `;`, agrupa por `Num`, skip si existe). Sin UI. |
| Export | `BudgetExportService` / `export_activities.php` — descarga, no import. |
| Carga masiva UI admin | Solo usuarios: `AdminUserCsvImportService` + `admin/users.php` (CSV + sample). |
| Super admin | Tabla `super_admins`; `Auth::isSuperadmin()`; panel `public/admin/` (`AdminAccess::requireSuperadmin*`). |
| Catálogo nombres admin | `tenant_catalog_activities.php` propaga nombres `activities` — **no** crea `budget_headers`. |

## Decisiones resueltas (este Spec)

| Tema | Decisión |
|------|----------|
| Actor / operador | Siempre super admin (quien ejecuta la carga). |
| Formato | CSV; UTF-8 con BOM (tildes/ñ); link de descarga de plantilla en admin. |
| Objeto | `budget_headers` + `budget_details` (instancias Presupuesto). |
| Scope | Un tenant por operación. |
| Solo altas | Ids/`activity_number` ya en BD → error de validación; SA corrige el CSV (no upsert). |
| Preview | Obligatoria: conteo a subir + solo records con error; sin tabla de todos los OK. |
| UI | Panel admin + link plantilla; no portal de encargados. |
| Atribución orgánica | Obligatoria en CSV: División/Coordinación → `created_by` (`departments.id`) + reglas CC/división. Distinto del user del super admin. |

## Implicación de diseño

La carga no puede ser un INSERT crudo: debe resolver FKs de catálogo del tenant y pasar por las mismas invariantes que `createActivity` / `BudgetActivityRules`. El CLI Records2026 es precedente de agrupación cabecera/detalle y skip, no el producto final.

## Puntos de código relevantes

- `budjetoc/src/BudgetService.php` — `createActivity`, numeración, columnas
- `budjetoc/src/BudgetActivityRules.php` — reglas División/Coordinación/recepción
- `budjetoc/src/Records2026ImportService.php` — precedente import CSV presupuesto
- `budjetoc/src/AdminUserCsvImportService.php` — precedente UI admin + sample
- `budjetoc/src/AdminAccess.php`, `Auth.php` — gate super admin
- `budjetoc/public/budget/activity_new.php` — contrato de campos UI
- `budjetoc/public/admin/users.php` — patrón UX carga masiva
- `budjetoc/database/schema.sql` — `budget_headers`, `budget_details`, `activities`, `super_admins`
