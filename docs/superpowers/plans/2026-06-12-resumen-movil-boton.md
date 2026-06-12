# Resumen compacto móvil antes del botón — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** En móvil, mostrar un resumen compacto plegable (miniatura + Total/N artículos + monto + chevron → despliega ítems + subtotal/envío/total) justo antes del botón "Realizar el pedido", estilo Shopify.

**Architecture:** El resumen se renderiza server-side en `payment.php` desde `WC()->cart`, dentro de `#payment` (que WooCommerce re-renderiza en cada refresco AJAX → totales siempre frescos, sin sync JS). El JS solo pliega/despliega (delegado, sobrevive al re-render). El CSS lo estiliza en tema claro y lo muestra solo en `≤960px`. La barra plegable superior del sidebar no se toca.

**Tech Stack:** PHP (WooCommerce cart API), jQuery, CSS, verificación con chrome-devtools MCP (viewport móvil).

---

## File Structure

- **Modify** `templates/checkout/payment.php` — bloque `.ccmck-mos` (barra colapsada + detalle) renderizado desde `WC()->cart`, insertado tras el hook `woocommerce_review_order_before_submit` (antes del botón).
- **Modify** `assets/ccmck-checkout.css` — estilos del resumen compacto (tema claro), `display:none` en desktop y visible `≤960px`.
- **Modify** `assets/ccmck-checkout.js` — handler de toggle delegado (`.ccmck-mos-bar`).

Datos: se leen de `WC()->cart` (`get_cart()`, `get_cart_contents_count()`, `get_total()`, `get_cart_subtotal()`, `get_cart_shipping_total()`, `get_product_subtotal()`), igual que `review-order.php`. Verificación post-deploy por MCP.

---

### Task 1: Markup del resumen en `payment.php`

**Files:**
- Modify: `templates/checkout/payment.php` (tras la línea `do_action( 'woocommerce_review_order_before_submit' );`, ≈línea 87)

- [ ] **Step 1: Insertar el bloque**

Tras `<?php do_action( 'woocommerce_review_order_before_submit' ); ?>` (línea 87) y antes del bloque del botón (línea 89), insertar:

```php
			<?php
			// Resumen compacto SOLO móvil, antes del botón (estilo Shopify). Vive dentro de
			// #payment → WooCommerce lo re-renderiza en cada refresco AJAX con totales frescos.
			// El JS solo pliega/despliega (ccmck-checkout.js). El CSS lo oculta en desktop.
			if ( WC()->cart && ! WC()->cart->is_empty() ) :
				$ccmck_cart  = WC()->cart->get_cart();
				$ccmck_count = WC()->cart->get_cart_contents_count();
				$ccmck_first = reset( $ccmck_cart );
				?>
				<div class="ccmck-mos">
					<button type="button" class="ccmck-mos-bar" aria-expanded="false" aria-controls="ccmck-mos-details">
						<span class="ccmck-mos-thumb"><?php echo $ccmck_first ? $ccmck_first['data']->get_image( 'woocommerce_thumbnail' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="ccmck-mos-label">
							<b><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></b>
							<small><?php echo esc_html( sprintf( _n( '%d artículo', '%d artículos', $ccmck_count, 'ccm-checkout' ), $ccmck_count ) ); ?></small>
						</span>
						<span class="ccmck-mos-total"><?php echo wp_kses_post( WC()->cart->get_total() ); ?></span>
						<svg class="ccmck-mos-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
					</button>
					<div class="ccmck-mos-details" id="ccmck-mos-details">
						<?php
						foreach ( $ccmck_cart as $ccmck_ci ) :
							$ccmck_p = $ccmck_ci['data'];
							if ( ! $ccmck_p || ! $ccmck_p->exists() || $ccmck_ci['quantity'] <= 0 ) {
								continue;
							}
							?>
							<div class="ccmck-mos-item">
								<span class="ccmck-mos-item-thumb"><?php echo $ccmck_p->get_image( array( 48, 48 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span class="ccmck-mos-item-name"><?php echo esc_html( $ccmck_p->get_name() ); ?> <span class="ccmck-mos-item-qty">&times; <?php echo esc_html( $ccmck_ci['quantity'] ); ?></span></span>
								<span class="ccmck-mos-item-price"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $ccmck_p, $ccmck_ci['quantity'] ) ); ?></span>
							</div>
						<?php endforeach; ?>
						<div class="ccmck-mos-sum"><span><?php esc_html_e( 'Subtotal', 'ccm-checkout' ); ?></span><span><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span></div>
						<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
							<div class="ccmck-mos-sum"><span><?php esc_html_e( 'Envío', 'ccm-checkout' ); ?></span><span><?php echo wp_kses_post( WC()->cart->get_cart_shipping_total() ); ?></span></div>
						<?php endif; ?>
						<div class="ccmck-mos-sum ccmck-mos-grand"><span><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></span><span><?php echo wp_kses_post( WC()->cart->get_total() ); ?></span></div>
					</div>
				</div>
			<?php endif; ?>
```

