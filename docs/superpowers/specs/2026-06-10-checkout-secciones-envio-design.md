# Spec: Checkout en secciones + métodos de envío en columna principal

**Fecha:** 2026-06-10
**Plugin:** ccm-checkout (mu-plugin)
**Estado:** Aprobado (diseño)

## Contexto

El checkout de ccmck (`dev.ccmtiendadelsonido.com/pago/`) hoy renderiza el formulario en
dos columnas internas (`col-1` billing | `col-2` shipping) **sin encabezados de sección**, y
los **métodos de envío sólo aparecen en el sidebar** (dentro de la tabla de totales del
order-review). El usuario quiere replicar el orden por secciones del checkout estilo Shopify
(ver capturas): **Contacto → Entrega → Métodos de envío → Pago**, con sus encabezados, y mover
la selección de método de envío al cuerpo principal como cards.

Decisiones tomadas en brainstorming (2026-06-10):
- **Layout:** se mantienen las 2 columnas (form izquierda + sidebar oscuro del resumen, ya
  pulido). Sólo se reorganiza el formulario izquierdo.
- **Cuenta/Login:** sólo visual por ahora. Se muestra el enlace "Iniciar sesión" (apunta a Mi
  Cuenta) y el checkbox "Enviarme novedades y ofertas"; NO hay login ni creación de cuenta en
  el checkout todavía.
- **Envío:** Coordinadora (plugin `coordinadora` v1.1.32) y Local Pickup **ya están
  habilitados** en las Zonas de Envío de WooCommerce. ccmck **no integra** transportadoras;
  sólo **renderiza** los métodos que WooCommerce ya ofrece.
- **Enfoque de render de envío:** A — fragment nativo de WooCommerce (ver §3).

## Resultado esperado

Columna principal con flujo vertical de secciones, cada una con `<h2>`:

```
Contacto      → Email + [Iniciar sesión] + ☑ novedades
Entrega       → país, nombre, apellidos, tipo/nº doc, dirección, casa/apto,
                depto, ciudad, c.postal, teléfono + ☐ guardar info
Métodos de    → cards seleccionables (Coordinadora, Recoger en tienda, …)
  envío
Pago          → nota "transacciones seguras" + gateways + botón Pagar ahora (sin cambios)
```

El sidebar oscuro del resumen se mantiene; sólo deja de mostrar el selector de envío
(que se mueve al cuerpo) y conserva el costo de envío como línea de total.

## Arquitectura

### 1. Reestructuración de la columna principal
**Archivo:** `templates/checkout/form-checkout.php` (editado)

Se reemplaza el bloque `col2-set` (billing | shipping lado a lado) por secciones verticales
`<section class="ccmck-section …">` dentro de `.checkout-main`:

1. `do_action( 'woocommerce_checkout_billing' )` → ahora emite **dos** secciones (Contacto +
   Entrega) gracias al override de `form-billing.php` (§2).
