# Decisión: eliminar regla receive-only (2026-11-21)

## Contexto

En el dashboard de presupuesto (`budget/index.php`) una lista hardcodeada de nombres
(`Docencia`, `Administracióncc`, `Direccióncc`, `Laboratorios`, `Pre universitario`)
reclasificaba coordinaciones del catálogo `departments` hacia la columna
**Centros de Costo** y bloqueaba la creación de actividades desde esas unidades.

Eso contradecía el catálogo admin (`admin/tenant_catalog.php` y páginas hijas), que es
la única UI donde se crean **Divisiones** y **Coordinaciones**.

## Decisión

Se elimina por completo la regla de negocio **receive-only** / **catalogReceive**:

- No hay lista hardcodeada de nombres “solo recepción”.
- No se bloquea crear actividades desde coordinaciones por nombre.
- Rename/delete de catálogo org ya no cita dependencia receive-only.
- Import CSV de actividades tampoco aplica ese bloqueo.

## Comportamiento del dashboard

| Columna | Fuente |
|---------|--------|
| Divisiones | `departments` type `estandar` (enlaces) |
| Coordinaciones/C.C. | `departments` type `coordinacion` (todas; enlaces) |
| Centros de Costo | `cost_centers` (catálogo contable; solo lectura en el selector) |

Los centros de costo siguen usándose al crear actividades **desde una división**
(`budget_headers.cost_center_id`). No son unidades del selector de departamento.

## Alcance express

Fix documentado y entregado sin pasar por PRD/epics completos. Artefactos tocados
principalmente en `budjetoc/src/BudgetActivityRules.php`, dashboard, `activity_new`,
`CatalogService`, import CSV y tests CLI asociados.
