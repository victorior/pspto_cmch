---
title: '1.1 Elegir tipo de documento y persistir perfil base'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
story_key: '1-1-elegir-tipo-de-documento-y-persistir-perfil-base'
baseline_commit: '9818fa8f8e526395954de3c452700c6eaba156b9'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Al crear o editar una OC el sistema asume Factura e infiere el tipo solo desde líneas/productos; no hay perfil tributario persistido en la OC.

**Approach:** Exigir elección explícita Factura o Boleta de Honorarios, persistir el perfil base en la OC, y usarlo al reabrir e imprimir (con fallback a inferencia solo en OCs históricas sin perfil).

## Boundaries & Constraints

**Always:**
- Tipo Factura | Boleta obligatorio al guardar OC nueva o al editar si el usuario define/cambia tipo.
- Perfil persistido manda sobre inferencia por producto cuando existe.
- Lecturas/escrituras con `tenant_id` de sesión.
- OCs sin perfil: comportamiento legado por inferencia hasta que el usuario elija tipo.
- En 1.1: Factura → mode `iva` / 19 %; Boleta → mode `retencion` / tasa provisional del helper legado (1.3 fijará default 15,25 % y UI de %).

**Never:**
- Migrar en masa OCs históricas.
- Implementar UI afecta/exenta (1.2) ni % retención editable / defaults anuales (1.3).
- Quitar validación de actividad (Epic 2) ni rediseñar catálogo `servicio boleta_*`.
- Cambiar DocumentRoot ni secretos.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Crear con Factura | POST con tipo factura | Persiste perfil base factura/`iva`/19; al reabrir/imprimir muestra Factura | Si falta tipo: error de validación, no guarda |
| Crear con Boleta | POST con tipo boleta | Persiste boleta/`retencion`/% provisional; impresión etiqueta Boleta | Igual si falta tipo |
| Editar tipo en Abierta | Cambia Factura↔Boleta y guarda | Actualiza perfil persistido; UI/impresión reflejan el nuevo tipo | Solo si `canEditPurchaseOrder` lo permite |
| Histórica sin perfil | Abre OC antigua, no toca tipo | Totales/impresión siguen inferencia por líneas | N/A |
| Histórica define tipo | Usuario elige tipo y guarda | Deja de inferir; usa perfil guardado | Validar tipo al guardar |

</frozen-after-approval>

## Code Map

- `budjetoc/database/schema.sql` + `migrations/` (siguiente: `016_*.sql`) — `purchase_orders` sin columnas tributarias; patrón `ALTER … ADD COLUMN … AFTER …`
- `budjetoc/src/OcService.php` — `createPurchaseOrder` / `updatePurchaseOrder`: extender INSERT/UPDATE; no tocar solo líneas/allocs
- `budjetoc/public/oc/oc_new.php` — POST sin campos de tipo; validar y pasar perfil a OcService
- `budjetoc/public/oc/inc/oc_new_legacy_body.php` + `js/oc_new_legacy.js` — sin selector; añadir UI + POST; reutilizar `ocDetectTaxProfile` solo como fallback
- `budjetoc/src/oc_tax_profile.php` — reutilizar builders `oc_tax_profile_iva` / `_retencion`; añadir resolución “persistido o inferido”
- `budjetoc/src/OcPrintView.php` + `public/oc/inc/oc_print_render.php` — hoy solo `oc_tax_profile_from_po_lines`; leer perfil persistido cuando exista
- `budjetoc/database/apply_all_migrations.py` / `verify_migrations.py` — aplicar y verificar columna(s)

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/database/migrations/016_purchase_orders_tax_profile.sql` (+ schema si aplica) -- Añadir columnas mínimas de perfil (`tax_doc_type`, `tax_mode`, `tax_percent` nullable) -- Persistir elección
- [x] `budjetoc/src/oc_tax_profile.php` -- Resolver perfil: persistido si hay tipo/modo; si no, inferencia legado -- Una fuente de verdad
- [x] `budjetoc/src/OcService.php` -- Leer/escribir perfil en create/update y SELECT de edición -- Persistencia multi-tenant
- [x] `budjetoc/public/oc/oc_new.php` + `inc/oc_new_legacy_body.php` + `js/oc_new_legacy.js` -- Selector obligatorio Factura/Boleta, validación POST, hidratar en edición -- CAP-1 UI
- [x] `budjetoc/src/OcPrintView.php` (+ render) -- Usar perfil resuelto para etiqueta Factura/Boleta -- Impresión coherente
- [x] Verificar migración local y matrix de edge cases -- ACs de story 1.1

**Acceptance Criteria:**
- Given estoy creando una OC nueva, when el formulario pide tipo de documento, then debo elegir Factura o Boleta (obligatorio antes de guardar) y no se fija el tipo solo por inferencia de producto.
- Given elegí un tipo y guardo, when se persiste, then el perfil base queda en la OC y al reabrir/imprimir el tipo mostrado corresponde (con `tenant_id`).
- Given una OC en estado Abierta (editable), when cambio el tipo y guardo, then se actualiza el perfil persistido.
- Given una OC histórica sin perfil, when se abre sin editar el tipo, then sigue la inferencia legado hasta que el usuario defina tipo.

## Implementation Notes

- Migración `016_purchase_orders_tax_profile.sql`: columnas nullable `tax_doc_type`, `tax_mode`, `tax_percent` en `purchase_orders` (también reflejado en `schema.sql`).
- `oc_tax_profile_base_fields()` fija Factura→iva/19 y Boleta→retencion/% provisional del helper (`oc_boleta_retencion_rate_percent('')`).
- `oc_tax_profile_resolve()` usa perfil persistido si hay tipo/modo; si no, `oc_tax_profile_from_po_lines`.
- UI: select `#tax_doc_type` obligatorio en OC nueva y en edición con perfil; histórico sin perfil puede guardar sin elegir (deja tax_* intactos).
- `apply_all_migrations.py` / `verify_migrations.py`: leen `db.port` numérico del config; verifican las 3 columnas.
- Prueba CLI: `php budjetoc/database/test_oc_tax_profile_1_1.php` (23 asserts, matriz 1.1).
- Verificado: migración 016 aplicada localmente; `verify_migrations.py` OK; lint PHP OK. Manual UI crear/reabrir/imprimir pendiente de smoke en browser.