2. `do_action( 'woocommerce_checkout_shipping' )` → se conserva (checkbox "enviar a otra
   dirección" + campos de envío alternativos); queda dentro/após Entrega, sin encabezado propio.
3. **Nueva sección "Métodos de envío"** con el contenedor `#ccmck_shipping_methods` (§3),
   ubicada entre los datos del cliente y el pago.
4. **Sección "Pago"**: el actual `<h3 id="order_review_heading">Pago</h3>` pasa a `<h2>` con la
   nota "Todas las transacciones son seguras y están encriptadas"; `woocommerce_checkout_payment()`
   se mantiene sin cambios.

Se preservan todos los hooks/IDs que `checkout.js` y el procesamiento de pedidos requieren
(`#customer_details`, `woocommerce_before/after_checkout_form`, el `remove_action` del pago, etc.).

### 2. Partición de campos Contacto vs Entrega
**Archivo:** `templates/checkout/form-billing.php` (nuevo override)

Override del template de billing de WooCommerce. En lugar del loop único, renderiza:

- **`<section class="ccmck-section ccmck-contacto">`**: encabezado con `<h2>Contacto</h2>` +
  enlace "Iniciar sesión" (`wc_get_page_permalink('myaccount')`). Dentro: el campo
  `billing_email` (vía `woocommerce_form_field()`), seguido del checkbox visual
  "Enviarme novedades y ofertas por correo electrónico".
- **`<section class="ccmck-section ccmck-entrega">`**: `<h2>Entrega</h2>` + loop sobre el
  resto de campos billing (todos menos `billing_email`), respetando el orden de prioridad que
  ya fija `CCMCK_Document::finalize_fields()` y las clases de columna (`form-row-first/last/wide`).
  Cierra con el checkbox visual "Guardar mi información…".

Los campos provienen de `$checkout->get_checkout_fields('billing')`; valores con
`$checkout->get_value()`. Los checkboxes visuales son markup propio (no son campos WC; no se
persisten en esta fase).

### 3. Métodos de envío en la columna principal (Enfoque A — fragment nativo)
**Archivo:** `includes/class-ccmck-shipping.php` (nuevo)

- `init()`: registra el filtro `woocommerce_update_order_review_fragments`.
- `render( $echo = true )`: produce el HTML de los paquetes de envío como cards. Itera
  `WC()->shipping()->get_packages()`; para cada paquete y sus `rates`, pinta un radio
  `input[name="shipping_method[$index]"]` con `value="$rate_id"`, label y costo
  (`wc_price()`), marcando el elegido (`WC()->session->get('chosen_shipping_methods')`).
  Si un paquete no tiene rates, muestra un aviso ("No hay envíos disponibles para tu dirección").
  Reutiliza la lógica de `wc_cart_totals_shipping_html()` como referencia, pero con markup de card.
- `fragments( $fragments )`: añade `$fragments['#ccmck_shipping_methods'] = <HTML del contenedor>`
  para que el AJAX `update_order_review` de WooCommerce lo refresque automáticamente.

En `form-checkout.php`, la sección "Métodos de envío" hace el **render inicial** server-side
llamando a `CCMCK_Shipping::render()` dentro de `<div id="ccmck_shipping_methods">…</div>`.

**Por qué funciona sin tocar Coordinadora:** `checkout.js` delega a nivel `document` el evento
`change` de `input[name^=shipping_method]` → dispara `update_checkout` → WooCommerce recalcula
los paquetes (incluida la cotización por API de Coordinadora) y devuelve los fragments; el
nuestro repinta las cards con el método elegido y el costo actualizado.

### 4. Sidebar: envío sólo como total
**Archivo:** `templates/checkout/review-order.php` (editado)

Se elimina el **selector** de envío del tfoot (el bloque `woocommerce_review_order_…shipping` /
`wc_cart_totals_shipping_html()` que pinta los radios) para no duplicar la selección. Se
conserva una **línea de total "Envío: $X"** mostrando el costo del método elegido
(`WC()->cart->get_cart_shipping_total()` o equivalente), de modo que el resumen siga cuadrando.
El total del pedido ya incluye el envío y no cambia.

### 5. Estilos
**Archivo:** `assets/ccmck-checkout.css` (editado)

- `.ccmck-section` (separación/aire entre secciones) y `.ccmck-section > h2`
  (encabezados tipo mockup: ~22–28px, peso fuerte, color sidebar).
- Encabezado de Contacto con enlace "Iniciar sesión" alineado a la derecha
  (flex space-between).
- `.ccmck-shipping-methods` + cards de método: mismo lenguaje visual que las cards de pago
  existentes (borde, radio, costo a la derecha, estado seleccionado).
- Checkboxes "novedades" / "guardar info" alineados con los del mockup.
- Responsive: en móvil las secciones se apilan (ya hay reglas base de `.form-row` por columna).

### 6. Carga de la clase
**Archivo:** `ccm-checkout.php` (editado)

Añadir `require` de `includes/class-ccmck-shipping.php` y `CCMCK_Shipping::init()` en el
bootstrap `ccmck_boot`, siguiendo el patrón del resto de clases (`CCMCK_Document`, etc.).

## Archivos

| Acción | Archivo |
|--------|---------|
| Nuevo | `templates/checkout/form-billing.php` |
| Nuevo | `includes/class-ccmck-shipping.php` |
| Editar | `templates/checkout/form-checkout.php` |
| Editar | `templates/checkout/review-order.php` |
| Editar | `ccm-checkout.php` |
| Editar | `assets/ccmck-checkout.css` |
| Sin tocar | `templates/checkout/payment.php`, sidebar/resumen, plugin `coordinadora` |

## Fuera de alcance (YAGNI / fases futuras)
- Login y creación de cuenta funcional dentro del checkout (sólo visual ahora).
- Persistir los checkboxes "novedades" / "guardar info" como meta del pedido.
- Cambiar a layout de 1 columna.
- Cupón, delivery-cards extra y secure-badge del mockup (pendientes previos, no parte de esto).

## Verificación (en vivo, chrome-devtools MCP)
La carpeta local NO sincroniza con el server; se despliega por File Manager (ver memoria
`deploy-dev-server`). Tras desplegar:

1. `/pago/` con carrito → confirmar las 4 secciones con sus `<h2>` en orden Contacto → Entrega →
   Métodos de envío → Pago.
2. Email en Contacto con enlace "Iniciar sesión" y checkbox novedades; resto de campos en Entrega.
3. **Métodos de envío en el cuerpo principal**: aparecen las cards (Coordinadora, Recoger en
   tienda). Seleccionar otra dirección/método → el costo y el total se actualizan vía AJAX
   (Coordinadora cotiza). Verificar que NO hay selector duplicado en el sidebar y que el total
   cuadra con la línea "Envío".
4. Comprobar que el pedido se puede colocar (botón Pagar ahora) sin errores de `shipping_method`.
5. Captura desktop + móvil.

## Notas de despliegue
- CSS auto-busta por `?ver=<filemtime>` (force_version ya activo).
- Los PHP nuevos/editados requieren **purgar OPcache** tras subir por File Manager.
