# Entrega — carga masiva actividades Presupuesto

Load-bearing para bmad-build / implementación. No es el “qué” de producto (está en SPEC.md); es el “cómo sale” al repo y a producción.

## Worktree y documentación

- Feature en **worktree nuevo** (rama aparte del sprint OC ya cerrado).
- Documentar en este folder de spec (ya hecho) y, al implementar, tocar solo lo necesario en código + migraciones si aplica; no inventar docs markdown de producto salvo que el build lo pida.

## Verificación local

- Probar en XAMPP local: BD `budjetoc`, MySQL suele puerto **3307**, junction `D:\xampp\htdocs\budjetoc` → repo `budjetoc/`.
- Casos mínimos: (1) descarga plantilla; (2) carga válida División + Coordinación; (3) rechazo no-superadmin; (4) skip número existente; (5) error de catálogo reportado sin insert parcial corrupto.
- Si hay scripts de test PHP existentes en `budjetoc/database/`, preferir extender el patrón del repo; no exigir framework nuevo.

## GitHub

- Commit con mensaje orientado al porqué (carga masiva super admin).
- Push a **Main** en GitHub cuando la feature esté verificada en local (el invocador humano confirma el momento del push si la política del repo lo requiere; el constraint del pedido es: sí a Main).

## Zip producción (nanosoft.cl/budget)

- Artefacto: `_bmad-output/deploy/` — zip con raíz **`budget/`** (no la raíz del repo).
- DocumentRoot hosting: `budget/public` bajo `/budget`.
- **Nunca** incluir secretos (`secrets_path` / `budget-secrets.local.php`) en el zip ni bajo `public_html`.
- Config de producción: `base_url=/budget`, `public_url=https://nanosoft.cl/budget`.
- Migraciones SQL aditivas al lado del zip si el esquema cambia; no `setup_unified.py --reset` ni `reset_operational_data.php` en producción.
- Referencia: `_bmad-output/deploy/README.md`, `spec-nanosoft-budget`.
