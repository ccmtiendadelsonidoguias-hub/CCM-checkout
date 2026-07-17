# Spec: Resumen compacto en móvil antes del botón (estilo Shopify)

**Fecha:** 2026-06-12
**Plugin:** ccm-checkout (mu-plugin)
**Estado:** Aprobado (diseño)

## Contexto

En móvil el checkout ya tiene arriba una barra plegable "Resumen del pedido"
(`.mobile-summary-toggle` → despliega `.sidebar-inner`; el `.checkout-sidebar` usa
`order:-1` para ir arriba). Pero junto al botón **"Realizar el pedido"** no hay ningún
resumen.

Shopify (referencia verificada en vivo por MCP, newconcept.co móvil) muestra, **antes del
botón de pago**, una fila compacta: miniatura + "Total / N artículos" + monto + chevron que
**despliega el detalle** (ítems + subtotal/envío/total). El usuario quiere replicar eso.

Decisiones de brainstorming (2026-06-12):
- **Mantener la barra de arriba Y añadir** el resumen compacto antes del botón (como Shopify
  tiene ambos).
- **Formato compacto plegable**: barra (miniatura + Total/N + monto + chevron) que despliega
  el desglose (ítems + subtotal/envío/total).

## Comportamiento esperado

### Markup (`templates/checkout/payment.php`)

Render server-side desde `WC()->cart`, insertado **antes del botón**, en la posición del hook
`woocommerce_review_order_before_submit` (junto a los términos, dentro de `.place-order`).
Solo se renderiza si el carrito no está vacío.

- **Barra colapsada** `.ccmck-mos-bar` (`<button type="button">`):
  - Miniatura del primer producto vía `$product->get_image( 'woocommerce_thumbnail' )`.
  - Etiqueta "Total" + "N artículo(s)" (`WC()->cart->get_cart_contents_count()`, con `_n()`).
  - Total: `WC()->cart->get_total()`.
  - Chevron SVG.
  - `aria-expanded="false"`, `aria-controls` al panel de detalle.
- **Detalle** `.ccmck-mos-details` (oculto por defecto):
  - Lista de ítems: miniatura + nombre + "× cantidad" + total de línea
    (`WC()->cart->get_product_subtotal( $product, $qty )`).
  - Subtotal (`WC()->cart->get_cart_subtotal()`), Envío y Total
    (`WC()->cart->get_total()`). El Envío replica el criterio del sidebar
    (`review-order.php`): "¡GRATIS!" cuando el coste es 0; si no, el importe.

### Datos siempre frescos (sin sync JS)

El bloque vive dentro de `#payment`, que WooCommerce **re-renderiza en cada refresco AJAX**
(`update_checkout`) con totales actualizados → el resumen se regenera solo, sin sincronización
JS. **Verificación obligatoria por MCP** de que `#payment` (y por tanto el bloque) se
re-renderiza al cambiar cantidad/dirección/envío. Si no fuera así, respaldo: en
`updated_checkout`, JS copia el total del sidebar a `.ccmck-mos-total` (mínimo). El estado
plegado/desplegado se reinicia (colapsado) tras cada re-render — aceptable.

### JS (`assets/ccmck-checkout.js`)

Solo el toggle, delegado (sobrevive al re-render de `#payment`):
- Click en `.ccmck-mos-bar` → `slideToggle` de `.ccmck-mos-details`, alterna clase `.open`
  (rota el chevron por CSS) y actualiza `aria-expanded`.

### CSS (`assets/ccmck-checkout.css`)

- Tema **claro** (combina con la página blanca): tarjeta con borde redondeado, barra con
  fondo gris muy claro, ítems con divisores, total destacado.
- **Solo móvil**: `display:none` en `@media (min-width:961px)`; visible en `≤960px`.
- La barra plegable superior (`.mobile-summary-toggle`) se mantiene intacta.

## Alcance / no-alcance

- Archivos: `templates/checkout/payment.php`, `assets/ccmck-checkout.js`,
  `assets/ccmck-checkout.css`.
- No se toca la barra/resumen del sidebar ni la lógica de pago/envío.
- El resumen de abajo es **solo lectura** (sin controles de cantidad; esos siguen en el
  sidebar/barra superior).

## Verificación

Sin tests JS en el repo → verificación **en vivo por chrome-devtools MCP** (viewport móvil
390×844) sobre `dev.dev.ccmtiendadelsonido.com/pago/`:
1. El resumen aparece antes del botón "Realizar el pedido"; oculto en desktop (≥961px).
2. Barra colapsada: miniatura real + "Total / N artículos" + total correcto (= sidebar).
3. Toca → despliega ítems + subtotal/envío/total; vuelve a tocar → colapsa; chevron rota.
4. Cambiar cantidad (+/−) → el total del bloque se actualiza (confirma el re-render AJAX).
Ver [[deploy-dev-server]].
