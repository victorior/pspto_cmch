---
title: '1.4 Cambiar perfil tributario en OC existente (cualquier estado)'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
story_key: '1-4-cambiar-perfil-tributario-en-oc-existente-cualquier-estado'
baseline_commit: 'b92ed81e09e81aee767dc7b9c13b43ea69ab833c'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Hoy solo una OC Abierta permite editar (y con ello el perfil tributario). En Cerrada no se puede corregir Factura/Boleta, IVA/exenta o % retención sin recrear la OC.

**Approach:** Permitir cambiar **solo el perfil tributario** en ABIERTA y CERRADA (no ANULADA), sin desbloquear líneas/presupuesto/proveedor. Totales e impresión siguen el perfil guardado; al pasar a Boleta se propone el default del `folio_year` (editable).

## Boundaries & Constraints

**Always:**
- `canChangeTaxProfile`: ABIERTA | CERRADA; ANULADA bloqueada (misma regla de producto).
- No ampliar `canEditPurchaseOrder` a CERRADA (no editar líneas/allocs/proveedor).
- Persistencia multi-tenant; un solo PO; sin migración masiva.
- Cambio Factura↔Boleta reemplaza el modo por completo; Boleta → default % del año, editable.
- Reutilizar helpers 1.1–1.3 (`from_form_input`, resolve, UI de tipo/rama/%).

**Never:**
- Forzar edición completa de OC Cerrada/Anulada.
- Actividad opcional (Epic 2).
- Cambiar reglas de anulación más allá del bloqueo de perfil.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Cerrada: cambiar a Boleta | OC CERRADA, POST perfil boleta+% | tax_* actualizados; print boleta | Si ANULADA: rechazar |
| Cerrada: Factura exenta | OC CERRADA, factura+exento | tax_mode exento; print exento | N/A |
| Abierta: cambio perfil | Como hoy vía form (o misma API tax-only) | Perfil actualizado | N/A |
| Anulada | Intento cambio perfil | Bloqueado | Mensaje / sin UI |
| Factura→Boleta | Cualquier editable perfil | Default % folio_year, editable | Validar % |

</frozen-after-approval>

## Code Map

- `budjetoc/src/OcService.php:249-251` — `canEditPurchaseOrder` ABIERTA-only; añadir `canChangeTaxProfile` + `updatePurchaseOrderTaxProfile`
- `budjetoc/public/oc/update.php` — precedente `invoice_only` / `status_only`; opción `tax_only` o endpoint dedicado
- `budjetoc/public/oc/index.php:121-123` — link “Perfil tributario” / editar tax en CERRADA
- `budjetoc/public/oc/oc_new.php` — modo `tax_only` (form solo tipo/rama/%, líneas readonly o omitidas) O pantalla mínima nueva
- Reutilizar: `oc_tax_profile.php`, body/JS controles 1.2–1.3, `OcPrintView`
- Tests: `database/test_oc_tax_profile_1_4.php`

## Tasks & Acceptance

**Execution:**
- [x] `OcService.php` -- `canChangeTaxProfile` + update solo tax_* (tenant) -- FR7/NFR2
- [x] UI lista + form tax-only -- CERRADA/ABIERTA; ANULADA sin acción -- FR7
- [x] Integrar tipo/rama/% (defaults boleta) y validación -- 1.2/1.3
- [x] CLI 1.4 -- Cerrada OK; Anulada falla; print refleja -- ACs

**Acceptance Criteria:**
- Given OC operativa (abierta/cerrada), when cambio tipo/rama/%, then se persiste y totales/impresión se recalculan.
- Given Factura↔Boleta, when guardo, then modo completo reemplazado; Boleta con default % del año editable.
- Given OC anulada, when intento cambiar perfil, then se mantiene el bloqueo vigente.
- Given el cambio, when se persiste, then multi-tenant y sin migración masiva.

## Implementation Notes

- `OcService::canChangeTaxProfile` → ABIERTA|CERRADA; `canEditPurchaseOrder` sin cambios (ABIERTA-only).
- `OcService::updatePurchaseOrderTaxProfile` actualiza solo `tax_doc_type`/`tax_mode`/`tax_percent` (tenant-scoped).
- `update.php` acepta `tax_only=1` (mismo patrón que `invoice_only`/`status_only`); errores redirigen a `oc_tax_edit.php`.
- Pantalla mínima `oc_tax_edit.php` con tipo/rama/% (defaults boleta por `folio_year`); lista muestra «Perfil tributario» si `canChangeTaxProfile`.
- CLI: `budjetoc/database/test_oc_tax_profile_1_4.php` (matriz + print + multi-tenant). Regresión 1.1–1.3 OK.
- Diferido a propósito: no ampliar edición completa a Cerrada; actividad/historial (Epic 2) no tocado.

## Spec Change Log

## Review Triage Log

## Design Notes

Camino mínimo: no abrir edición completa de Cerrada. Preferir `updatePurchaseOrderTaxProfile` + UI tax-only (o `oc_new.php?id=&tax_only=1` con campos no-tributarios deshabilitados y POST que no reemplace líneas). Listado: botón junto a Cerrada, no “Editar” completo.

## Verification

**Commands:**
- `php budjetoc/database/test_oc_tax_profile_1_4.php` -- matriz OK
- Regresión `test_oc_tax_profile_1_1.php` … `1_3.php`

**Manual checks:**
- Cerrar una OC; cambiar perfil a Boleta/Exenta; imprimir; Anulada sin opción; Abierta sigue pudiendo cambiar perfil.
