---
title: '1.2 Factura afecta IVA o exenta + totales e impresión'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
story_key: '1-2-factura-afecta-iva-o-exenta-totales-e-impresión'
baseline_commit: 'ed5e555f491eeced60b43c6e61833d2dd5ae02dd'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Con tipo Factura (1.1) el sistema siempre persiste IVA 19 %; el operador no puede declarar factura exenta, y la UI de totales ignora esa rama.

**Approach:** Cuando el tipo es Factura, exigir Afecta IVA o Exenta, persistir `tax_mode`/`tax_percent` correspondientes, y alinear totales UI + servidor + impresión al perfil guardado (sin inferencia por producto).

## Boundaries & Constraints

**Always:**
- Con Factura: elegir Afecta IVA (`iva`/19) o Exenta (`exento`/0); persistir en la OC.
- Totales UI y servidor usan el perfil persistido; impresión factura afecta vs exenta (no boleta).
- Default al elegir Factura: Afecta IVA (comportamiento previo a 1.2).
- Reutilizar columnas `tax_*` de 1.1; semántica numérica de `oc_tax_profile_iva` / `_exento`.
- Multi-tenant; edición solo donde `canEditPurchaseOrder` lo permita (Abierta).

**Never:**
- UI/% retención boleta ni defaults anuales (1.3).
- Edición de perfil en estados no editables hoy (1.4).
- Quitar validación de actividad (Epic 2).
- Nueva migración de esquema (salvo descubrimiento bloqueante).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Factura + Afecta IVA | POST tipo factura + rama iva | Persiste iva/19; totales neto+IVA; impresión Factura IVA | Si Factura sin rama: error validación |
| Factura + Exenta | POST tipo factura + rama exento | Persiste exento/0; IVA 0 total=subtotal; impresión “IVA (exento)” | Igual |
| Boleta | Tipo boleta | Oculta/ignora control afecta-exenta; perfil retención como 1.1 | N/A |
| Cambiar Boleta→Factura | Edición Abierta | Pide/aplica rama factura; totales e impresión modo factura | Validar rama al guardar |
| Cambiar afecta↔exenta | Edición Abierta | Actualiza tax_mode/percent; UI e impresión coinciden | N/A |
| Persistido manda | Factura exenta + líneas “con IVA” | Totales/print usan exento, no inferencia | N/A |

</frozen-after-approval>

## Code Map

- `budjetoc/src/oc_tax_profile.php` — extender `oc_tax_profile_base_fields` (factura+rama); `resolve` ya soporta `exento`; reutilizar builders
- `budjetoc/public/oc/oc_new.php` — POST/hidratación de rama IVA/exenta; validar si tipo=factura
- `budjetoc/public/oc/inc/oc_new_legacy_body.php` — control Afecta/Exenta visible solo con Factura
- `budjetoc/public/oc/js/oc_new_legacy.js` — `refreshDocTotals` hoy fuerza iva en factura; leer rama; show/hide
- `budjetoc/src/OcService.php` — ya persiste tax_*; sin cambio de esquema
- `budjetoc/src/OcPrintView.php` + `inc/oc_print_render.php` — ya ramifican iva/exento si mode persistido
- Continuidad 1.1: `test_oc_tax_profile_1_1.php` / Design Notes — ampliar o añadir CLI 1.2

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/src/oc_tax_profile.php` -- `base_fields` acepta rama factura iva|exento -- Perfil correcto
- [x] `budjetoc/public/oc/oc_new.php` + body + JS -- UI/POST/hidratación Afecta|Exenta; totales JS alineados -- CAP-3
- [x] `OcPrintView` / print render -- Verificar impresión exenta vs afecta con perfil persistido -- FR5/FR6
- [x] CLI test 1.2 (o extensión) -- Matriz: iva, exento, manda sobre líneas, boleta no usa rama -- ACs

**Acceptance Criteria:**
- Given tipo Factura, when elijo Afecta IVA o Exenta y guardo, then el modo queda persistido.
- Given Factura afecta + neto, when se calculan totales (UI y servidor), then IVA 19 % y total = neto + IVA.
- Given Factura exenta + subtotal, when totales/impresión, then IVA 0, total = subtotal, formato exento (no boleta).
- Given perfil factura afecta/exenta, when recalculo o imprimo, then no se usa inferencia por producto.
- Given cambio Boleta→Factura o afecta↔exenta en Abierta, when guardo, then totales e impresión reflejan el nuevo modo factura.

## Implementation Notes

- `oc_tax_profile_base_fields($docType, ?$facturaMode)`: factura + `iva`/`null` → iva/19; `exento` → exento/0; boleta ignora 2º arg. Helpers `oc_tax_profile_iva_fields` / `_exento_fields`.
- UI: radios Afecta IVA / Exenta en `#tax_factura_mode_wrap` (oculto si no es Factura); POST `tax_factura_mode`; validación servidor si Factura sin rama.
- JS: `refreshDocTotals` lee rama; no llama `ocDetectTaxProfile` con Factura; show/hide + validación submit.
- Print: sin cambio de código — `OcPrintView`/`oc_print_render` ya ramifican iva/exento; verificado en CLI 1.2.
- Prueba: `php budjetoc/database/test_oc_tax_profile_1_2.php` (49 asserts). Regresión 1.1 OK (40). Lint PHP OK.
- Pendiente smoke manual en browser (crear afecta/exenta, reabrir/imprimir, Boleta→Factura). No se tocó activity_view historial (deferred).
- Review fix: `oc_tax_profile_from_form_input` (frontera POST); create exento + OcPrintView en CLI; wrap visible sin JS si tipo=factura.

## Spec Change Log

## Review Triage Log

| finding | verdict | evidence |
|---------|---------|----------|
| Radios solo con JS | medium | Parche: wrap visible vía PHP si tipo=factura |
| Validación cliente rama muerta | low | Default iva; servidor valida |
| base_fields vacío vs POST estricto | false | Contrato HTTP distinto del helper libre |
| Create exento no en CLI | medium | Parche: create+print exento |
| Sin test HTTP POST | medium | Parche: `oc_tax_profile_from_form_input` + asserts |
| Label/fieldset IVA | low | Cosmético |
| Nombres helpers *_fields | low | Sin ambigüedad en callers |
| Hydrate post-error | low | Aceptable |
| Cleanup FK | false | Cleanup actual OK en corridas |
| Sprint status en diff | false | Actualizado fuera del diff budjetoc |
| users vacío en CLI | defer | Entorno local siempre tiene users |
| JS refreshDocTotals sin test | defer | Sin harness JS; smoke manual |

## Design Notes

Sin migración nueva. Firma sugerida: `oc_tax_profile_base_fields(string $docType, ?string $facturaMode = null)` — factura sin modo → `iva` (default); boleta ignora segundo arg. Control UI: radio o select junto a Tipo, `display:none` si no es Factura. JS no debe llamar `ocDetectTaxProfile` cuando hay tipo Factura con rama elegida.

## Verification

**Commands:**
- `php budjetoc/database/test_oc_tax_profile_1_2.php` (o suite ampliada) -- asserts de matriz 1.2 OK

**Manual checks:**
- Crear Factura afecta y exenta; reabrir/imprimir; cambiar Boleta→Factura exenta; Boleta no muestra control afecta/exenta.
