# Brownfield — OC tributario y actividad

## Hoy (antes de este Spec)

| Tema | Comportamiento actual |
|------|------------------------|
| Tipo documento | Inferido desde líneas: nombres con "exento de IVA" → exento; códigos/nombres `servicio boleta_*` o "(X% Impto. Retenido)" → retención; resto → factura IVA 19% (`oc_tax_profile.php`, espejo JS en `oc_new_legacy.js`). |
| Default | Sin señales de boleta/exento → **factura con IVA**. |
| Actividad | Validación cliente y servidor: "Debe imputar al menos una actividad" (`oc_new_legacy.js`, `oc_new.php`). |
| Persistencia perfil | No hay columna de perfil tributario en `purchase_orders`; el modo se recalcula desde líneas al imprimir/ver. |
| Estados OC | `ABIERTA` (0), `CERRADA` (1), `ANULADA` (2) — sin regla de negocio que exija imputación presupuestaria para cambiar de estado. |
| Default retención legado | Algunos paths usan 12,25 % si no parsean % desde el nombre del producto. |

## Decisiones resueltas (post Spec)

| Tema | Decisión |
|------|----------|
| Default retención Boleta | **15,25 %** (Chile 2026, Ley 21.133); parametrizable por año comercial; siempre editable manualmente. |
| Cambio de tipo post-emisión | **Permitido** en cualquier estado operativo. |
| Avance sin actividad | **Permitido**; imputación posterior sin bloquear el flujo. |

## Árbol de decisión objetivo (CAP-1..3)

```
Usuario crea/edita OC
 └─ Tipo documento?
     ├─ Factura
     │   └─ ¿Afecta IVA?
     │       ├─ Sí → mode=iva, tax_percent=19
     │       └─ No (exenta) → mode=exento, tax_percent=0
     └─ Boleta de Honorarios
         └─ % retención R (usuario) → mode=retencion, tax_percent=R
```

## Fórmulas (sin cambio)

- **iva:** `tax = round(neto * 19/100)` (entero legado), `total = neto + tax`
- **exento:** `tax = 0`, `total = subtotal`
- **retencion:** `tax = round(subtotal * R/100, 2)`, `total = subtotal - tax`

## Implicación de diseño

El perfil debe **persistirse** en la OC (o equivalente inequívoco). Seguir infiriendo solo desde productos contradice CAP-1 cuando el usuario elige Factura/Boleta explícitamente.

## Puntos de código relevantes

- `budjetoc/src/oc_tax_profile.php` — cálculo servidor
- `budjetoc/public/oc/js/oc_new_legacy.js` — UI totales + validación actividad
- `budjetoc/public/oc/oc_new.php` — guardado / validación actividad
- `budjetoc/src/OcService.php` — persistencia PO / líneas / imputaciones
- `budjetoc/public/oc/inc/oc_print_render.php`, `OcPrintView.php` — impresión
