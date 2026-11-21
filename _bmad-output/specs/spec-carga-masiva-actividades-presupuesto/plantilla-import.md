# Plantilla de import — actividades Presupuesto (CSV)

Contrato de columnas y validación para CAP-2 / CAP-3 / CAP-4.

## Formato de archivo

- Extensión: `.csv`
- Encoding: **UTF-8 con BOM** al descargar la plantilla y al leer la subida (Excel en Windows no debe romper tildes ni eñes).
- Separador: `;`
- Primera fila: encabezados exactos de la plantilla.
- La UI admin ofrece **link de descarga** de esta plantilla (CAP-2).

## Modelo de filas

- Varias filas pueden compartir el mismo identificador de actividad (grupo) → una cabecera `budget_headers` + N `budget_details`.
- Si el producto exige al menos una línea de cuenta, el grupo debe traerla.
- Una misma carga puede mezclar varias Divisiones y Coordinaciones (una unidad por grupo).

## Columnas mínimas (cabecera / grupo)

| Columna (nombre sugerido) | Obligatoria | Significado |
|---------------------------|-------------|-------------|
| `num` o `activity_number` | Sí* | Número base o completo; si solo base, el año de la carga forma `N-YYYY`. |
| `year` / `budget_year` | Sí (archivo o selector UI) | Año presupuestario. |
| `month` | Sí | Mes de la actividad. |
| `activity_name` | Sí | Nombre en catálogo `activities` del tenant (default: debe existir). |
| `creator_type` | Sí (crítico) | `division` \| `coordinacion`. Unidad orgánica a la que se atribuye la actividad. |
| `creator_name` | Sí (crítico) | Nombre exacto del `departments` del tenant → `budget_headers.created_by`. No es el login del super admin. |
| `division_name` | Condicional | Destino si unidad es coordinación; si división, suele ser el propio creador. |
| `cost_center_name` | Condicional | Obligatorio si unidad es división; vacío/null si coordinación. |
| `observations` | No | Texto libre (UTF-8). |

\* Si la UI fija año y el archivo trae solo `num`, el sistema compone `activity_number`.

## Columnas de detalle (por fila de grupo)

| Columna | Obligatoria | Significado |
|---------|-------------|-------------|
| `account_code` / `Cod` | Sí (si hay detalle) | Código en `account_codes` del tenant. |
| `qty` | Según UI actual | Cantidad. |
| `unit_price` / monto | Según UI actual | Precio unitario o monto de línea. |
| `detail_label` / subactividad | No | Texto de línea si el esquema lo soporta. |

Alinear nombres finales al export (`BudgetExportService`) o al CSV Records2026 donde reduzca fricción; incluir 1–2 filas de ejemplo en la plantilla (claramente marcadas).

## Validación previa (CAP-4) — qué mostrar

- **Sí:** cantidad de registros/grupos que se subirían si el archivo estuviera limpio.
- **Sí:** lista solo de records con error (fila/grupo + motivo), incluyendo:
  - `activity_number` / id ya existente en la BD del tenant (solo altas → el SA debe corregir el archivo).
  - unidad orgánica inválida, CC faltante, cuenta inexistente, encoding/parseo, etc.
- **No:** tabla con todos los records válidos a subir.
- Con cualquier error → no habilitar confirmación de carga. Corregir CSV → volver a validar → subir.

## Validaciones por grupo (reglas)

1. Tenant = el elegido en admin; nombres solo en ese tenant.
2. Solo altas: número/id ya existente → error de validación (no skip silencioso en commit).
3. `creator_type` + `creator_name` obligatorios; coordinación solo-recepción → error.
4. División → `cost_center_name` resuelve a `cost_centers`; Coordinación → `cost_center_id` null y `division_name` válida tipo `estandar`.
5. Cuentas/montos de detalle válidos.
6. En la carga confirmada: transacción por grupo (o equivalente) para no corromper inserts previos si un grupo falla inesperadamente.

## Fuera de esta plantilla

- Replicación de catálogos entre tenants.
- Aliases históricos de Records2026 — no requisito de la plantilla nueva.
- Formato `.xlsx`.