- [ ] **Step 2: Lint PHP**

Run: `php -l templates/checkout/payment.php`
Expected: `No syntax errors detected in templates/checkout/payment.php`

- [ ] **Step 3: Commit**

```bash
git add templates/checkout/payment.php
git commit -m "feat(checkout): resumen compacto móvil antes del botón (markup)"
```

---

### Task 2: CSS del resumen compacto

**Files:**
- Modify: `assets/ccmck-checkout.css` (añadir al final)

- [ ] **Step 1: Añadir los estilos al final de `assets/ccmck-checkout.css`**

```css
/* ===== Resumen compacto móvil antes del botón (estilo Shopify) =====
   Render server-side en payment.php (#payment → se refresca solo por AJAX).
   Oculto en desktop; visible y plegable en ≤960px. JS solo togglea .open. */
.ccmck .ccmck-mos { display: none; }
@media (max-width: 960px) {
  .ccmck .ccmck-mos {
    display: block;
    border: 1px solid #e6e6e6;
    border-radius: 12px;
    margin: 0 0 18px;
    overflow: hidden;
    background: #fff;
  }
  .ccmck .ccmck-mos-bar {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: #fafafa;
    border: 0;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
  }
  .ccmck .ccmck-mos-bar:hover,
  .ccmck .ccmck-mos-bar:focus,
  .ccmck .ccmck-mos-bar:active { background: #f3f3f3; outline: none; box-shadow: none; }
  .ccmck .ccmck-mos-bar:focus-visible { outline: 2px solid var(--ccmck-accent, #e63946); outline-offset: -2px; }
  .ccmck .ccmck-mos-thumb img {
    width: 44px; height: 44px; border-radius: 8px;
    object-fit: cover; border: 1px solid #eee; display: block;
  }
  .ccmck .ccmck-mos-label { display: flex; flex-direction: column; line-height: 1.2; }
  .ccmck .ccmck-mos-label b { font-size: 15px; color: #111; font-weight: 700; }
  .ccmck .ccmck-mos-label small { font-size: 12px; color: #888; }
  .ccmck .ccmck-mos-total { margin-left: auto; font-size: 16px; font-weight: 800; color: #111; }
  .ccmck .ccmck-mos-caret { width: 18px; height: 18px; color: #888; transition: transform .25s; flex: 0 0 auto; }
  .ccmck .ccmck-mos.open .ccmck-mos-caret { transform: rotate(180deg); }
  .ccmck .ccmck-mos-details { display: none; padding: 6px 16px 14px; border-top: 1px solid #eee; }
  .ccmck .ccmck-mos-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f2f2f2; }
  .ccmck .ccmck-mos-item-thumb img { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid #eee; display: block; }
  .ccmck .ccmck-mos-item-name { font-size: 13px; color: #333; flex: 1; }
  .ccmck .ccmck-mos-item-qty { color: #888; }
  .ccmck .ccmck-mos-item-price { font-size: 13px; font-weight: 700; color: #111; white-space: nowrap; }
  .ccmck .ccmck-mos-sum { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; color: #555; }
  .ccmck .ccmck-mos-grand { padding-top: 12px; margin-top: 4px; font-size: 17px; font-weight: 800; color: #111; border-top: 1px solid #eee; }
}
```

- [ ] **Step 2: Verificar el look por MCP (inyección)**

