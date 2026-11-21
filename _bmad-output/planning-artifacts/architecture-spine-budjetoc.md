---
title: Architecture Spine — BudgetOC (budjetoc)
status: final
updated: 2026-11-20
altitude: system
mode: fast-path-brownfield
---

# Architecture Spine — budjetoc

Brownfield: ratifica lo que el codigo ya hace. No rediseña el stack.

## Paradigm

**Modular monolith PHP** con un solo esquema MySQL multi-tenant. Tres superficies web (admin / presupuesto / OC) comparten bootstrap, auth y datos.

## Inherited / adopted decisions

### AD-1 — Una base de datos por entorno
- **Binds:** conexion PDO unica via `Db::pdo`.
- **Prevents:** lecturas cruzadas a bases legacy en runtime.
- **Rule:** Local `db.name=budjetoc`. Produccion: nombre en secrets (p. ej. `nanodata_cmchbudgetOC`). Imports legacy solo por scripts CLI + dumps.
- **[ADOPTED]**

### AD-2 — DocumentRoot = `public/`
- **Binds:** exposicion HTTP.
- **Prevents:** servir `config/`, `src/`, `database/` como estaticos.
- **Rule:** `.htaccess` Deny en carpetas sensibles; hosting apunta a `public`.
- **[ADOPTED]**

### AD-3 — Secretos fuera del arbol web
- **Binds:** credenciales de produccion.
- **Prevents:** filtracion por misconfig de DocumentRoot o zip mal armado.
- **Rule:** `secrets_path` o `BUDJETOC_SECRETS` fusiona sobre `config.php` con `array_replace_recursive`.
- **[ADOPTED]** 2026-11-20

### AD-4 — Auth centralizada + OAuth Google en produccion
- **Binds:** login de usuarios.
- **Prevents:** passwords de app como unico vector en prod.
- **Rule:** `google_oauth.enabled=true`, `password_login_enabled=false` en produccion; email debe existir en `users`.
- **[ADOPTED]**

### AD-5 — Tenant isolation en consultas
- **Binds:** datos operativos.
- **Prevents:** filtrado cruzado entre colegios.
- **Rule:** operaciones de presupuesto/OC filtran por `tenant_id` de sesion.
- **[ADOPTED]**

## Seed (estructura actual)

```
budjetoc/
  public/     # admin, budget, oc, auth/google
  src/        # servicios + bootstrap
  config/     # config.php (local) / production example
  database/   # schema, migrations, imports CLI
```

## Deferred

- Separar API HTTP publica para integraciones externas (hoy no aplica al deploy nanodata).
- Contenedorizacion; hosting es cPanel/shared PHP.

## Open questions

- Ninguna bloqueante para deploy a `nanodata.cl/cmchbudget` (DocumentRoot = `public` bajo `/cmchbudget`).
