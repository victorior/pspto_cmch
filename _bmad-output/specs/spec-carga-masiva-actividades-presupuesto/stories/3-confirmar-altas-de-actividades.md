---
title: 'Confirmar altas de actividades'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '1583067d412f96a6c1d2d563045ba3c41da27da3'
context:
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/plantilla-import.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/brownfield.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/2-validacion-previa-compacta.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Tras CAP-4 el super admin ve que el CSV está limpio, pero aún no puede confirmar la carga: no se crean `budget_headers`/`budget_details` con la atribución División/Coordinación del archivo (CAP-3).

**Approach:** En la misma pantalla, tras re-subir el CSV limpio, confirmar altas: re-validar, y si `ok`, insertar solo actividades nuevas con el `activity_number` del CSV y la unidad orgánica resuelta; sin upsert ni persistir el archivo en sesión.

## Boundaries & Constraints

**Always:** Solo super admin; `tenant_id` de la pantalla; re-validar en el mismo POST de confirmación (misma lógica CAP-4); solo INSERT de grupos OK; `activity_number` del CSV se conserva; `created_by` = unidad del CSV (`departments.id`), no el user del SA; pasar por `BudgetActivityRules` + semántica de `BudgetService::createActivity` (detalle + columnas); transacción por grupo; sincronizar `activity_sequences` tras números fijos; CSRF + multipart.

**Never:** Upsert/overwrite; INSERT con validación fallida; CSV en sesión/tmp durable; numeración auto que ignore el CSV; abrir a `tenant_admin`/encargados; reescribir `AdminUserCsvImportService` o CLI Records2026; migraciones de esquema; portal `budget/activity_new.php`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Confirm limpio | CSV 2 grupos nuevos (división + coordinación), catálogos OK | `ok` validate; N inserts; UI resumen creados; filas en BD con `activity_number` y `created_by` del CSV | N/A |
| Confirm con error | Mismo CSV pero un `activity_number` ya en BD (o unidad inválida) | Re-validate `ok=false`; **cero** INSERT; lista errores como CAP-4 | Mensaje; no parcial |
| Re-upload omitido | POST confirm sin archivo | Error; sin INSERT | Flash claro |
| Fallo mid-batch | Grupo 1 OK, grupo 2 falla inesperado en INSERT | Grupo 1 queda; grupo 2 reportado; no corrompe txn del 1 | Error por grupo; conteo parcial |

</frozen-after-approval>

## Code Map

