---
title: 'Verificación local y entrega'
type: 'chore'
created: '2026-11-21'
status: 'in-progress'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'f655aad3f9fa3d3bb0c7776b15b9b86f49331aec'
context:
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/delivery.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-carga-masiva-actividades-presupuesto/stories/3-confirmar-altas-de-actividades.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Las historias 1–3 ya implementan carga masiva, pero falta cerrar `delivery.md`: verificar el flujo en local, publicar en GitHub (`main`) y generar el zip de producción `budget/` para nanosoft.cl/budget.

**Approach:** Correr smokes CAP-4/CAP-3 (+ chequeos locales listados en delivery), alinear `_bmad-output/deploy/` al empaquetado nanosoft, construir `budget-app.zip` sin secretos, y entregar en GitHub: push de la feature y merge a `main` en esta sesión.

## Boundaries & Constraints

**Always:** BD local `budjetoc` / puerto 3307; zip con raíz `budget/`; DocumentRoot `budget/public`; `base_url=/budget`, `public_url=https://nanosoft.cl/budget`; secretos fuera del zip y de git; rama destino GitHub = `main` (minúsculas); no reset de producción; push feature + merge a `main` en la misma sesión de entrega (decisión B).

**Never:** Incluir `secrets_path` / `*-secrets*` / dumps con credenciales en el zip; `setup_unified.py --reset` o `reset_operational_data.php` en prod; reescribir producto CAP-1–3 salvo fixes mínimos hallados en verificación; DocumentRoot en raíz del repo; dejar la feature solo en PR sin merge a `main`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Smoke validate+import | XAMPP/`budjetoc` OK | Ambos scripts exit 0; casos limpio/dup/catálogo cubiertos | Si falla: no push ni zip “verde” |
| Zip sin secretos | Empaquetar desde `budjetoc/` + config prod | `_bmad-output/deploy/budget-app.zip` raíz `budget/`; sin secrets | Abortar si se detecta archivo secreto |
| GitHub entrega | Feature verificada | Feature en `origin/main` tras push + merge (sin force-push) | Si merge bloquea: reportar y HALT |

</frozen-after-approval>

## Code Map

- `budjetoc/database/test_budget_activity_csv_validate.php` — smoke CAP-4 (plantilla BOM, limpio Div/Coord, dup, catálogo, sin INSERT).
- `budjetoc/database/test_budget_activity_csv_import.php` — smoke CAP-3 (INSERT, atribución, mid-batch, secuencia).
- `budjetoc/public/admin/budget_activities_import.php` — plantilla `?sample_csv=1` + gate `requireSuperadminSession` (chequeo manual o assert estático; HTTP diferido).
- `_bmad-output/deploy/README.md` (worktree) — hoy stale nanodata; alinear al README nanosoft de main (`budget-app.zip`, `/budget`).
- `_bmad-output/deploy/` en main (`D:\Cursor_proyectos\pspto_cmch\_bmad-output\deploy\`) — referencia staging/`budget/` + zip; **sin script packager** → empaquetar a mano (copy → Compress-Archive / tar).
- `budjetoc/config/config.php` / secrets — prod en zip apunta `secrets_path` externo; no copiar `budget-secrets.local.php`.
- Git: `origin` → `github.com/victorior/pspto_cmch.git`; default `main`; feature `feature/carga-masiva-actividades-presupuesto`.
- **No tocar:** esquema con reset, portal encargados, AdminUserCsvImportService.

## Tasks & Acceptance

**Execution:**
- [x] `budjetoc/database/test_budget_activity_csv_validate.php` + `…_import.php` -- Ejecutar y registrar resultado -- delivery verificación local.
- [x] `_bmad-output/deploy/README.md` -- Alinear a nanosoft (`budget-app.zip`, `/budget`) -- docs de entrega coherentes.
- [x] `_bmad-output/deploy/budget-app.zip` -- Empaquetar `budget/` desde app feature sin secretos -- artefacto prod.
- [x] GitHub (`main`) -- Push feature + merge a `main` en esta sesión -- constraint delivery.md (decisión B).

**Acceptance Criteria:**
- Given smokes locales, when se corren validate+import, then ambos pasan (exit 0).
- Given el zip generado, when se inspecciona, then raíz es `budget/`, incluye `public/`, y no contiene archivos de secretos.
- Given la entrega GitHub, when termina la historia, then el código de carga masiva está en `origin/main`.

## Implementation Notes

- 2026-11-21: Smokes locales OK — validate 35/35 exit 0; import 38/38 exit 0 (BD `budjetoc` / XAMPP).
- 2026-11-21: Assert estático UI: `requireSuperadminSession` L8 + `sample_csv=1` L36 en `budget_activities_import.php` (HTTP manual diferido).
- 2026-11-21: `_bmad-output/deploy/README.md` alineado a nanosoft.cl/budget (`budget-app.zip`, DocumentRoot `budget/public`).
- 2026-11-21: `config.production.example.php` actualizado a nanosoft (`base_url=/budget`, `public_url=https://nanosoft.cl/budget`, `secrets_path` externo).
- 2026-11-21: Empaquetado manual → `_bmad-output/deploy/budget-app.zip` (raíz `budget/`, 122 entradas, incluye import UI, 0 `*secret*`); zip gitignored.
- 2026-11-21: GitHub — push `feature/carga-masiva-actividades-presupuesto` + merge PR #2 → `origin/main` (`b1bb3c0`).

## Spec Change Log

## Review Triage Log

## Design Notes

- delivery.md dice “Main”; el remoto usa `main` — tratarlos como el mismo destino.
- Huecos HTTP (plantilla / no-superadmin) ya diferidos en historias 1–3; en esta historia: smokes CLI + nota manual en Verification.
- Empaquetado: copiar árbol app a staging temporal `budget/`, config prod sin secretos embebidos, comprimir; no versionar el zip si `.gitignore` lo excluye (sí dejar README actualizado).

## Verification

**Commands:**
- `php budjetoc/database/test_budget_activity_csv_validate.php` -- exit 0 (35 ok, 0 fail)
- `php budjetoc/database/test_budget_activity_csv_import.php` -- exit 0 (38 ok, 0 fail)
- Inspección zip: raíz `budget/`; `budget/public/` presente; `budget_activities_import.php` presente; 0 entradas `*secret*`; config apunta a secrets_path externo

**Manual checks:**
- UI: descarga plantilla UTF-8 BOM; no-superadmin denegado (si sesión disponible) — diferido; gate estático verificado.
