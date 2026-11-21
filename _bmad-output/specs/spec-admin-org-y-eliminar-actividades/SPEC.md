---
id: SPEC-admin-org-y-eliminar-actividades
companions:
  - brownfield.md
  - ../../planning-artifacts/architecture-spine-budjetoc.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Admin org (CC / divisiones / coordinaciones) y eliminar actividades propias

## Why

**Pain to solve:** el catálogo orgánico del tenant (centros de costo, divisiones, coordinaciones) hoy solo permite altas en admin; no hay forma segura de corregir o retirar entidades, y el motor de BD no expresa la regla de negocio “no borrar si hay actividades”. A la vez, los encargados de división/coordinación no pueden eliminar actividades de Presupuesto propias aunque el año esté editable, lo que bloquea limpieza operativa y el prerrequisito para borrar entidades orgánicas. El catálogo orgánico es compartido en atribuciones entre todos los tenants. Trabajo en rama `feature/admin-org-y-eliminar-actividades` sobre budjetoc (PHP 8 + MySQL multi-tenant).

## Capabilities

- **CAP-1**
  - **intent:** El super admin crea, edita y elimina centros de costo, divisiones y coordinaciones, manteniendo las mismas atribuciones en todos los tenants.
  - **success:** Tras un alta, rename o delete válido, el conjunto de CC/divisiones/coordinaciones queda alineado en todos los tenants; un no–super-admin no alcanza esas mutaciones.

- **CAP-2**
  - **intent:** El sistema impide eliminar un centro de costo, división o coordinación mientras existan actividades de Presupuesto asociadas; el operador debe eliminar esas actividades antes.
  - **success:** Con actividades asociadas (en el tenant donde se evalúa), el delete falla con mensaje claro y la entidad permanece; sin asociaciones que bloqueen, el delete completa y la entidad deja de figurar en el catálogo de todos los tenants.

- **CAP-3**
  - **intent:** El encargado de división o de coordinación elimina sus propias actividades de Presupuesto cuando el permiso de edición de ese año está activado para su unidad.
  - **success:** Con permiso de edición del año activo y actividad propia sin imputaciones OC, la actividad (cabecera y detalle) desaparece; sin permiso de año, si no es propia, o si hay imputaciones OC, el delete se rechaza.

- **CAP-4**
  - **intent:** El super admin elimina cualquier actividad de Presupuesto del tenant de trabajo, sin restricciones de ownership ni de permiso de edición anual.
  - **success:** Como super admin, puede borrar una actividad ajena o de año en solo lectura para encargados; el único bloqueo aplicable es el de imputaciones OC (igual que CAP-3).

## Constraints

- Actor de CAP-1/CAP-2/CAP-4: solo super admin (`super_admins` / sesión admin); no encargados ni `tenant_admin` para CRUD de CC/divisiones/coordinaciones.
- Paridad multi-tenant del catálogo orgánico: todos los tenants tienen las mismas atribuciones; edit/delete/alta no deben dejar un tenant desalineado.
- Delete de entidad orgánica/CC: bloqueo duro si hay `budget_headers` asociadas (definición en Assumptions); sin cascade que deje referencias huérfanas semánticas; sin soft-delete.
- Delete de actividad (CAP-3 y CAP-4): bloqueado si hay imputaciones OC sobre líneas/allocations.
- Delete de actividad por encargado (CAP-3): solo propias; gated por la misma fuente de permiso de edición anual que `canEditActivityHeader` / `tenant_department_edit_policy`.
- Super admin (CAP-4 y catálogo): sin restricciones de ownership, permiso de año ni unidad; operaciones de datos de actividades siguen acotadas al `tenant_id` del contexto; el catálogo orgánico se mantiene en paridad entre tenants.
- Stack y despliegue: budjetoc PHP 8 + MySQL; DocumentRoot `public/`; secretos fuera del árbol web; alinear a architecture spine (companion adoptado).
- Implementación en worktree/rama `feature/admin-org-y-eliminar-actividades`.

## Non-goals

- Carga o borrado masivo de entidades orgánicas o de actividades.
- Rediseñar el modelo dual `departments` vs `cost_centers`, ni el catálogo de nombres `activities`.
- Dar a `tenant_admin` el CRUD del catálogo maestro orgánico.
- Sustituir o ampliar la UI de permisos de edición anuales más allá de consumirla en CAP-3.
- Soft-delete o archivado de entidades/actividades.
- Permitir borrar actividades (o líneas) que ya tienen imputación OC.

## Success signal

Un super admin renombra o elimina un CC/división/coordinación y el cambio queda reflejado en todos los tenants; si hay actividades asociadas, el borrado de entidad se bloquea hasta eliminarlas (el SA puede borrar cualquier actividad sin gates de encargado, salvo OC). Un encargado, con el año editable, elimina una actividad propia sin OC; con año en solo lectura, actividad ajena, o con OC, no puede.

## Assumptions

- “Actividades” = instancias `budget_headers` (+ `budget_details`), no filas del catálogo `activities`.
- Asociación para el guard de CAP-2: CC → `budget_headers.cost_center_id`; división → headers con `division_id` o `created_by` = esa división; coordinación → headers con `department_id` o `created_by` = esa coordinación.
- “Propia” (CAP-3) = `created_by` = `departments.id` de la unidad del encargado, con fallback legacy `department_id` como en `BudgetService::filterRowsCreatedByDepartment`.
- Permiso de edición del año = misma política que hoy usa el portal Presupuesto para editar.
- Paridad de catálogo = mismo conjunto de nombres/entidades orgánicas en todos los tenants (extiende el propagate de altas actual a edit/delete).
- “Sin restricciones” del SA no incluye saltarse el bloqueo por imputaciones OC ni cruzar datos de actividades entre tenants fuera del contexto elegido.
