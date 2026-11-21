---
title: '1.3 Boleta de Honorarios — retención default anual y editable'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
story_key: '1-3-boleta-de-honorarios-retención-default-anual-y-editable'
baseline_commit: '75b6b25c377f16a1cef09067c47a67a0bfc1078c'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Al elegir Boleta, el % de retención sigue el provisional legado (~12,25 %) y no es editable; no hay default por año comercial (Ley 21.133).

**Approach:** Default de retención según `folio_year` (2026→15,25; 2027→16; 2028→17), campo editable en el formulario, persistir el % elegido (o el default aceptado), y alinear totales UI/servidor/impresión al perfil guardado.

## Boundaries & Constraints

**Always:**
- Con Boleta: mostrar % editable; default = tasa del `folio_year` de la OC.
- Mapa mínimo: 2026=15,25; 2027=16; 2028=17. Años &lt;2026 → 15,25; años &gt;2028 → 17 hasta ampliar mapa.
- Si el usuario edita el %, se persiste su valor; si acepta el default, se persiste ese default.
- Totales: retención = subtotal × R/100 (2 dec); total = subtotal − retención; impresión boleta.
- Perfil persistido manda sobre inferencia por producto y sobre el 12,25 legado.
- Reutilizar `tax_percent`; sin quitar actividad obligatoria (Epic 2).

**Never:**
- UI afecta/exenta (ya 1.2) ni edición en cualquier estado (1.4).
- Migración de OCs históricas en masa.
- Cambiar parseo de glosas de producto para legado sin perfil (puede seguir en 12,25 solo en inferencia).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Boleta 2026 default | folio_year=2026, sin editar % | Persiste 15,25; totales/print con 15,25 | N/A |
| Boleta 2027/2028 | folio_year=2027 o 2028 | Default 16 / 17 | N/A |
| Editar % | Usuario pone otro R (p. ej. 10,75) | Persiste R; totales usan R | % vacío/ inválido → error o default (servidor exige número &gt;0 y ≤100) |
| Factura | Tipo factura | Oculta control % retención | N/A |
| Persistido manda | tax_percent guardado ≠ default año | Resolve/print usan guardado, no 12,25 ni glosa | N/A |
| Cambio folio_year en alta | Cambia año antes de guardar | Default % se actualiza en UI (si usuario no lo editó a mano) | N/A |

</frozen-after-approval>

## Code Map

- `budjetoc/src/oc_tax_profile.php` — mapa año→%; `base_fields` / `from_form_input` boleta + %; `oc_boleta_retencion_rate_percent` solo legado por glosa
- `budjetoc/public/oc/oc_new.php` — POST `tax_retencion_percent` + `folio_year`; bootstrap JS mapa/default
- `budjetoc/public/oc/inc/oc_new_legacy_body.php` — input % visible solo con Boleta
- `budjetoc/public/oc/js/oc_new_legacy.js` — `refreshDocTotals` lee input; default al cambiar tipo/año
- `OcService` / `OcPrintView` — ya persisten/resuelven `tax_percent` (sin schema nuevo)
- Tests: `database/test_oc_tax_profile_1_3.php`; ajustar asserts 1.1 que asumen 12,25 en base_fields boleta

## Tasks & Acceptance

**Execution:**
- [x] `oc_tax_profile.php` -- Default por folio_year + aceptar % en form/base_fields boleta -- FR2/FR3
- [x] `oc_new.php` + body + JS -- UI editable, POST, totales -- CAP-2
- [x] Print/resolve -- Verificar % persistido en impresión boleta -- FR5/FR6
- [x] CLI `test_oc_tax_profile_1_3.php` (+ ajuste 1.1 si hace falta) -- Matriz defaults/edición -- ACs

**Acceptance Criteria:**
- Given Boleta, when se muestra el %, then default = tasa del folio_year (2026=15,25) y es editable.
- Given tasas parametrizadas por año, when creo OC de ese año, then el default corresponde; si edito %, se usa mi valor.
- Given Boleta con R % y subtotal, when totales, then retención y total con redondeo legado; impresión boleta.
- Given perfil retención persistido, when recalculo/imprimo, then no se usa inferencia ni 12,25 legado.
- Given acepto el default sin editar, when guardo, then se persiste ese default.

## Implementation Notes

- `oc_boleta_retencion_default_for_year` + `oc_boleta_retencion_rate_map` en `budjetoc/src/oc_tax_profile.php`. `base_fields` / `from_form_input` aceptan `folio_year` y % opcional; vacío → default anual; inválido/≤0/>100 → RuntimeException.
- UI: `#tax_retencion_wrap` / `#tax_retencion_percent` en `oc_new_legacy_body.php` (solo Boleta). POST + bootstrap mapa/touched en `oc_new.php`. JS rellena default al cambiar tipo/año si el usuario no tocó el campo; totales leen el input.
- Print/resolve: sin schema nuevo; `OcPrintView` + `oc_tax_profile_resolve` usan `tax_percent` persistido (verificado en CLI 1.3). Inferencia legado por glosa sigue en 12,25.
- Tests: `database/test_oc_tax_profile_1_3.php` (matriz + persistencia/print). `test_oc_tax_profile_1_1.php` ajustado: default anual en lugar de 12,25 provisional. Regresión 1.1/1.2 OK.
- Diferido (fuera de alcance): historial `activity_view`.

## Spec Change Log

## Review Triage Log

## Design Notes

Helper `oc_boleta_retencion_default_for_year(int $year): float` en `oc_tax_profile.php`. `oc_tax_profile_from_form_input` / `base_fields` reciben folio_year y % opcional. Input `#tax_retencion_percent` en wrap boleta. JS: si el usuario no “tocó” el campo, al cambiar `folio_year` o elegir Boleta se rellena el default; si lo editó, no pisar. Opcional futuro: override en config — no requerido en 1.3.

## Verification

**Commands:**
- `php budjetoc/database/test_oc_tax_profile_1_3.php` -- matriz OK
- `php budjetoc/database/test_oc_tax_profile_1_1.php` -- regresión OK tras nuevo default

**Manual checks:**
- Boleta 2026 muestra 15,25 editable; editar %; imprimir; Factura oculta el campo; cambiar año actualiza default si no se editó.
