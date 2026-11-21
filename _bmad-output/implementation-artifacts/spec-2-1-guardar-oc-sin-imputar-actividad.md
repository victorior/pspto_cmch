---
title: '2.1 Guardar OC sin imputar actividad'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
story_key: '2-1-guardar-oc-sin-imputar-actividad'
baseline_commit: '6c7551a5de9fa263001d09af5cbfc7d6f8ca028e'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Crear/editar OC exige al menos una imputación de actividad de presupuesto; el operador no puede registrar la compra si aún no tiene imputación.

**Approach:** Quitar la obligatoriedad de imputaciones en JS, `oc_new.php` y `OcService` create/update. “Buscar Actividades” sigue disponible. Sin imputaciones no se consume presupuesto.

## Boundaries & Constraints

**Always:**
- Guardar con cero imputaciones es válido; con imputaciones, validaciones de monto/saldo vigentes.
- Otras validaciones se mantienen (proveedor, líneas de producto, tipo documento, etc.).
- Multi-tenant; no rediseñar el módulo presupuesto.

**Never:**
- Eliminar Buscar Actividades.
- Cambiar reglas de cierre/reapertura/N° factura (ya fijadas tras 1.4).
- Story 2.2 (avanzar estado) más allá de lo que ya permita el producto sin exigir actividad.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Crear sin allocs | POST con líneas producto, budget vacío | OC creada; 0 filas po_budget_allocations | Sin error de actividad |
| Editar sin allocs | Update con budget vacío | Allocs borradas/vacías; OC OK | N/A |
| Crear con allocs | Budget con montos | Como hoy; saldo validado | Errores saldo vigentes |
| Reabrir sin allocs | Editar OC sin imputaciones | Guardar de nuevo sin exigir allocs | N/A |
| Buscar Actividades | Sin allocs previas | Modal/búsqueda sigue disponible | N/A |

</frozen-after-approval>

## Code Map

- `budjetoc/public/oc/js/oc_new_legacy.js` — guard submit “Debe imputar al menos una actividad”
- `budjetoc/public/oc/oc_new.php` — throw si `$allocs === []`
- `budjetoc/src/OcService.php` — throw si normalizeAllocations vacío en create/update
- `BudgetService::assertOcAllocationsWithinSaldo` — ya no-op con lista vacía (no tocar)
- UI Buscar Actividades — dejar intacta
- Test: `database/test_oc_optional_activity_2_1.php`

## Tasks & Acceptance

**Execution:**
- [x] Quitar 3 gates (JS + oc_new + OcService create/update) -- Permitir 0 allocs
- [x] Soften hint opcional si es trivial -- UX
- [x] CLI create/update sin allocs + con allocs -- ACs
- [x] Confirmar Buscar Actividades intacto -- FR10

**Acceptance Criteria:**
- Given creo/edito OC, when guardo sin imputaciones, then guarda sin error de actividad.
- Given formulario, when no imputé, then Buscar Actividades sigue disponible.
- Given OC sin actividad, when reabro y guardo, then no se exigen imputaciones y no consume presupuesto.

## Implementation Notes

- 2026-11-21: Eliminados gates de “al menos una imputación” en `budjetoc/public/oc/js/oc_new_legacy.js` (submit), `budjetoc/public/oc/oc_new.php` y `OcService::createPurchaseOrder` / `updatePurchaseOrder`. Hint UX suavizado a “Opcional: …”. UI “Buscar Actividades” sin cambios. CLI `budjetoc/database/test_oc_optional_activity_2_1.php` cubre create/update sin allocs, create con allocs, vaciar allocs en update, y presencia del botón. Verificado: `php budjetoc/database/test_oc_optional_activity_2_1.php` (13 OK) y `php budjetoc/database/test_oc_status_rules.php` (6 OK; reglas 1.4 intactas). Sin tocar `activity_view` historial ni reglas de factura/cierre/reapertura.

## Spec Change Log

## Review Triage Log

## Design Notes

Cambio mínimo: eliminar throws/guards de “al menos una”. No migración. Update con allocs vacíos: DELETE allocations existentes (comportamiento actual del update).

## Verification

**Commands:**
- `php budjetoc/database/test_oc_optional_activity_2_1.php` -- OK
- Regresión tax 1.1–1.4 si aplica

**Manual checks:**
- Nueva OC con productos, sin actividad → guarda; Buscar Actividades funciona; reabrir y guardar de nuevo.
