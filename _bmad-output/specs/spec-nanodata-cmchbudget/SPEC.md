---
title: SPEC — Deploy BudgetOC a nanodata.cl/cmchbudget
status: final
updated: 2026-11-20
slug: nanodata-cmchbudget
---

# SPEC — nanodata cmchbudget

## Why

Publicar BudgetOC (budjetoc) en hosting nanodata con secretos fuera de `public_html`, BD dedicada, login Google, y un paquete zip+SQL reproducible; documentar el sistema bajo BMAD.

## Capabilities

- **CAP-1** App servida en `https://nanodata.cl/cmchbudget` (DocumentRoot = carpeta `public`).
- **CAP-2** BD `nanodata_cmchbudgetOC` con usuario dedicado; password solo en secrets fuera de web.
- **CAP-3** Archivo `/home/nanodata/config/cmchbudget-secrets.local.php` fusionado al boot.
- **CAP-4** Google OAuth redirect en produccion documentada y lista para Console.
- **CAP-5** Paquete zip de codigo + dump SQL para poblar la BD.
- **CAP-6** Repo git ligado a `https://github.com/victorior/pspto_cmch.git` sin secretos ni dumps.
- **CAP-7** Documentacion BMAD: AGENTS.md, architecture spine, esta SPEC, docs de operacion.

## Constraints

- No incluir passwords/OAuth secrets en el zip publico ni en git.
- Local sigue usando BD `budjetoc` y puerto MySQL XAMPP (3307).
- PHP 8.x, pdo_mysql, mbstring.

## Non-goals

- Migrar `proyecto_oc-iae` al mismo hosting.
- Reescribir la app a otro stack.
- Automatizar CI/CD en el hosting.

## Success signal

- URL publica responde login/hub sin PDO error.
- Secrets solo en path fuera de `public_html`.
- SQL importable crea esquema+datos coherentes.
- Redirect URI Google aceptada y login funciona para emails ya en `users`.
- `_bmad-output` + `AGENTS.md` + `docs/` describen el sistema y el deploy.
