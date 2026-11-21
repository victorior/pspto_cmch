Encoding pantallas OC (N, tildes, guiones)
============================================

Antes de commit o despliegue, ejecutar desde la raiz del proyecto:

  python database/fix_oc_encoding.py
  python database/check_oc_encoding.py

check_oc_encoding.py debe terminar con "OK" (exit 0).
Si falla, corregir y volver a ejecutar fix + check.

Archivos revisados: public/oc/**/*.php, public/oc/**/*.js, claves t_es en src/helpers.php.