- `budjetoc/src/AdminBudgetActivityCsvImportService.php` — añadir `importFromUploadedFile` / `importUploadedCsv`: llamar validate; si `!ok` devolver sin INSERT; si ok, por grupo construir header/details (extraer resolución hoy privada en `validateGroup` ~341–530), INSERT, sync secuencia; retorno `{ok, created, errors[]}`.
- `budjetoc/src/BudgetService.php` — `createActivity` (~936) hoy llama `nextActivityNumber` y **no** acepta número CSV. Extender con `?string $activityNumber = null`: si viene, usar ese número (sin consumir secuencia en `next*`) y tras insert llamar sync de secuencia al máximo del año (patrón `Records2026ImportService::syncActivitySequence` ~554); si null, comportamiento actual intacto para `activity_new.php`.
- `budjetoc/src/BudgetActivityRules.php` — reusar `validateAndNormalizeHeader` / `creatorCoordinationMayCreateBudget` (ya en validate); no cambiar reglas.
- `budjetoc/public/admin/budget_activities_import.php` — tras validate OK, form/CTA `action=confirm_csv` con re-subida de archivo + CSRF; POST confirm → import; mostrar creados / errores; sin CTA si validate falló.
- `budjetoc/src/Records2026ImportService.php` — solo referencia de INSERT con número fijo + sync; **no modificar**.
- `budjetoc/database/test_budget_activity_csv_validate.php` — patrón smoke; extender o añadir `test_budget_activity_csv_import.php`: limpio→INSERT, duplicado→0 inserts, cleanup.
- **No tocar:** `AdminUserCsvImportService`, portal encargados, schema migrations.

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/src/BudgetService.php` -- Opcional `$activityNumber` en `createActivity` + sync secuencia cuando fijo -- conservar num CSV sin romper UI manual.
- [x] `budjetoc/src/AdminBudgetActivityCsvImportService.php` -- `import*` re-valida y crea grupos vía `createActivity` + `created_by` unidad CSV -- CAP-3.
- [x] `budjetoc/public/admin/budget_activities_import.php` -- CTA confirm solo tras OK; POST `confirm_csv` re-sube e importa -- cierre UI CAP-3.
- [x] `budjetoc/database/test_budget_activity_csv_import.php` (o extensión del smoke validate) -- limpio / duplicado / atribución; cleanup -- I/O matrix.

**Acceptance Criteria:**
- Given CSV limpio validado y re-subido, when confirma, then se crean solo esas actividades con `activity_number` y unidad del CSV en el tenant elegido.
- Given CSV con error de validación, when intenta confirmar, then no hay INSERT y ve los errores.
- Given actividades creadas por carga, when se listan en Presupuesto del tenant, then aparecen bajo la División/Coordinación indicada (`created_by` / campos derivados).
- Given no-superadmin, when POSTea confirm, then se deniega como en historias 1–2.

## Implementation Notes

- 2026-11-21: `BudgetService::createActivity` acepta `?string $activityNumber = null`; con número fijo no consume `nextActivityNumber` y sincroniza `activity_sequences` vía `syncActivitySequenceFromNumber` (mismo criterio que Records2026).
- 2026-11-21: `AdminBudgetActivityCsvImportService` añade `importFromUploadedFile` / `importUploadedCsv` (re-valida → cero INSERT si falla; por grupo `createActivity` + `created_by` unidad CSV). `analyzeGroup` extrae resolución de cabecera/detalle para validate e import.
- 2026-11-21: UI `budget_activities_import.php`: CTA confirmar solo tras validate OK; POST `confirm_csv` exige re-subida multipart + CSRF; resumen de creados / errores.
- 2026-11-21: Smoke `database/test_budget_activity_csv_import.php` — limpio, duplicado, unidad inválida, atribución, cleanup.
- 2026-11-21: Verificación: `php -l` OK en los 3 archivos tocados; smoke import 25/25; smoke validate (regresión CAP-4) 35/35. Fallo mid-batch (INSERT inesperado tras validate OK) cubierto en código (try/catch por grupo) pero sin smoke que fuerce el fallo de BD.
- 2026-11-21: Smoke mid-batch vía `$debugFailInsertActivityNumber` (solo tests): grupo1 queda, grupo2 error, sin INSERT del 2.
- 2026-11-21 (review fixes): smoke assert+restore `activity_sequences`; `createActivity(null)` N-YYYY; asserts `division_id`/`department_id`; debug flag solo CLI + finally; `break` tras analyzeGroup error; UI confirm fallido sin fake `would_upload`; flash acción desconocida.

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Evidence / route |
|---------|---------|------------------|
| BH: status frontmatter aún in-progress | false | Diff stale; en disco ya `in-review`. |
| BH: Spec Change Log / Triage vacíos | false | Secciones de workflow; se llenan en review/loopback. |
| BH: companions SPEC/plantilla no actualizados | false | Historia 3 no exige reescritura de companions; contrato ya incluye CAP-3. |
| BH: AC sin checkboxes | false | Plantilla BMAD no marca AC con `[x]`; verificación es smoke + triage. |
| BH/VG: smoke no assert `activity_sequences` | medium | Confirmado: sync existe (~1062–1104) pero smoke no SELECT last_number. → patch |
| BH/EC/VG: cleanup no restaura secuencia | medium | Confirmado: DELETE headers sin restaurar last_number; bases ~881k inflan tenant. → patch |
| BH: no assert division_id/department_id | low | Parcial vía created_by; assert extra es trivial. → patch |
| BH: sin test no-superadmin confirm | false | `requireSuperadminSession` L8 bloquea antes del POST; mismo patrón historias 1–2. |
| BH: confirm fallido → `$validationReport` con would_upload=0 | medium | Confirmado L120–128: etiqueta “Grupos que se subirían” engaña. → patch |
| BH: confirm no ata hash del CSV validado | false | Diseño historia 2/3: re-subir y re-validar cualquier CSV limpio. |
| BH: resumen muestra created_by numérico | low | Cosmético; lookup de nombre añade superficie. Rechazado. |
| BH: superficies de error parcial vs total distintas | medium | Mismo root que would_upload fake. → patch |
| BH/EC: `$debugFailInsertActivityNumber` sin guard CLI | medium | Público estático; crash puede dejar hook armado. → patch |
| BH: TOCTOU / doble parse sin doc | maybe-false | Race concurrente rara en SA; si real sería medium. → defer |
| BH: notas “25/25” no reproducibles | low | Conteo depende de catálogos; irrelevante al producto. Rechazado. |
| EC: continue tras analyzeGroup error post-validate | medium | Confirmado L264–269: sigue insertando grupos siguientes. → patch |
| EC: sync secuencia sin FOR UPDATE | medium | Mismo patrón Records2026 (preexistente); carga SA no concurrente típica. → defer |
| EC: POST action desconocida sin flash | low | Confirmado: solo validate/confirm; else silencioso. → patch |
| EC claim: sync no-op si base≤0 | false | Tras validate OK el número es `N-YYYY` con base>0; early-return no aplica. |
| VG: `createActivity(null)` sin smoke | medium | Pre-verificado: solo import pasa número fijo; portal usa null. → patch |
| VG: UI confirm_csv sin smoke HTTP | medium | Pre-verificado; mismo hueco que validate HTTP. → defer |

## Design Notes

- Confirmación = **re-subir** el CSV (historia 2: sin sesión). Siempre re-validar antes de escribir.
- `createActivity` con número opcional evita duplicar el INSERT multi-columna; `activity_new` sigue con `null` (secuencia).
- Sync de `activity_sequences` obligatorio tras números fijos para no desalinear altas manuales posteriores.
- Éxito parcial solo ante fallo **inesperado** de INSERT tras validate OK; fallo de validate → cero writes.

## Verification

**Commands:**
- `php -l budjetoc/src/BudgetService.php` -- sin error sintaxis
- `php -l budjetoc/src/AdminBudgetActivityCsvImportService.php` -- sin error sintaxis
- `php -l budjetoc/public/admin/budget_activities_import.php` -- sin error sintaxis
- `php budjetoc/database/test_budget_activity_csv_import.php` (o smoke extendido) -- casos limpio + duplicado OK; cleanup

**Manual checks:**
- UI: tras validate OK aparece confirmar; con errores no; tras confirm, actividades visibles con número y unidad del CSV.
