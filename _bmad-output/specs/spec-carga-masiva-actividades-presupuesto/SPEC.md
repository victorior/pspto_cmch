---
id: SPEC-carga-masiva-actividades-presupuesto
companions:
  - brownfield.md
  - plantilla-import.md
  - delivery.md
  - ../../planning-artifacts/architecture-spine-budjetoc.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Carga masiva de actividades de Presupuesto (super admin)

## Why

**Pain to solve:** hoy cada actividad de Presupuesto se crea a mano en el portal (`activity_new.php`) por encargados de División o Coordinación. En tenants nuevos o al poblar un año, eso es lento y frágil. El super admin necesita, por tenant, subir un CSV basado en plantilla que cree las actividades (cabecera + detalle) en el módulo Presupuesto, sin depender de la carga manual de esos encargados. El sprint de OC formato tributario ya cerró; esto es feature nueva en worktree aparte.

## Capabilities

- **CAP-1**
  - **intent:** El super admin abre, desde el panel admin y en el contexto de un tenant, una acción de carga masiva de actividades de Presupuesto.
  - **success:** Solo un usuario con sesión super admin ve y ejecuta la acción; al elegir un tenant, la carga y las lecturas de catálogo quedan acotadas a ese `tenant_id`.

- **CAP-2**
  - **intent:** El super admin descarga, desde un link visible en esa pantalla, la plantilla CSV a completar.
  - **success:** El link entrega un CSV que abre en Excel con tildes y eñes intactas (UTF-8 con BOM); encabezados y ejemplos acordes a `plantilla-import.md`; un archivo válido relleno a partir de ella pasa la validación (CAP-4) y luego la carga (CAP-3).

- **CAP-4** *(paso 3 del flujo: validar antes de cargar)*
  - **intent:** Antes de confirmar la carga, el sistema valida el CSV y muestra un informe compacto (no el listado completo de filas OK).
  - **success:** El informe indica cuántos registros se subirían si no hay errores; lista solo los records con error (incluidos ids/`activity_number` ya presentes en la BD del tenant) para que el super admin corrija el archivo; con errores, no se confirma la carga; sin errores, se habilita la subida.

- **CAP-3** *(paso 4 del flujo: confirmar altas)*
  - **intent:** Tras una validación sin errores, el super admin confirma la subida; el sistema crea solo actividades nuevas, cada una atribuida a la División o Coordinación indicada en el CSV.
  - **success:** Cada `budget_headers` creado queda ligado a la unidad de la plantilla (`created_by` + `division_id` / `department_id` / `cost_center_id` según tipo) y detalle asociado; no se actualizan existentes; no se escriben datos de otro tenant; el operador de la acción es siempre el super admin.

## Constraints

- Actor exclusivo: super admin (`super_admins` / `Auth::isSuperadmin`); no encargados ni `tenant_admin`. El “creador” de la operación de carga es siempre el super admin; la atribución orgánica División/Coordinación va en el CSV (ver abajo).
- Archivo: **CSV** editable en Excel; encoding **UTF-8 con BOM** (o equivalente verificado) para no desconfigurar tildes ni eñes. Separador y columnas en `plantilla-import.md`.
- Pantalla admin con **link permanente de descarga de plantilla** (CAP-2).
- Validación previa obligatoria (CAP-4): conteo de registros a subir + solo filas/grupos con error; **no** renderizar tabla con todos los records válidos.
- Solo altas: si un id/`activity_number` ya existe en el tenant, es **error de validación** (el SA corrige el CSV); no upsert ni overwrite.
- Atribución orgánica obligatoria por actividad/grupo en el CSV (`creator_type` + `creator_name` → `budget_headers.created_by` y campos derivados). Sin unidad válida, error en CAP-4. El user_id del super admin no reemplaza esa FK (`departments.id`).
- Creación alineada a `BudgetActivityRules` / `BudgetService::createActivity`. Detalle en `brownfield.md`.
- Scope multi-tenant estricto por `tenant_id` elegido.
- Superficie UI en panel admin; el botón no vive en el portal Presupuesto de encargados.
- DocumentRoot solo `public/`; secretos fuera del árbol web y del zip. Entrega en `delivery.md`.
- Respetar architecture spine (modular monolith admin / budget / OC).

## Non-goals

- Dar carga masiva a encargados de División/Coordinación o a `tenant_admin`.
- Formato `.xlsx` nativo / nueva dependencia PhpSpreadsheet.
- Sustituir o reescribir el CLI legado `Records2026ImportService` (puede inspirar, no es el producto).
- Carga masiva de OC, usuarios, o catálogos genéricos más allá de resolver FKs de la actividad.
- Edición o borrado masivo de actividades existentes.
- Mostrar en la UI de validación el listado completo de filas correctas.
- Rediseñar el dashboard o el flujo `activity_new.php` de encargados.

## Success signal

Un super admin descarga la plantilla CSV (tildes/ñ OK en Excel), la rellena con actividades de División y Coordinación, valida y ve solo el conteo a subir más los errores (si los hay, p. ej. número ya en BD); corrige el archivo, valida limpio, confirma la carga y las actividades nuevas aparecen en Presupuesto bajo la unidad indicada — sin encargado en `activity_new.php`.

## Assumptions

- “Actividades” = `budget_headers` (+ `budget_details`), no solo catálogo `activities`.
- “Creador = super admin” = operador de la feature; la unidad orgánica sigue siendo columna obligatoria del CSV porque `created_by` en BD es `departments.id`.
- Separador CSV `;` (alineado a export/import legado), salvo que la plantilla documente otro y se pruebe con Excel regional.