Con viewport móvil (390×844) en el checkout de dev, inyectar el CSS de arriba en un `<style>` y un markup de prueba `.ccmck-mos` antes de `#place_order` (réplica de la estructura de Task 1, con datos leídos del sidebar). Screenshot colapsado y desplegado. Confirmar: tarjeta clara antes del botón, barra con miniatura + "Total / N artículos" + monto + chevron; al expandir, ítems + Subtotal + Envío + Total.

- [ ] **Step 3: Commit**

```bash
git add assets/ccmck-checkout.css
git commit -m "feat(checkout): estilos del resumen compacto móvil"
```

---

### Task 3: JS del toggle

**Files:**
- Modify: `assets/ccmck-checkout.js` (antes del cierre `} )( jQuery );`)

- [ ] **Step 1: Añadir el handler delegado**

Insertar antes de la última línea `} )( jQuery );`:

```javascript
    /* ------------------------------------------------------------------ */
    /*  Resumen compacto móvil: pliega/despliega el detalle.               */
    /*  Delegado en document → sobrevive al re-render de #payment en cada  */
    /*  updated_checkout (el bloque lo rinde payment.php desde WC()->cart). */
    /* ------------------------------------------------------------------ */
    $( document ).on( 'click', '.ccmck-mos-bar', function () {
        var $mos = $( this ).closest( '.ccmck-mos' );
        var open = $mos.toggleClass( 'open' ).hasClass( 'open' );
        $( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
        $mos.find( '.ccmck-mos-details' ).slideToggle( 180 );
    } );
```

- [ ] **Step 2: Verificar sintaxis**

Run: `node --check assets/ccmck-checkout.js`
Expected: exit 0 (sin salida).

- [ ] **Step 3: Commit**

```bash
git add assets/ccmck-checkout.js
git commit -m "feat(checkout): toggle del resumen compacto móvil"
```

---

### Task 4: Despliegue y verificación final

**Files:** ninguno (deploy + smoke test)

- [ ] **Step 1: Recordar deploy**

Subir por File Manager: `templates/checkout/payment.php`, `assets/ccmck-checkout.css`, `assets/ccmck-checkout.js`. El `.php` **requiere purgar OPcache**; CSS/JS rompen caché solos por `force_version()`.

- [ ] **Step 2: Smoke test en vivo por MCP (viewport móvil 390×844)**

Tras deploy + OPcache:
1. El resumen aparece antes de "Realizar el pedido"; en desktop (≥961px) está oculto.
2. Barra colapsada: miniatura real (del producto) + "Total / N artículos" + total = el del sidebar.
3. Tocar la barra → despliega ítems + Subtotal + Envío + Total; chevron rota; volver a tocar colapsa.
4. **Re-render AJAX:** pulsar "+" en la barra superior / cambiar cantidad → confirmar que el total del bloque inferior se actualiza (valida que `#payment` se re-renderiza con el bloque dentro).

- [ ] **Step 3: Contingencia si el total NO se actualiza en el paso 2.4**

Si tras el refresco AJAX el bloque inferior NO refleja el nuevo total (es decir, `#payment` no se re-renderiza con el bloque), añadir en `assets/ccmck-checkout.js` una sync mínima:

```javascript
    // Respaldo: si #payment no se re-renderiza en AJAX, sincroniza el total del
    // resumen compacto desde el sidebar tras cada refresco.
    $( document.body ).on( 'updated_checkout', function () {
        var $t = $( '.checkout-sidebar .order-total td' ).last();
        if ( $t.length ) { $( '.ccmck-mos-total' ).html( $.trim( $t.html() ) ); }
    } );
```

Commit y re-desplegar el JS. (Si el paso 2.4 pasa, esta tarea se omite.)

- [ ] **Step 4: CHANGELOG**

Añadir entrada en `docs/CHANGELOG.md` (sección *Añadido*) describiendo el resumen compacto móvil antes del botón.

---

## Notas de verificación

- Sin tests JS/PHP unitarios para esto → verificación **en vivo por chrome-devtools MCP** con
  viewport móvil sobre `dev.ccmtiendadelsonido.com/pago/`.
- Riesgo principal: que `#payment` no se re-renderice en AJAX (totales obsoletos). Mitigado con
  la verificación explícita 2.4 y la contingencia del Step 3 de Task 4.
