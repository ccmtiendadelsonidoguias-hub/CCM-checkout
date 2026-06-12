# Changelog

Todos los cambios notables de **CCM Checkout** se documentan en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido
- **Skeleton loading (shimmer)**: placeholders con efecto shimmer mientras el checkout carga, que **imitan la estructura real de cada contenedor** (campos con línea de label + barra; resumen como tarjeta de producto + líneas de totales; envío como cards con radio/nombre/precio; pago como filas con radio/nombre/iconos), en vez de bloques planos. En la **carga inicial** el wrapper sale con la clase `ccmck-preload` (server-rendered), así el skeleton se ve desde el primer pintado sin parpadeo, y el JS la retira al estar listo (`window.load` / primer `updated_checkout` / timeout de seguridad de 4 s). En los **refrescos AJAX** (cambiar cantidad, dirección o método de envío) el JS togglea `ccmck-skel` en las regiones dinámicas (resumen, métodos de pago y de envío), reemplazando el overlay blanco + spinner por defecto de WooCommerce. Los campos usan pseudo-elementos (pura CSS); resumen/envío/pago usan *partials* de markup (`.ccmck-skel-tpl`) que rinde `form-checkout.php` dentro del contenedor estable y se muestran por *display-swap* (sobreviven a que WC reemplace el fragmento interno por AJAX). Dos paletas (clara / oscura). En `ccmck-checkout.css` + `ccmck-checkout.js` + `form-checkout.php`.
- **Checkout en secciones**: el formulario se reorganiza en **Contacto / Entrega / Métodos de envío / Pago** (encabezados `<h2>`), manteniendo el layout de 2 columnas. El override `templates/checkout/form-billing.php` parte los campos: `billing_email` → Contacto (con enlace "Iniciar sesión" y checkbox de novedades, ambos visuales); el resto → Entrega.
- **Métodos de envío en la columna principal** como cards (`CCMCK_Shipping`): renderiza los métodos que ya ofrecen las Zonas de Envío de WooCommerce (Coordinadora, Local Pickup) y los mantiene actualizados vía el filtro nativo `woocommerce_update_order_review_fragments`. La selección usa radios `shipping_method[]` nativos (recotización de Coordinadora intacta). El sidebar deja de mostrar el selector y muestra el envío como línea de total.
- **Estado *disabled* de Métodos de envío**: cuando todavía no hay una dirección que WooCommerce pueda cotizar (sin ciudad / dirección incompleta), la sección muestra **cards grises no seleccionables** (sin precio) con la nota *"Ingresa tu dirección para ver el costo"*, en lugar del texto "No hay envíos disponibles". La lista es fija (`Coordinadora`, `Recogida local`) vía `CCMCK_Shipping::placeholder_labels()`, filtrable con `ccmck_shipping_placeholder_labels` — no se leen de las Zonas porque Coordinadora se inyecta dinámicamente al cotizar y la zona trae métodos internos que el cliente no usa. Al completar la dirección, el *fragment* reemplaza las placeholder por las cards reales con precio y radio activo.
- **Recogida local (pickup en tienda)**: opción de envío gratis siempre seleccionable (`CCMCK_Pickup` inyecta la tarifa en `woocommerce_package_rates`, sin depender de Zonas). Al elegirla, los campos de **calle, ciudad y departamento** dejan de ser obligatorios (filtro `woocommerce_checkout_fields` con `required=false` + JS que quita `validate-required`); `billing_postcode` se mantiene obligatorio porque en esta tienda está rotulado "Cédula / NIT". La sección de envío pasa a **render mixto**: cards reales seleccionables (Recogida local siempre; Coordinadora cuando hay dirección) + placeholders disabled solo de los métodos de la lista fija aún sin tarifa real.
- Sección **FAQ** (acordeón) en el sidebar, editable desde *Ajustes → Checkout CCM* (`CCMCK_Faq`).
- Resumen del pedido como **tarjetas**: miniatura del producto + badge de cantidad + precio unitario ("c/u"); totales como *summary-lines*.
- **Cache-busting** automático de los assets del checkout vía `filemtime()` (`CCMCK_Assets::asset_version()`): cada cambio en `ccmck-checkout.css`/`.js` actualiza el `?ver=` y rompe caché del navegador. `CCMCK_VERSION` se mantiene como *fallback* si el archivo no existe.

### Cambiado
- **Errores de campo inline (estilo Shopify)**: al pulsar "Finalizar pedido" con campos obligatorios vacíos, ya **no** aparece el banner rojo arriba del formulario. En su lugar, cada campo se marca con **borde rojo + mensaje debajo** ("Ingresa …" / "Selecciona …"). La validación es propia (`ccmck-checkout.js`, captura del `submit` en `document` antes que WooCommerce) y se apoya en la clase `.validate-required`, por lo que **respeta la recogida local** automáticamente. El error de un campo se limpia en cuanto el usuario lo corrige.
- **Errores server-side de WooCommerce inline por campo (estilo Shopify)**: los errores que devuelve el servidor en el evento `checkout_error` (formato de email/teléfono/documento, "X es un campo requerido", etc.) ya **no** se muestran como un banner agrupado. `ccmckMapServerErrors` reparte cada error a su campo —reconociendo el nombre del campo en el `<strong>`/texto del mensaje, con una tabla de aliases (`correo`→email, `población/ciudad`→city, `documento`→nº documento, …)— y lo pinta **inline con borde rojo** reusando el mismo estilo que la validación cliente. Solo los errores **no asociables a un campo** (fallo de pasarela, genéricos) quedan en un **aviso compacto junto al botón Pagar**; si todos se mapean, no aparece ningún banner.

### Corregido
- **P1** — Campos del formulario con el look del mockup sobre el markup real de WooCommerce (`.form-row`/`.input-text`).
- **P2** — "Facturación" a ancho completo de la columna principal (antes flotaba a media columna).
- **P3** — Botón "Realizar el pedido" en rojo de marca (Elementor lo pisaba por especificidad).
- **P4** — Oculto el bloque `wp-block-woocommerce-cart` colado sobre el checkout (solo desktop).
- **P5** — Neutralizados los fondos morados por defecto de WooCommerce en `#payment` y `.payment_box`.
- **P6** — Labels `screen-reader-text` ocultos que desalineaban las filas de la grilla.
- **P7** — Filas del formulario compactas (los `::before/::after` de clearfix de WC inflaban ~36 px por fila al estar en `display:flex`).
- **P8** — Tarjeta del resumen a ancho completo (tabla `shop_table` → block/flex, conservando el fragmento AJAX).
- Activada la clase `CCMCK_Faq` en el bootstrap (estaba `require`-ida pero sin `::init()`).

## [1.0.1] - 2026-06-05

### Cambiado
- Activación en producción (`dev.ccmtiendadelsonido.com/pago/`): reemplazo de CheckoutWC por el mu-plugin propio.

## [1.0.0] - 2026-06-04

### Añadido
- mu-plugin `ccm-checkout`: override del checkout *clásico* de WooCommerce (Enfoque A) vía `woocommerce_locate_template`.
- Campos de documento colombiano (`billing_document_type` / `billing_document_number`) y liberación de `billing_postcode`.
- AJAX nativo de cantidades en el sidebar (`CCMCK_Cart_Ajax`).
- Panel de ajustes (`CCMCK_Settings`), branding por variables CSS (`CCMCK_Branding`), ordenamiento/filtrado de pasarelas (`CCMCK_Payments`).
- Plantillas: `form-checkout`, `review-order`, `payment`, `thankyou`.
- Suite de tests PHPUnit (Document, Settings, Payments, Thankyou).
