# Deploy — nanosoft.cl/budget (multi-tenant)

## Archivos

| Archivo | Destino |
|---------|---------|
| `budget-app.zip` | Extraer en el sitio; carpeta raíz = **`budget`** |
| `016_purchase_orders_tax_profile.sql` | phpMyAdmin (solo al actualizar con perfil tributario) |
| `cna14865_cmchbudgetOC.sql` | Importar en MySQL (**instalación nueva** solamente) |
| `budget-secrets.local.php` | `/home2/cna14865/config/budget-secrets.local.php` |

## Actualización (código ya en hosting)

1. En cPanel File Manager (o FTP): respaldo rápido de `/home2/cna14865/public_html/budget/`.
2. Extraer `budget-app.zip` sobre `public_html/` de modo que quede  
   `/home2/cna14865/public_html/budget/public/index.php`  
   (sobrescribe archivos; **no** toques `budget-secrets.local.php` fuera de `public_html`).
3. En phpMyAdmin, BD `cna14865_cmchbudgetOC`, ejecutar  
   `016_purchase_orders_tax_profile.sql`  
   (si las columnas `tax_doc_type` / `tax_mode` / `tax_percent` ya existen, omitir).
4. Probar `https://nanosoft.cl/budget/` (barra final) y una OC nueva (Factura/Boleta).
5. Carga masiva de actividades: Admin → tenant → import CSV (`budget_activities_import.php`); solo super admin.

## Instalación nueva

1. Crear BD `cna14865_cmchbudgetOC` y usuario `cna14865_cmchbudgetOC_usr` (si el hosting no lo hizo).
2. Importar `cna14865_cmchbudgetOC.sql`, luego `016_purchase_orders_tax_profile.sql` si el dump no lo trae.
3. Subir secrets a `/home2/cna14865/config/budget-secrets.local.php` (permisos 640).
4. Extraer el zip de modo que exista `…/budget/public/index.php`  
   (ruta típica: `/home2/cna14865/public_html/budget/`).
5. PHP **8.1+** (obligatorio).
6. Google OAuth ya debe incluir la redirect (ver abajo).
7. Probar `https://nanosoft.cl/budget/` (con barra final).
8. Carga masiva de actividades: Admin → tenant → import CSV (`budget_activities_import.php`); solo super admin.

**Importante:** el `.htaccess` de la raíz de `budget/` hace *rewrite interno* a `public/` (no redirect 302).  
Si ves una URL tipo `/home2/cna14865/public_html/budget/public/`, el `.htaccess` del servidor es el viejo: vuelve a subir el del zip.

## Google OAuth

**Redirect URI (obligatorio actualizar en Google Console):**
```
https://nanosoft.cl/budget/gauth/callback.php
```

> No usar `/auth/…`: el hosting suele devolver **403 Forbidden** (ModSecurity).

**Origin:**
```
https://nanosoft.cl
```

## Config embebida en el zip

`budget/config/config.php` (desde plantilla producción):

- `base_url` = `/budget`
- `public_url` = `https://nanosoft.cl/budget`
- `secrets_path` = `/home2/cna14865/config/budget-secrets.local.php`

## Nota de nombre

La carpeta de despliegue se llama **`budget`** (no `cmchbudget`) porque la app es **multi-tenant**; CMCH es un tenant, no el nombre del producto en URL.
