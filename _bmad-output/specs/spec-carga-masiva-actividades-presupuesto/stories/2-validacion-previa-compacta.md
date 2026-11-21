---
title: 'Validación previa compacta'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'ec4ea6c5710fbd48a572352b50526f7404070787'
context:
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/plantilla-import.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/brownfield.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/1-pantalla-admin-plantilla-csv.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** El super admin puede descargar la plantilla (historia 1) pero aún no puede validar un CSV relleno antes de cargar: sin informe de errores/duplicados, no puede corregir el archivo con confianza (CAP-4).

**Approach:** En la misma pantalla admin, permitir subir el CSV del tenant, validarlo sin insertar, y mostrar solo el conteo de grupos que se subirían más la lista de records con error. Con errores no se habilita confirmación de carga; la alta real queda para historia 3.

## Boundaries & Constraints

**Always:** Solo super admin; `tenant_id` de la pantalla; UTF-8 (strip BOM); separador `;`; encabezados = `templateHeaders()`; `would_upload` = grupos por `num`(+year → `activity_number`); duplicado en `budget_headers` = error; atribución División/Coordinación validada con catálogos + `BudgetActivityRules` sin INSERT.

**Never:** `BudgetService::createActivity` / INSERT; tabla de filas OK; skip silencioso de duplicados; `.xlsx`; abrir a `tenant_admin`/encargados; reescribir `AdminUserCsvImportService` o CLI Records2026.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| CSV limpio | Plantilla con 2 grupos nuevos, catálogos OK | `ok=true`, `would_upload=2`, lista errores vacía; mensaje “validación OK”; sin botón de alta aún (historia 3) | N/A |
| Duplicado | `activity_number` ya en tenant | Error listado con grupo/número; `ok=false`; no confirmar | Mensaje claro “ya existe” |
| Unidad inválida | `creator_name`/`creator_type` o CC/división mal | Error por grupo; `ok=false` | Motivo legible |
| Parseo/encoding | BOM, `;`, tilde/ñ | Parse correcto; nombres con tilde no generan error de encoding | Si header distinto → error de plantilla |
| Sin archivo / vacío | POST sin CSV o solo headers | `ok=false`, error | No crash |

</frozen-after-approval>

## Code Map

