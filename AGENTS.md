<!-- bmad:context -->
<!-- Verified 2026-11-20 against f0ea12b. Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## pspto_cmch / BudgetOC (budjetoc)

App PHP 8 + MySQL unificada (presupuesto + OC + admin multi-tenant). Codigo en `budjetoc/`. Planificacion BMAD en `_bmad-output/`. Conocimiento de producto en `docs/`.

## Policy

- Nunca subir secretos (BD, OAuth, `app_secret`) a git ni dentro de `public_html`; usar `secrets_path` fuera del DocumentRoot.
- Nunca apuntar el DocumentRoot a la raiz del repo; solo a `budjetoc/public` (en hosting: carpeta `public` de cmchbudget).
- En `env=local` la BD debe llamarse `budjetoc`. En produccion el nombre lo define secrets/config (p. ej. `nanodata_cmchbudgetOC`).
- No ejecutar `setup_unified.py --reset` ni `reset_operational_data.php` en produccion.

## Where things are

- App: `budjetoc/` — `public/` (web), `src/`, `config/`, `database/`
- Deploy nanodata: `_bmad-output/specs/spec-nanodata-cmchbudget/SPEC.md` y `_bmad-output/deploy/`
- Arquitectura: `_bmad-output/planning-artifacts/architecture-spine-budjetoc.md`
- Instalacion legada: `budjetoc/INSTALACION.txt` (complementa, no reemplaza, la SPEC de deploy)

## Running and verifying

- Local XAMPP: MySQL suele estar en puerto **3307** (`D:\xampp\mysql\bin\my.ini`); `config.php` debe llevar `db.port`.
- Junction web: `D:\xampp\htdocs\budjetoc` → este repo `budjetoc/` (Apache no sigue `.lnk`).
- Migraciones: `py budjetoc/database/apply_all_migrations.py` con `pymysql`; verificar con `py budjetoc/database/verify_migrations.py`.
- PHP: 8.x con `pdo_mysql` y `mbstring`.

## Conventions that differ from defaults

- Bootstrap carga `config/config.php` y luego fusiona `secrets_path` o env `BUDJETOC_SECRETS` con `array_replace_recursive`.
- Sesiones por portal usan prefijo `budjetoc_*` (`Auth.php`); no mezclar con cookies de otras apps en el mismo host sin cuidado de path.
- Login produccion: Google OAuth; el email debe existir en `users` antes del primer login.

## Known pitfalls

- Si aparece PDO 2002 a 127.0.0.1: comprobar puerto 3307 vs 3306 y que MySQL este escuchando.
- Hardcode antiguo exigia siempre `db.name=budjetoc`; en produccion ya no — no reintroducir ese hardcode global.

<!-- /bmad:context -->
