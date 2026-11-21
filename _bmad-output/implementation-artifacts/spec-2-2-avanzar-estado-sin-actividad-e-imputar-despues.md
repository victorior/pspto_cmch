---
title: '2.2 Avanzar estado sin actividad e imputar después'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context: []
story_key: '2-2-avanzar-estado-sin-actividad-e-imputar-después'
baseline_commit: 'cee25b303b10d2e404a10d558979ab8df6e025b6'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Confirmar que una OC sin imputaciones puede avanzar de estado (p. ej. cerrar con N° factura) y que la imputación sigue disponible después mientras la OC sea editable (Abierta).

**Approach:** El producto ya no exige actividad para cambiar estado (solo N° factura al cerrar; no reabrir). Formalizar con prueba de integración: crear sin allocs → factura → cerrar; y en Abierta imputar después vía edición. No reabrir Cerrada para editar (regla 1.4).

## Boundaries & Constraints

**Always:**
- Cambio de estado sin allocs permitido bajo reglas vigentes (cerrar exige invoice_number_in).
- Imputar después = editar OC **Abierta** (Buscar Actividades / update con allocs).
- Multi-tenant; no consumir presupuesto sin allocs.

**Never:**
- Reabrir Cerrada para imputar (prohibido).
- Relajar N° factura al cerrar.
- Ampliar edición completa de Cerrada.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Cerrar sin allocs | OC Abierta, 0 allocs, invoice&gt;0 → Cerrada | Estado Cerrada; 0 allocs | Sin error de actividad |
| Cerrar sin invoice | 0 allocs, invoice=0 | Bloqueado (regla 1.4) | Mensaje N° factura |
| Imputar en Abierta | OC sin allocs → update con allocs | Allocs persistidas; consume saldo | Errores saldo vigentes |
| Imputar en Cerrada | Intento edit completo | Bloqueado canEdit | N/A |

</frozen-after-approval>

## Code Map

- `OcService::assertStatusTransition` / `update.php` status_only — sin check de allocs
- `oc_new` / OcService update — 2.1 ya permite 0↔N allocs en Abierta
- Test nuevo: `database/test_oc_optional_activity_2_2.php`

## Tasks & Acceptance

**Execution:**
- [x] CLI 2.2 — create 0 allocs → invoice → close; Abierta → add allocs -- FR9/FR10
- [x] Confirmar grep: status path no exige actividad -- ACs
- [x] Documentar en Implementation Notes el flujo esperado -- Claridad

**Acceptance Criteria:**
- Given OC sin imputaciones, when avanzo estado (p. ej. cierro con N° factura), then es permitido.
- Given OC Abierta sin actividad, when imputo después, then se guardan allocs.
- Given validaciones, when guardo o cambio estado sin actividad, then no hay error de actividad obligatoria (otras reglas sí).

## Implementation Notes

- 2026-11-21: Sin cambios de producto (ya cubierto por 2.1 + reglas 1.4). Entregable: `budjetoc/database/test_oc_optional_activity_2_2.php`. Flujo esperado: (1) crear OC con 0 allocs → set `invoice_number_in` → `assertStatusTransition` + UPDATE a Cerrada (sigue 0 allocs); (2) cerrar sin invoice sigue bloqueado; (3) OC Abierta sin allocs → `updatePurchaseOrder` con allocs persiste; (4) Cerrada: `canEdit` false y update completo rechazado (no reabrir). Grep estático: `update.php` status_only + `assertStatusTransition` sin check de actividad. Verificado: `php budjetoc/database/test_oc_optional_activity_2_2.php` (21 OK) y `php budjetoc/database/test_oc_status_rules.php` (6 OK). No se tocó `activity_view` ni se relajaron reglas de factura/cierre/reapertura.

## Spec Change Log

## Review Triage Log

## Design Notes

Casi todo ya está por 2.1 + reglas de estado. 2.2 = verificación + CLI de integración. Imputar post-cierre fuera de alcance.

## Verification

**Commands:**
- `php budjetoc/database/test_oc_optional_activity_2_2.php` -- OK
- `php budjetoc/database/test_oc_status_rules.php` -- OK

**Manual checks:**
- OC sin actividad → ingresar N° → cerrar; otra OC Abierta → imputar después y guardar.