- `budjetoc/src/AdminBudgetActivityCsvImportService.php` — extender: parse CSV, agrupar por num/year, `validateUploadedCsv(PDO, tenantId, path|string)` → `{ok, would_upload, errors[]}`; reusar `UTF8_BOM`/`templateHeaders`.
- `budjetoc/public/admin/budget_activities_import.php` — form multipart + CSRF; POST valida; UI: conteo + solo errores; sin tabla OK; sin enable create.
- `budjetoc/src/AdminUserCsvImportService.php` — patrón parse/errores/`utf8_file_contents` (no modificar).
- `budjetoc/src/helpers.php` — `utf8_file_contents`, `utf8_normalize`, `activity_num_short` / año.
- `budjetoc/src/BudgetActivityRules.php` — `validateAndNormalizeHeader`, `creatorCoordinationMayCreateBudget`, `errorMessage` (solo validar).
- `DepartmentCatalog` / `CostCenterCatalog` / `CatalogService` / maps estilo `Records2026ImportService::loadCatalogMaps` — resolver nombres/códigos del tenant.
- `budget_headers` — `SELECT … tenant_id + activity_number` para duplicados.
- **No tocar:** `BudgetService::createActivity`, portal `budget/`, import usuarios.

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/src/AdminBudgetActivityCsvImportService.php` -- Añadir parse + `validateUploadedCsv` (grupos, reglas, duplicados, detalle cuenta/qty/precio) -- núcleo CAP-4 sin INSERT.
- [x] `budjetoc/public/admin/budget_activities_import.php` -- Formulario subir CSV, llamar validación, mostrar `would_upload` + solo errores; CSRF + superadmin -- UI compacta.
- [x] `budjetoc/database/` o script PHP de smoke local -- Caso limpio + duplicado + unidad inválida contra servicio -- cobertura I/O matrix (CLI preferible si hay patrón en repo).

**Acceptance Criteria:**
- Given super admin en tenant T con CSV limpio, when valida, then ve conteo de grupos a subir y cero errores, sin listado de filas OK y sin inserts.
- Given CSV con `activity_number` existente en T, when valida, then ese grupo aparece en errores y no se ofrece confirmación de carga.
- Given CSV con División/Coordinación o CC inválidos, when valida, then errores por grupo con motivo y `ok=false`.
- Given no-superadmin, when abre/POSTea la página, then se deniega como en historia 1.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Evidence / route |
|---------|---------|------------------|
| BH: `year` vacío → año actual silencioso | medium | Confirmado en `groupRows`/`composeActivityNumber` (`year > 0 ? … : date('Y')`). → patch |
| BH: cabecera multi-fila solo usa 1ª fila | medium | Confirmado: `$lead = $group['lines'][0]['cells']` sin comparar filas siguientes. → patch |
| BH: `division_name` inválido (creator=division) se ignora | medium | Confirmado líneas ~422–431: si no es hijo válido, no hay error. → patch |
| BH: `would_upload` > 0 con `ok=false` | false | Flash dice “No se habilita la carga”; etiqueta “sin errores de grupo” es coherente con Design Notes. |
| BH: smoke no cubre `validateFromUploadedFile`/$_FILES | medium | Misma brecha que VG-upload; CLI solo strings. → patch |
| BH: Implementation Notes / Change Log / Triage vacíos | false | Secciones de workflow; rellenar notas no es defecto de producto; triage se escribe aquí. |
| BH: early-return creator salta detalle/duplicado | low | Confirmado returns ~367–397; rechazado: uso cotidiano corrige creator y revalida; acumular añade complejidad. |
| BH: bootstrap `!$known` amplía carga CSV admin | false | Mismo patrón que `AdminUserCsvImportService`; CLI/smoke necesita el require. |
| BH: `!isset($_FILES['csv_file'])` casi muerto | low | Rechazado: cosmético; servicio ya cubre `UPLOAD_ERR_NO_FILE`. |
| BH: qty/unit_price = 0 pasan | false | Spec no exige >0; solo no-negativo; historia 3 no es criterio de esta. |
| BH: smoke “2 grupos” degrada a 1 sin coordinación | low | Rechazado: path coordinación existe; fallo de catálogo local no invalida el código. |
| BH: string path sin Windows-1252 de `utf8_file_contents` | low | Rechazado: UI usa rama archivo; asimetría CLI irrelevante al usuario. |
| EC: year blank/non-numeric → año actual | medium | Mismo defecto que BH-year. → patch (agrupado) |
| EC: fila con datos pero num+year vacíos se descarta | medium | Confirmado `groupRows` continue sin error tras filas no vacías en parse. → patch |
| EC: POST tenant no gestionable → fallback | false | Página exige superadmin; `canManageTenant` siempre true para superadmin — remap no aplica. |
| EC: string >2MB sin límite | low | Rechazado: UI pasa por archivo con `MAX_BYTES`; string es CLI/tests. |
| EC: fwrite php://memory falla | low | Rechazado: fallo raro de runtime; no cotidiano. |
| EC: num ya `N-YYYY` vs columna year distinta | medium | Confirmado: regex short-circuit en `composeActivityNumber` ignora year. → patch |
| VG: POST/UI `validate_csv` sin smoke HTTP | medium | Pre-verificado; patrón repo = CLI + UI manual. → defer |
| VG: upload/file-path sin test | medium | Pre-verificado. → patch |
| VG: `would_upload` no fijado con ≥2 líneas/grupo | medium | Pre-verificado. → patch |
| VG: asserts encoding débiles (miran source sample) | medium | Pre-verificado. → patch |
| VG-other: tenant POST remap | false | Igual que EC-tenant; superadmin. |

## Design Notes

- `would_upload` cuenta **grupos** (mismo `num`+`year`), no cada línea de detalle.
- Historia 3 re-subirá el archivo limpio para confirmar; esta historia no persiste el CSV en sesión.
- Errores: preferir `{line?, group, activity_number?, message}` y renderizar solo eso.

## Verification

**Commands:**
- `php -l budjetoc/src/AdminBudgetActivityCsvImportService.php` -- sin errores de sintaxis
- `php -l budjetoc/public/admin/budget_activities_import.php` -- sin errores de sintaxis
- Smoke CLI o manual: validar plantilla de ejemplo (espera errores de catálogo genérico) + CSV con número existente (error duplicado)

**Manual checks:**
- UI: no tabla de OK; con errores no hay CTA de confirmar alta; tildes/ñ en nombres no rompen el parseo.
