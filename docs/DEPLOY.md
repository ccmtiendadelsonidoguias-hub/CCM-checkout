# Deploy CCM Checkout

1. Hacer backup del directorio actual en producción.
2. Subir carpeta completa `ccm-checkout`.
3. **Asegurar el loader en la raíz de `mu-plugins`** (ver sección siguiente).
4. Verificar que no haya error fatal.
5. Limpiar caché de hosting/plugin/CDN.
6. Probar `/pago/` en incógnito.
7. Probar pago dummy o sandbox.

## Loader MU-plugin (OBLIGATORIO)

WordPress **no** auto-carga MU-plugins ubicados en una subcarpeta. Por eso el
plugin solo arranca si existe el stub en la **raíz** de `mu-plugins`:

```
wp-content/mu-plugins/ccm-checkout-loader.php   ← stub (hace require del bootstrap)
wp-content/mu-plugins/ccm-checkout/             ← este repo
```

La fuente de verdad versionada del stub es **`ccm-checkout-loader.php`** en la
raíz de este repo. Al desplegar (o en un clone limpio), **cópialo** a
`wp-content/mu-plugins/ccm-checkout-loader.php`. Si falta, el checkout
personalizado no se activa (y el sitio cae al checkout estándar de WooCommerce).

Mantén el `Version:` del loader sincronizado con `CCMCK_VERSION`
(`ccm-checkout.php`).

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
