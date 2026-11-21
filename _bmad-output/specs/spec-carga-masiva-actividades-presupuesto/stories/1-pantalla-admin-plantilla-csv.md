---
title: 'Pantalla admin + plantilla CSV'
type: 'feature'
created: '2026-11-21'
status: 'done'
route: 'oneshot'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/plantilla-import.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** El super admin no tiene en el panel admin una entrada por tenant ni una plantilla CSV descargable para preparar la carga masiva de actividades de Presupuesto (CAP-1 / CAP-2).

**Approach:** Añadir página admin solo-superadmin con selector de tenant y link permanente que descarga la plantilla CSV (UTF-8 con BOM, columnas de `plantilla-import.md`). La validación y el alta quedan para historias 2–3; esta historia no importa filas.

## Boundaries & Constraints

**Always:** Solo `Auth`/`AdminAccess` superadmin; scope visual/tenant vía `tenant_id`; plantilla UTF-8 con BOM; separador `;`; link visible en la pantalla.

**Never:** Abrir a `tenant_admin`/encargados; portal `budget/`; `.xlsx`; lógica de validación/insert (CAP-4/CAP-3); tocar `AdminUserCsvImportService` / import de usuarios.

</frozen-after-approval>

## Implementation Notes

- Nav label: «Carga actividades»; active key `budget_activities_import`.
- Tenant: `?tenant_id=` + lista de tenants activos (patrón `edit_permissions.php`); aviso si `tenant_id` inválido.
- Servicio nuevo `AdminBudgetActivityCsvImportService::sampleCsvContent()` con BOM + CRLF + encabezados + 2 filas ejemplo (División y Coordinación, con tilde/ñ).
- Página: `budjetoc/public/admin/budget_activities_import.php` con `?sample_csv=1` (Cache-Control: no-store).
- CTA en `admin/index.php` Acciones rápidas.
- Bootstrap: require del servicio en rama admin.
- Post-review patches: botón Aplicar tenant sin JS; escape `\r`; no echo CSV si headers_sent.

## Review Triage Log

- Cache-Control faltante — **high** → patched (`no-store`).
- Select tenant solo onchange — **medium** → patched (botón submit).
- Sin CTA en index — **medium** → patched.
- Columnas no documentadas en pantalla — **medium** → patched (resumen en UI).
- Plantilla no usa catálogos del tenant — **defer** (deferred-work).
- escapeCsvCell sin `\r` — **medium** → patched.
- Solo LF en CSV — **low** → patched (CRLF).
- tenant_id inválido silencioso — **medium** → patched (aviso).
- headers_sent + echo — **medium** → patched (500).
- Sin test headers vs plantilla-import — **defer**.
- Nav sin tenant_id — **defer**.
- Em dash en ejemplos — **low** → patched (ASCII en observations; tilde/ñ en nombres de prueba).
