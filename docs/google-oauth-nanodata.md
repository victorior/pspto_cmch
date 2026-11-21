# Google OAuth — nanodata.cl/cmchbudget

## URLs a registrar en Google Cloud Console

Tipo: **OAuth 2.0 Client ID** → Web application.

### Authorized JavaScript origins

```
https://nanodata.cl
```

### Authorized redirect URIs

```
https://nanodata.cl/cmchbudget/auth/google/callback.php
```

(Asume DocumentRoot = carpeta `public` montada en `/cmchbudget`. Si el hosting deja `/public` visible en la URL, usa en su lugar `https://nanodata.cl/cmchbudget/public/auth/google/callback.php`.)

### Local (opcional, mismo Client ID)

```
http://localhost/budjetoc/public/auth/google/callback.php
http://127.0.0.1/budjetoc/public/auth/google/callback.php
```

## Config app

En secrets de produccion:

- `google_oauth.enabled` = true
- `client_id` / `client_secret` del Client ID
- `password_login_enabled` = false
- `public_url` = `https://nanodata.cl/cmchbudget`

## Usuarios

El email de Google debe existir previamente en la tabla `users`. En el primer login se vincula `google_id`.
