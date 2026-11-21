---
status: final
stepsCompleted:
  - step-01-validate-prerequisites
  - step-02-design-epics
  - step-03-create-stories
  - step-04-final-validation
inputDocuments:
  - ../specs/spec-oc-formato-tributario-actividad/SPEC.md
  - ../specs/spec-oc-formato-tributario-actividad/brownfield.md
  - architecture-spine-budjetoc.md
---

# pspto_cmch - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for pspto_cmch, decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

## Requirements Inventory

### Functional Requirements

FR1: Al crear o editar una OC, el usuario debe poder elegir el tipo de documento: Factura o Boleta de Honorarios.
FR2: Al elegir Boleta de Honorarios, el usuario debe poder asignar el porcentaje de retención aplicado a los totales.
FR3: Al elegir Boleta, el sistema debe proponer un % de retención por defecto según el año comercial (2026 = 15,25 %), parametrizable por año y editable manualmente.
FR4: Al elegir Factura, el usuario debe indicar si afecta al IVA o es exenta.
FR5: El sistema debe aplicar automáticamente formato e impuesto según la elección: factura afecta → IVA 19 %; factura exenta → IVA 0; boleta → retención al % elegido.
FR6: El perfil tributario (modo + %) debe persistir con la OC y usarse en recálculo e impresión, mandando sobre la inferencia por código/nombre de producto.
FR7: El usuario debe poder cambiar tipo de documento / rama IVA-exenta / % retención después de emitida o aprobada (cualquier estado operativo).
FR8: El usuario debe poder crear y guardar una OC sin imputar actividad ni líneas de presupuesto.
FR9: Una OC sin actividad debe poder avanzar de estado; la imputación de actividad permanece disponible posteriormente sin bloquear el flujo.
FR10: La búsqueda e imputación de actividades de presupuesto debe seguir disponible de forma opcional.

### NonFunctional Requirements

NFR1: Cambios acotados al módulo OC / presupuesto vinculado; no rediseñar el monolito ni el stack.
NFR2: Respetar aislamiento multi-tenant (`tenant_id`) en lecturas/escrituras del perfil tributario e imputaciones.
NFR3: Secretos y DocumentRoot según architecture spine (`public/` solo; secretos fuera del árbol web).
NFR4: Fórmulas de totales deben conservar la semántica numérica ya usada en `oc_tax_profile` (IVA 19 % entero legado; retención con redondeo a 2 decimales).
NFR5: OCs históricas no se migran en masa; el perfil nuevo aplica a creación/edición explícita.

### Additional Requirements

- Architecture spine brownfield: modular monolith PHP + MySQL multi-tenant; no starter greenfield.
- Persistir perfil tributario en la OC (hoy no existe columna; se infiere desde líneas) — requisito técnico derivado del Spec/brownfield.
- Parametrizar tasas de retención por año comercial (config/tabla) además del default 15,25 % para 2026.
- Reutilizar superficies existentes: `oc_new.php`, `oc_new_legacy.js`, `oc_tax_profile.php`, `OcService.php`, impresión (`OcPrintView` / `oc_print_render.php`).
- Estados OC vigentes: ABIERTA / CERRADA / ANULADA — sin regla brownfield de imputación obligatoria al cambiar estado.
- No eliminar productos `servicio boleta_*` del catálogo (compatibilidad legado).

### UX Design Requirements

_Ningún documento UX (DESIGN/EXPERIENCE) incluido. La UI de elección Factura/Boleta, % retención e IVA/exenta se especificará en stories a partir del Spec._

### FR Coverage Map

FR1: Epic 1 — Elegir Factura o Boleta
FR2: Epic 1 — Asignar % retención en Boleta
FR3: Epic 1 — Default retención por año + edición manual (Ley 21.133)
FR4: Epic 1 — Factura afecta IVA o exenta
FR5: Epic 1 — Aplicar formato e impuesto automático
FR6: Epic 1 — Persistir perfil; manda sobre inferencia
FR7: Epic 1 — Cambiar tipo/% en cualquier estado
FR8: Epic 2 — Guardar OC sin actividad
FR9: Epic 2 — Avanzar estado sin actividad
FR10: Epic 2 — Imputación de actividad opcional

## Epic List

