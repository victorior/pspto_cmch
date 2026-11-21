# Deploy — nanodata.cl/cmchbudget

## Archivos generados

| Archivo | Destino |
|---------|---------|
| `cmchbudget-app.zip` | Extraer bajo el sitio (ver abajo) |
| `nanodata_cmchbudgetOC.sql` | Importar en MySQL (phpMyAdmin / CLI) |
| `cmchbudget-secrets.local.php` | `/home/nanodata/config/cmchbudget-secrets.local.php` |

## Pasos

1. Crear BD `nanodata_cmchbudgetOC` y usuario `nanodata_cmchbudgetOC_usr` (si el hosting no lo hizo).
2. Importar `nanodata_cmchbudgetOC.sql`.
3. Subir `cmchbudget-secrets.local.php` a `/home/nanodata/config/` (permisos 640, dueño del usuario del sitio).
4. Extraer el zip de forma que exista `.../cmchbudget/public/index.php`.
5. En el zip, `config/config.php` ya es la plantilla de produccion (apunta a `secrets_path`).
6. DocumentRoot / mapeo: carpeta **`public`** debe ser la raiz web de `/cmchbudget`.
7. Registrar redirect Google: ver `docs/google-oauth-nanodata.md`.
8. Probar `https://nanodata.cl/cmchbudget/`.

## Google OAuth (URI)

```
https://nanodata.cl/cmchbudget/auth/google/callback.php
```

Origin:

```
https://nanodata.cl
```