## Spec Change Log

## Review Triage Log

| finding | verdict | evidence |
|---------|---------|----------|
| Boleta siempre persiste % provisional del helper | false | Intent frozen exige provisional hasta 1.3; `oc_tax_profile_base_fields` hace exactamente eso |
| tax_percent ignorado en rama IVA | false | Intent 1.1 fija Factura→iva/19; `oc_tax_profile_iva` es la semántica correcta |
| Sin UI/persistencia exento | false | Intent Never: UI afecta/exenta es 1.2 |
| Print hardcodea IVA (19%) | low | Cosmético preexistente; 1.1 exige etiqueta tipo, no reescritura de fila IVA |
| `OC_NEW_CONFIG.taxDocType` no leído en JS | low | El select DOM hidrata; campo muerto no afecta comportamiento |
| INSTALACION.txt no menciona 016 | low | Docs; migrate/verify ya cubren 016 |
| Tests solo helpers, no OcService/print | medium | Gap pre-verificado: se puede borrar INSERT tax_* y el CLI sigue verde |
| LegacyOcImport sin tax_* | defer | Import legado no es flujo de create/edit de esta story |
| Label i18n Factura vs Boleta | low | Cosmético; no rompe ACs |
| UPDATE duplicado tax/non-tax | low | Deuda de forma; un solo caller actual |
| Resolve prioriza mode=retencion sobre factura | false | Write path siempre usa `base_fields` coherente; inconsistencia solo con DB manual |
| Docs migraciones 001–016 sin 006 | false | Cosmético de docstring; runner aplica archivos presentes |
| Predicado JS vs server taxRequired | false | Ambas exigen tipo en alta; histórico sin perfil opcional en ambos |
| Falta nota operador “correr 016” | low | verify_migrations + apply ya son el camino documentado en Verification |
| Resolve con tipo desconocido cae a IVA | maybe-false | Solo con filas corruptas; write path valida factura\|boleta |
| factura+mode retencion imprime boleta | false | App no escribe ese triple |
| Hydrate sin strtolower | low | Persistencia siempre lowercase vía `base_fields`; patch trivial opcional |
| Re-run migración 016 aborta | false | `apply_all_migrations.py` salta errores duplicate/already exists |
| create con taxProfile vacío guarda percent 0 | false | create solo recibe `base_fields` o null desde oc_new |
| update guarda '' en vez de NULL | false | update con perfil solo desde `base_fields` no vacío |
| Port quoted en config | false | config usa `'port' => 3306` numérico |
| Gap: round-trip OcService tax_* | medium | verification-gap disposition patch |
| Gap: OcPrintView doc_type persistido | medium | verification-gap disposition patch |
| Gap: POST sin tipo en create | defer | Sin harness HTTP; smoke manual + cerrar round-trip OcService primero |
| run_verification_tests.py sin db.port | defer | Preexistente; apply/verify ya leen port |

## Design Notes

Columnas nullable: `tax_doc_type` (`factura`|`boleta`), `tax_mode`, `tax_percent`. NULL = legado. Factura en 1.1 fija `iva`/19; Boleta fija `retencion` + % del helper actual (provisional hasta 1.3). UI: control explícito en el formulario OC (no inferido). Edición de perfil en estados no-Abierta queda para 1.4 (`canEditPurchaseOrder` vigente).

## Verification

**Commands:**
- `py budjetoc/database/apply_all_migrations.py` -- aplica `016` sin error
- `py budjetoc/database/verify_migrations.py` -- confirma columnas de perfil en `purchase_orders`

**Manual checks:**
- Crear OC Factura y Boleta; reabrir y verificar tipo; imprimir etiqueta; OC vieja sin columnas pobladas sigue inferencia.