### Epic 1: Formato tributario explícito en OC (cumplimiento SII)
El operador declara si la OC es Factura (afecta IVA 19 % o exenta) o Boleta de Honorarios (retención con default vigente por año comercial según Ley 21.133: 15,25 % en 2026, parametrizable hacia 16 %/17 % y editable para años anteriores). El perfil persiste, manda sobre la inferencia por producto, alimenta totales e impresión, y puede modificarse en cualquier estado operativo.
**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR6, FR7

### Epic 2: Actividad de presupuesto opcional en OC
El operador puede crear, guardar y avanzar de estado una OC sin imputar actividad; la búsqueda e imputación presupuestaria sigue disponible después, sin bloquear el flujo principal.
**FRs covered:** FR8, FR9, FR10

## Epic 1: Formato tributario explícito en OC (cumplimiento SII)

El operador declara si la OC es Factura (afecta IVA 19 % o exenta) o Boleta de Honorarios (retención con default vigente por año comercial según Ley 21.133: 15,25 % en 2026, parametrizable hacia 16 %/17 % y editable para años anteriores). El perfil persiste, manda sobre la inferencia por producto, alimenta totales e impresión, y puede modificarse en cualquier estado operativo.

### Story 1.1: Elegir tipo de documento y persistir perfil base

As a operador de OC,
I want elegir si la OC es Factura o Boleta de Honorarios al crearla,
So that el sistema deje de asumir factura por defecto y guarde mi elección.

**Acceptance Criteria:**

**Given** estoy creando una OC nueva
**When** el formulario me pide el tipo de documento
**Then** debo poder elegir Factura o Boleta de Honorarios (obligatorio antes de guardar)
**And** no se usa solo la inferencia por nombre/código de producto para fijar el tipo

**Given** elegí un tipo y guardo la OC
**When** se persiste la OC
**Then** el perfil tributario base (modo asociado al tipo) queda guardado en la OC (migración/campos solo los necesarios para esta story)
**And** al reabrir o imprimir, el tipo mostrado corresponde a lo guardado (multi-tenant, NFR2)

**Given** una OC existente en estado Borrador
**When** la edito
**Then** puedo modificar el tipo de documento elegido y se actualiza el perfil tributario persistido

**Given** una OC histórica sin perfil persistido
**When** se abre sin editar el tipo
**Then** el comportamiento legado por inferencia puede seguir aplicándose hasta que el usuario defina el tipo (NFR5)

### Story 1.2: Factura afecta IVA o exenta + totales e impresión

As a operador de OC,
I want indicar si la Factura afecta al IVA o es exenta,
So that totales e impresión reflejen el tratamiento tributario correcto ante el SII.

**Acceptance Criteria:**

**Given** elegí tipo Factura al crear o editar una OC
**When** el sistema pide el tratamiento de IVA
**Then** debo poder elegir Afecta IVA o Exenta
**And** esa elección queda persistida en el perfil tributario de la OC (FR4, FR6)

**Given** Factura con Afecta IVA y un subtotal neto
**When** se calculan los totales (UI y servidor)
**Then** se aplica IVA 19 % con la semántica numérica existente de `oc_tax_profile`
**And** el total = neto + IVA (FR5, NFR4)

**Given** Factura Exenta y un subtotal
**When** se calculan los totales
**Then** IVA = 0 y total = subtotal
**And** la impresión muestra el formato de factura exenta (no retención de boleta)

**Given** el perfil persistido es factura afecta o exenta
**When** se recalculan totales o se imprime
**Then** se usa el perfil guardado, no la inferencia por nombre/código de producto (FR6)

**Given** cambié de Boleta a Factura (o entre afecta/exenta) en borrador
**When** guardo
**Then** totales e impresión se actualizan al nuevo modo factura

### Story 1.3: Boleta de Honorarios — retención default anual y editable

As a operador de OC,
I want asignar el % de retención en Boleta de Honorarios, con un default según el año comercial,
So that la OC cumpla la tasa vigente (Ley 21.133) y pueda ajustarse a otros años o cambios futuros.

**Acceptance Criteria:**

**Given** elegí tipo Boleta de Honorarios
**When** el sistema muestra el % de retención
**Then** propone el default del año comercial de la OC (2026 = **15,25 %**)
**And** puedo editar el % manualmente antes de guardar (FR2, FR3)

