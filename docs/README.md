# Documentacion BudgetOC (pspto_cmch)

## Indice BMAD

| Artefacto | Ruta |
|-----------|------|
| Instrucciones agentes | [`../AGENTS.md`](../AGENTS.md) |
| Architecture spine | [`../_bmad-output/planning-artifacts/architecture-spine-budjetoc.md`](../_bmad-output/planning-artifacts/architecture-spine-budjetoc.md) |
| SPEC deploy nanodata | [`../_bmad-output/specs/spec-nanodata-cmchbudget/SPEC.md`](../_bmad-output/specs/spec-nanodata-cmchbudget/SPEC.md) |
| Paquete deploy | [`../_bmad-output/deploy/README.md`](../_bmad-output/deploy/README.md) |
| Instalacion tecnica | [`../budjetoc/INSTALACION.txt`](../budjetoc/INSTALACION.txt) |

## Sistemas en este workspace

- **budjetoc** — sistema unificado (presupuesto + OC). Es el que se despliega a nanodata.
- El legado `proyecto_oc-iae` (si existe en otras carpetas) **no** forma parte de este deploy y **no** comparte BD con budjetoc.

## Entornos

| | Local | Produccion |
|---|--------|------------|
| URL | `http://localhost/budjetoc/public/` | `https://nanodata.cl/cmchbudget/` |
| BD | `budjetoc` @ `127.0.0.1:3307` | `nanodata_cmchbudgetOC` |
| Secretos | en `budjetoc/config/config.php` | `/home/nanodata/config/cmchbudget-secrets.local.php` |
