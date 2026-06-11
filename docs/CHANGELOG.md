# Changelog

Todos los cambios notables de **CCM Checkout** se documentan en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido
- **Checkout en secciones**: el formulario se reorganiza en **Contacto / Entrega / Métodos de envío / Pago** (encabezados `<h2>`), manteniendo el layout de 2 columnas. El override `templates/checkout/form-billing.php` parte los campos: `billing_email` → Contacto (con enlace "Iniciar sesión" y checkbox de novedades, ambos visuales); el resto → Entrega.
- **Métodos de envío en la columna principal** como cards (`CCMCK_Shipping`): renderiza los métodos que ya ofrecen las Zonas de Envío de WooCommerce (Coordinadora, Local Pickup) y los mantiene actualizados vía el filtro nativo `woocommerce_update_order_review_fragments`. La selección usa radios `shipping_method[]` nativos (recotización de Coordinadora intacta). El sidebar deja de mostrar el selector y muestra el envío como línea de total.
- **Estado *disabled* de Métodos de envío**: cuando todavía no hay una dirección que WooCommerce pueda cotizar (sin ciudad / dirección incompleta), la sección muestra los métodos reales de las Zonas de Envío en **cards grises no seleccionables** (sin precio) con la nota *"Ingresa tu dirección para ver el costo"*, en lugar del texto "No hay envíos disponibles". Los nombres se leen automáticamente de `WC_Shipping_Zones` (`CCMCK_Shipping::get_zone_method_labels()`); al completar la dirección, el *fragment* las reemplaza por las cards reales con precio y radio activo.
- Sección **FAQ** (acordeón) en el sidebar, editable desde *Ajustes → Checkout CCM* (`CCMCK_Faq`).
- Resumen del pedido como **tarjetas**: miniatura del producto + badge de cantidad + precio unitario ("c/u"); totales como *summary-lines*.
- **Cache-busting** automático de los assets del checkout vía `filemtime()` (`CCMCK_Assets::asset_version()`): cada cambio en `ccmck-checkout.css`/`.js` actualiza el `?ver=` y rompe caché del navegador. `CCMCK_VERSION` se mantiene como *fallback* si el archivo no existe.

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