**Given** la tasa por año está parametrizada (p. ej. 2026→15,25; 2027→16; 2028→17)
**When** creo una OC de un año comercial dado
**Then** el default corresponde a ese año
**And** si edito el % a otro valor (p. ej. documento de año anterior), se usa mi valor (FR3)

**Given** Boleta con retención R % y un subtotal
**When** se calculan los totales (UI y servidor)
**Then** retención = subtotal × R / 100 (redondeo legado a 2 decimales)
**And** total = subtotal − retención; impresión en formato boleta (FR5, NFR4)

**Given** el perfil persistido es retención
**When** se recalculan totales o se imprime
**Then** se usan modo y % guardados, no la inferencia por producto ni el default 12,25 % legado (FR6)

**Given** no ingreso un % y acepto el default
**When** guardo
**Then** se persiste el default aplicado (15,25 % u otro según año)

### Story 1.4: Cambiar perfil tributario en OC existente (cualquier estado)

As a operador de OC,
I want cambiar el tipo de documento, la rama IVA/exenta o el % de retención en una OC ya guardada (incluida emitida/cerrada),
So that pueda corregir el tratamiento tributario sin recrear la OC.

**Acceptance Criteria:**

**Given** una OC existente en estado operativo (p. ej. abierta/cerrada; no anulada si el producto impide edición)
**When** edito tipo (Factura ↔ Boleta), afecta/exenta o % retención
**Then** puedo guardar el nuevo perfil tributario persistido (FR7)
**And** totales e impresión se recalculan con el nuevo perfil

**Given** cambio de Factura afecta a Boleta (o viceversa)
**When** guardo
**Then** el modo anterior se reemplaza por completo (sin mezclar IVA y retención)
**And** si paso a Boleta, se propone el default de retención del año comercial (editable)

**Given** una OC anulada (si hoy no es editable)
**When** intento cambiar el perfil
**Then** el sistema mantiene la regla de edición vigente del producto (no forzar edición en anuladas si ya está bloqueada)

**Given** el cambio de perfil
**When** se persiste
**Then** sigue aplicando aislamiento multi-tenant (NFR2) y no migra otras OCs en masa (NFR5)

## Epic 2: Actividad de presupuesto opcional en OC

El operador puede crear, guardar y avanzar de estado una OC sin imputar actividad; la búsqueda e imputación presupuestaria sigue disponible después, sin bloquear el flujo principal.

### Story 2.1: Guardar OC sin imputar actividad

As a operador de OC,
I want guardar una OC nueva o en edición sin asignar actividad de presupuesto,
So that pueda registrar la compra antes de tener la imputación presupuestaria.

**Acceptance Criteria:**

**Given** estoy creando o editando una OC
**When** guardo sin líneas de imputación de presupuesto
**Then** el guardado es válido (sin error de “debe imputar al menos una actividad”)
**And** la OC queda persistida (FR8)

**Given** el formulario de OC
**When** no he imputado actividades
**Then** “Buscar Actividades” sigue disponible de forma opcional
**And** puedo agregar imputaciones en el mismo guardado si quiero (FR10)

**Given** una OC guardada sin actividad
**When** la reabro
**Then** no se exigen imputaciones para volver a guardar
**And** no consume presupuesto hasta que existan imputaciones

### Story 2.2: Avanzar estado sin actividad e imputar después

As a operador de OC,
I want avanzar el estado de una OC sin actividad e imputar presupuesto más tarde,
So that el flujo operativo no quede bloqueado por la imputación presupuestaria.

**Acceptance Criteria:**

**Given** una OC sin imputaciones de presupuesto
**When** cambio su estado operativo (p. ej. abierta ↔ cerrada, según reglas actuales del producto)
**Then** el cambio de estado es permitido
**And** no se exige actividad/presupuesto para avanzar (FR9)

**Given** una OC en cualquier estado operativo editable y aún sin actividad
**When** uso “Buscar Actividades” e imputo líneas de presupuesto
**Then** las imputaciones se guardan correctamente
**And** a partir de ahí la OC puede consumir presupuesto (FR10)

**Given** las validaciones de cliente (`oc_new_legacy.js`) y servidor (`oc_new.php`)
**When** se intenta guardar o cambiar estado sin actividad
**Then** ya no se muestra/lanza el error de actividad obligatoria
**And** otras validaciones existentes (proveedor, líneas de producto, etc.) se mantienen
