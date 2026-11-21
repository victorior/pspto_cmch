# Epic 1 Context: Formato tributario explícito en OC (cumplimiento SII)

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

El operador declara en la OC si el documento es Factura (afecta IVA 19 % o exenta) o Boleta de Honorarios (retención con default por año comercial según Ley 21.133, editable). Ese perfil persiste, manda sobre la inferencia por producto, alimenta totales e impresión, y puede cambiarse en cualquier estado operativo.

## Stories

- Story 1.1: Elegir tipo de documento y persistir perfil base
- Story 1.2: Factura afecta IVA o exenta + totales e impresión
- Story 1.3: Boleta de Honorarios — retención default anual y editable
- Story 1.4: Cambiar perfil tributario en OC existente (cualquier estado)

## Requirements & Constraints

- Elección explícita Factura | Boleta; no fijar el tipo solo por inferencia de líneas/productos.
- Factura: rama afecta IVA (19 %) o exenta (0 %). Boleta: % retención con default 15,25 % en 2026, parametrizable por año (p. ej. 16 % 2027, 17 % 2028) y siempre editable.
- El perfil (modo + %) debe persistir con la OC y usarse en recálculo e impresión.
- Cambiar tipo / IVA-exenta / % en cualquier estado operativo (no solo borrador).
- OCs históricas sin perfil: legado por inferencia hasta que el usuario defina tipo; sin migración masiva.
- Alcance acotado al módulo OC/presupuesto vinculado; multi-tenant (`tenant_id`); DocumentRoot solo `public/`; secretos fuera del árbol web.
- Fórmulas numéricas: conservar semántica existente de `oc_tax_profile` (IVA 19 % entero legado; retención con redondeo a 2 decimales).
- No eliminar productos `servicio boleta_*` del catálogo.

## Technical Decisions

- Stack brownfield: monolito modular PHP + MySQL multi-tenant; PDO único; operaciones OC filtran por `tenant_id` de sesión.
- Hoy el modo se infiere en `oc_tax_profile.php` / espejo JS; no hay columna de perfil en `purchase_orders` — hay que persistirlo (migración/campos mínimos según story).
- Reutilizar superficies: `oc_new.php`, `oc_new_legacy.js`, `oc_tax_profile.php`, `OcService.php`, impresión (`OcPrintView` / `oc_print_render.php`).
- Árbol objetivo: Factura → mode `iva`/`exento`; Boleta → mode `retencion` + `tax_percent`.

## Cross-Story Dependencies

- 1.1 introduce elección de tipo + persistencia base; 1.2 añade rama IVA/exenta y totales/impresión factura; 1.3 añade retención default/edición y totales boleta; 1.4 generaliza edición del perfil en cualquier estado.
- Epic 2 (actividad opcional) es independiente en valor de usuario; no bloquea este epic.
