---
id: SPEC-oc-formato-tributario-actividad
companions:
  - brownfield.md
  - ../../planning-artifacts/architecture-spine-budjetoc.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# OC — formato tributario explícito y actividad opcional

## Why

**Pain to solve:** al crear una Orden de Compra el sistema asume por defecto el formato de factura (e infiere boleta/exento desde nombres de producto), y obliga a imputar al menos una actividad de presupuesto. Los operadores necesitan declarar explícitamente si la OC es Factura o Boleta de Honorarios —con retención o IVA/exenta según corresponda— y poder crear la OC sin actividad cuando aún no hay imputación presupuestaria.

## Capabilities

- **CAP-1**
  - **intent:** Al crear o editar una OC, el usuario elige el tipo de documento (Factura o Boleta de Honorarios) y el sistema aplica el formato e impuesto asociados a esa elección.
  - **success:** Tras elegir Factura o Boleta, el perfil tributario mostrado/calculado e impreso corresponde a la elección del usuario, no a la inferencia por producto; el tipo puede cambiarse también después de emitida/aprobada.

- **CAP-2**
  - **intent:** Si el usuario elige Boleta de Honorarios, puede asignar el porcentaje de retención; el sistema propone un default por año comercial y permite editarlo manualmente.
  - **success:** Default vigente 2026 = 15,25 % (parametrizable por año; editable); con R elegido, retención = subtotal × R / 100 y total = subtotal − retención; el % queda asociado a la OC.

- **CAP-3**
  - **intent:** Si el usuario elige Factura, indica si afecta al IVA o es exenta; el sistema aplica el impuesto correspondiente.
  - **success:** Factura afecta → IVA 19% sobre neto; Factura exenta → IVA 0 y total = subtotal; el modo queda asociado a la OC.

- **CAP-4**
  - **intent:** El usuario puede crear, editar y avanzar de estado una OC sin imputar actividad; la imputación permanece disponible después.
  - **success:** Guardar y cambiar estado con cero imputaciones no produce error; Buscar Actividades sigue disponible; no se exige presupuesto antes de aprobar/avanzar (no hay regla brownfield que lo imponga).

## Constraints

- La elección del usuario manda sobre la inferencia por código/nombre de producto.
- El perfil tributario elegido (modo + %) debe persistir con la OC para recálculo e impresión.
- Fórmulas de totales: Factura afecta = neto + IVA 19%; Factura exenta = subtotal; Boleta = subtotal − retención(subtotal × % / 100). Detalle en `brownfield.md`.
- Retención default Boleta: 15,25 % para 2026; debe ser parametrizable por año comercial (p. ej. 16 % 2027, 17 % 2028) y siempre editable manualmente (p. ej. documentos de años anteriores).
- Tipo documento / rama IVA-exenta / % retención editable en cualquier estado operativo (no solo borrador).
- Actividad opcional en todo el flujo; no bloquea avance de estado.
- Cambios acotados al módulo OC / presupuesto vinculado; respetar multi-tenant y architecture spine (DocumentRoot `public/`, secretos fuera del árbol web).

## Non-goals

- Rediseñar el catálogo de productos o eliminar productos “servicio boleta_*”.
- Cambiar el flujo de anulación de OC más allá de lo necesario para actividad opcional y perfil tributario.
- Recalcular o migrar en masa OCs históricas ya emitidas (salvo que el usuario edite una OC concreta).
- Introducir otros tipos de documento (guía, nota de crédito, etc.).

## Success signal

Un operador crea una OC eligiendo Boleta (default 15,25 %, editable) sin imputar actividad, avanza de estado y ve totales/impresión en formato boleta; crea otra eligiendo Factura afecta o exenta y ve IVA 19 % o exento; en ambos casos puede cambiar el tipo después y guardar sin actividad.

## Assumptions

- Factura afecta usa IVA 19 % fijo ya implementado; no se pide % IVA al usuario.
- Una OC sin actividad no consume presupuesto hasta que se imputen líneas.
- Estados actuales (`ABIERTA` / `CERRADA` / `ANULADA`) no añaden regla de imputación obligatoria al avanzar.
