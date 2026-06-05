# Deploy CCM Checkout

1. Hacer backup del directorio actual en producción.
2. Subir carpeta completa `ccm-checkout`.
3. Verificar que no haya error fatal.
4. Limpiar caché de hosting/plugin/CDN.
5. Probar `/pago/` en incógnito.
6. Probar pago dummy o sandbox.

## Cache-busting de assets

Los assets del checkout (`assets/ccmck-checkout.css` y `assets/ccmck-checkout.js`)
se encolan con su versión calculada por **`filemtime()`** en
`CCMCK_Assets::asset_version()`: el parámetro `?ver=` del asset es la fecha de
modificación del archivo en disco. Por eso **cada vez que subes un CSS/JS nuevo,
el `?ver=` cambia y el navegador descarga la versión nueva** automáticamente, sin
tener que tocar `CCMCK_VERSION` (que se mantiene solo como *fallback*).

- Tras desplegar, confirma en `/pago/` que el `<link>`/`<script>` del asset
  muestra `?ver=<número>` (un timestamp Unix).
- ⚠️ Si la URL del asset aparece **sin `?ver=`**, hay una optimización de
  hosting/plugin/CDN que **elimina las query strings** de los estáticos. En ese
  caso el cache-busting por `?ver=` no surte efecto: hay que desactivar esa opción
  ("remove query strings from static resources") o el cambio no se reflejará.
