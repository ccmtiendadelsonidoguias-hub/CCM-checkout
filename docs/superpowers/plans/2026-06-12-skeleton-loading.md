# Skeleton loading (shimmer) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar skeletons con efecto shimmer en el checkout, tanto en la carga inicial (sin parpadeo) como durante los refrescos AJAX (resumen, pago, envío), reemplazando el overlay blanco+spinner de WooCommerce.

**Architecture:** Mecanismo 100% por **toggle de clases CSS** sobre contenedores estables (no se inyecta markup que WC pueda reemplazar). El skeleton se dibuja con pseudo-elementos `::before/::after` con shimmer y se atenúa el contenido real (`opacity:0`). La carga inicial usa la clase `ccmck-preload` (puesta por PHP en el wrapper, visible desde el primer pintado); el JS la retira al estar listo. Los refrescos AJAX togglean `ccmck-skel` en las 3 regiones dinámicas vía los eventos `update_checkout`/`updated_checkout`.

**Tech Stack:** CSS (keyframes + gradiente), jQuery (eventos de WooCommerce), PHP (1 clase en plantilla), verificación con chrome-devtools MCP.

---

## File Structure

- **Modify** `assets/ccmck-checkout.css` — bloque nuevo de skeleton: variables de paleta (clara/oscura), keyframe `ccmckShimmer`, pinte compartido de pseudo-bloques, reglas de `.ccmck-preload` (campos+envío+pago+resumen) y `.ccmck-skel` (envío+pago+resumen), y neutralización del `blockUI` de WC en esas zonas.
- **Modify** `assets/ccmck-checkout.js` — módulo skeleton: togglea `ccmck-skel` en `update_checkout`/`updated_checkout`; muestra skeleton del resumen dentro de `ccmckUpdateQty`; retira `ccmck-preload` al estar listo (`window.load` / primer `updated_checkout` / timeout de seguridad).
- **Modify** `templates/checkout/form-checkout.php:40` — añadir la clase `ccmck-preload` al wrapper `<div class="ccmck ccmck-checkout-page">`.

Contenedores objetivo (estables, definidos por las plantillas del plugin): `.checkout-main .form-row` (campos), `.ccmck-shipping-section` → `#ccmck_shipping_methods` (envío), `.ccmck-payment-section` → `#payment` (pago), `.checkout-sidebar .sidebar-inner` (resumen). Las reglas CSS son por selector, así que siguen aplicando aunque WC reemplace el contenido interno.

No hay harness de tests JS → cada tarea se verifica por MCP en `https://dev.ccmtiendadelsonido.com/pago/`.

---

### Task 1: CSS del skeleton (shimmer, preload, skel, neutralizar blockUI)

**Files:**
- Modify: `assets/ccmck-checkout.css` (añadir al final del archivo)

- [ ] **Step 1: Añadir el bloque de skeleton al final de `assets/ccmck-checkout.css`**

```css
/* ===================== Skeleton loading (shimmer) =====================
   Mecanismo: clase en contenedor estable + pseudo-bloque shimmer encima y
   contenido real atenuado (opacity:0). `.ccmck-preload` (wrapper, vía PHP) =
   carga inicial; `.ccmck-skel` (por JS) = refrescos AJAX. Selectores, no
   markup → sobreviven a que WC reemplace el contenido interno por AJAX. */
.ccmck {
  --ccmck-skel-base: #e9e9ee;   /* paleta clara (columna blanca) */
  --ccmck-skel-hi:   #f4f4f7;
  --ccmck-skel-radius: 8px;
}
.ccmck .checkout-sidebar {       /* paleta oscura (panel negro) */
  --ccmck-skel-base: #26262b;
  --ccmck-skel-hi:   #34343b;
}
@keyframes ccmckShimmer {
  0%   { background-position: -600px 0; }
  100% { background-position:  600px 0; }
}
/* Pinte compartido de todos los pseudo-bloques skeleton. */
.ccmck.ccmck-preload .checkout-main .form-row::after,
.ccmck.ccmck-preload .ccmck-shipping-section::after,
.ccmck.ccmck-preload .ccmck-payment-section::after,
.ccmck.ccmck-preload .checkout-sidebar .sidebar-inner::before,
.ccmck.ccmck-preload .checkout-sidebar .sidebar-inner::after,
.ccmck .ccmck-shipping-section.ccmck-skel::after,
.ccmck .ccmck-payment-section.ccmck-skel::after,
.ccmck .checkout-sidebar.ccmck-skel .sidebar-inner::before,
.ccmck .checkout-sidebar.ccmck-skel .sidebar-inner::after {
  content: '';
  position: absolute;
  background-color: var(--ccmck-skel-base);
  background-image: linear-gradient(90deg, var(--ccmck-skel-base) 0, var(--ccmck-skel-hi) 90px, var(--ccmck-skel-base) 180px);
  background-size: 600px 100%;
  background-repeat: no-repeat;
  border-radius: var(--ccmck-skel-radius);
  animation: ccmckShimmer 1.3s linear infinite;
  pointer-events: none;
  z-index: 3;
}
/* Transición de reaparición del contenido real. */
.ccmck .checkout-main .form-row > *,
.ccmck .ccmck-shipping-section #ccmck_shipping_methods,
.ccmck .ccmck-payment-section #payment,
.ccmck .checkout-sidebar .sidebar-inner > * { transition: opacity .3s ease; }

/* --- Campos (solo carga inicial) --- */
.ccmck.ccmck-preload .checkout-main .form-row { position: relative; }
.ccmck.ccmck-preload .checkout-main .form-row > * { opacity: 0; }
.ccmck.ccmck-preload .checkout-main .form-row::after { left: 0; right: 0; top: 0; height: 52px; }

/* --- Envío (inicial + AJAX) --- */
.ccmck .ccmck-shipping-section { position: relative; }
.ccmck.ccmck-preload .ccmck-shipping-section #ccmck_shipping_methods,
.ccmck .ccmck-shipping-section.ccmck-skel #ccmck_shipping_methods { opacity: 0; min-height: 120px; }
.ccmck.ccmck-preload .ccmck-shipping-section::after,
.ccmck .ccmck-shipping-section.ccmck-skel::after { left: 0; right: 0; top: 44px; height: 120px; }

/* --- Pago (inicial + AJAX) --- */
.ccmck .ccmck-payment-section { position: relative; }
.ccmck.ccmck-preload .ccmck-payment-section #payment,
.ccmck .ccmck-payment-section.ccmck-skel #payment { opacity: 0; min-height: 160px; }
.ccmck.ccmck-preload .ccmck-payment-section::after,
.ccmck .ccmck-payment-section.ccmck-skel::after { left: 0; right: 0; top: 84px; height: 200px; }

/* --- Resumen (inicial + AJAX), sidebar oscuro --- */
.ccmck .checkout-sidebar .sidebar-inner { position: relative; }
.ccmck.ccmck-preload .checkout-sidebar .sidebar-inner,
.ccmck .checkout-sidebar.ccmck-skel .sidebar-inner { min-height: 240px; }
.ccmck.ccmck-preload .checkout-sidebar .sidebar-inner > *,
.ccmck .checkout-sidebar.ccmck-skel .sidebar-inner > * { opacity: 0; }
.ccmck.ccmck-preload .checkout-sidebar .sidebar-inner::before,
.ccmck .checkout-sidebar.ccmck-skel .sidebar-inner::before { left: 24px; right: 24px; top: 24px; height: 72px; }
.ccmck.ccmck-preload .checkout-sidebar .sidebar-inner::after,
.ccmck .checkout-sidebar.ccmck-skel .sidebar-inner::after { left: 24px; right: 24px; top: 116px; height: 96px; }

/* Oculta el overlay blanco+spinner por defecto de WC donde ponemos skeleton. */
.ccmck .checkout-sidebar .blockUI.blockOverlay,
.ccmck #order_review .blockUI.blockOverlay,
.ccmck .ccmck-payment-section .blockUI.blockOverlay,
.ccmck #ccmck_shipping_methods .blockUI.blockOverlay { display: none !important; }
/* ===================== /Skeleton loading ===================== */
```

- [ ] **Step 2: Verificar el render del skeleton por MCP (inyectando las clases)**

Recargar el checkout de dev. Inyectar el CSS de arriba en un `<style>` (cache-bust no aplica a CSS inyectado) y forzar el estado preload; tomar screenshot.

```javascript
() => {
  // (pegar el bloque CSS de arriba como string en una <style id="ccmck-skel-test">)
  // … aquí el agente inyecta el CSS …
  const w = document.querySelector('.ccmck-checkout-page');
  w.classList.add('ccmck-preload');
  window.scrollTo(0,0);
  return { preload: w.classList.contains('ccmck-preload') };
}
```

Expected: screenshot con barras shimmer en los campos (claras) y paneles shimmer en el resumen (oscuros), envío y pago; el contenido real atenuado. Verificar visualmente que el shimmer anima y las paletas son correctas.

- [ ] **Step 3: Verificar el estado AJAX (`ccmck-skel`) por MCP**

```javascript
() => {
  document.querySelector('.ccmck-checkout-page').classList.remove('ccmck-preload');
  ['.checkout-sidebar','.ccmck-payment-section','.ccmck-shipping-section']
    .forEach(s => document.querySelector(s)?.classList.add('ccmck-skel'));
  return 'skel on';
}
```

Expected: screenshot con skeleton solo en resumen + pago + envío (los campos NO, siguen visibles). Confirma que el toggle AJAX afecta solo a las 3 regiones dinámicas.

- [ ] **Step 4: Commit**

```bash
git add assets/ccmck-checkout.css
git commit -m "feat(checkout): estilos de skeleton loading (shimmer)"
```

---

### Task 2: PHP — clase `ccmck-preload` en el wrapper

**Files:**
- Modify: `templates/checkout/form-checkout.php:40`

- [ ] **Step 1: Añadir la clase al wrapper**

Reemplazar la línea 40:

```php
<div class="ccmck ccmck-checkout-page">
```

por:

```php
<div class="ccmck ccmck-checkout-page ccmck-preload">
```

- [ ] **Step 2: Lint del PHP**

Run: `php -l templates/checkout/form-checkout.php`
Expected: `No syntax errors detected in templates/checkout/form-checkout.php`

- [ ] **Step 3: Commit**

```bash
git add templates/checkout/form-checkout.php
git commit -m "feat(checkout): wrapper con ccmck-preload para skeleton inicial"
```

---

### Task 3: JS — togglear skeleton (AJAX) y retirar preload (inicial)

**Files:**
- Modify: `assets/ccmck-checkout.js`

- [ ] **Step 1: Añadir el módulo skeleton antes del cierre `} )( jQuery );`**

Insertar este bloque justo antes de la última línea `} )( jQuery );`:

```javascript
    /* ------------------------------------------------------------------ */
    /*  Skeleton loading (shimmer): carga inicial + refrescos AJAX         */
    /*  Mecanismo por clases CSS (ver ccmck-checkout.css). En AJAX togglea  */
    /*  .ccmck-skel en las 3 regiones dinámicas; en la carga inicial retira */
    /*  .ccmck-preload del wrapper cuando el checkout está listo.           */
    /* ------------------------------------------------------------------ */
    var CCMCK_SKEL_REGIONS = '.checkout-sidebar, .ccmck-payment-section, .ccmck-shipping-section';

    // AJAX de WooCommerce: skeleton al iniciar el refresco, fuera al terminar.
    $( document.body ).on( 'update_checkout', function () {
        $( CCMCK_SKEL_REGIONS ).addClass( 'ccmck-skel' );
    } );
    $( document.body ).on( 'updated_checkout', function () {
        $( CCMCK_SKEL_REGIONS ).removeClass( 'ccmck-skel' );
    } );

    // Carga inicial: retira .ccmck-preload cuando el checkout está listo.
    // Señales: window.load O el primer updated_checkout; + timeout de seguridad.
    function ccmckRevealInitial() {
        $( '.ccmck-checkout-page.ccmck-preload' ).removeClass( 'ccmck-preload' );
    }
    $( window ).on( 'load', ccmckRevealInitial );
    $( document.body ).on( 'updated_checkout', ccmckRevealInitial );
    setTimeout( ccmckRevealInitial, 4000 ); // red de seguridad
```

- [ ] **Step 2: Mostrar skeleton del resumen al cambiar cantidad (dentro de `ccmckUpdateQty`)**

En `ccmckUpdateQty` (la función existente del AJAX de carrito), añadir el skeleton del resumen al inicio y quitarlo si la petición no dispara un refresco. Reemplazar el cuerpo actual:

```javascript
        busy = true;

        $.post( CCMCK.ajaxUrl, {
            action: 'ccmck_update_cart_item',
            nonce:  CCMCK.nonce,
            key:    key,
            qty:    qty
        } )
        .done( function ( res ) {
            if ( res && res.success ) {
                // Indica a WC que recalcule totales y vuelva a pintar #order_review
                $( document.body ).trigger( 'update_checkout' );
            }
        } )
        .always( function () {
            // Libera la bandera al finalizar (éxito o error)
            busy = false;
        } );
```

por:

```javascript
        busy = true;
        // Skeleton inmediato del resumen (antes de que llegue update_checkout).
        $( '.checkout-sidebar' ).addClass( 'ccmck-skel' );

        $.post( CCMCK.ajaxUrl, {
            action: 'ccmck_update_cart_item',
            nonce:  CCMCK.nonce,
            key:    key,
            qty:    qty
        } )
        .done( function ( res ) {
            if ( res && res.success ) {
                // Indica a WC que recalcule totales y vuelva a pintar #order_review
                // (update_checkout re-añade el skeleton; updated_checkout lo retira).
                $( document.body ).trigger( 'update_checkout' );
            } else {
                $( '.checkout-sidebar' ).removeClass( 'ccmck-skel' );
            }
        } )
        .fail( function () {
            $( '.checkout-sidebar' ).removeClass( 'ccmck-skel' );
        } )
        .always( function () {
            // Libera la bandera al finalizar (éxito o error)
            busy = false;
        } );
```

- [ ] **Step 3: Verificar sintaxis**

Run: `node --check assets/ccmck-checkout.js`
Expected: sin salida de error (exit 0).

- [ ] **Step 4: Verificar carga inicial por MCP (con CSS+JS+preload inyectados)**

Recargar el checkout de dev. Inyectar el CSS de Task 1, añadir `ccmck-preload` al wrapper, y comprobar que `ccmckRevealInitial` lo retira:

```javascript
() => {
  const w = document.querySelector('.ccmck-checkout-page');
  w.classList.add('ccmck-preload');
  const before = w.classList.contains('ccmck-preload');
  // simula la señal de "listo"
  window.jQuery(document.body).trigger('updated_checkout');
  return { before, after: w.classList.contains('ccmck-preload') };
}
```

Expected (con el JS nuevo cargado/inyectado): `{ before:true, after:false }` — el primer `updated_checkout` retira el preload. Screenshot: contenido real visible, sin skeleton.

- [ ] **Step 5: Verificar AJAX de cantidad por MCP**

Pulsar el botón "+" del resumen y observar que el resumen muestra skeleton mientras recalcula y vuelve al normal:

```javascript
() => {
  const plus = document.querySelector('.ccmck-qty-plus');
  plus && plus.click();
  // inmediatamente tras el click, el resumen debe tener la clase
  return { skelOnClick: document.querySelector('.checkout-sidebar')?.classList.contains('ccmck-skel') };
}
```

Expected: `{ skelOnClick: true }` justo tras el click; tras el `updated_checkout` el resumen vuelve (verificar con un segundo eval que la clase ya no está).

- [ ] **Step 6: Verificar refresco de dirección/envío por MCP**

```javascript
() => {
  window.jQuery(document.body).trigger('update_checkout');
  const on = ['.checkout-sidebar','.ccmck-payment-section','.ccmck-shipping-section']
    .map(s => document.querySelector(s)?.classList.contains('ccmck-skel'));
  return { skelAll: on };
}
```

Expected: `{ skelAll: [true,true,true] }` — las 3 regiones con skeleton durante el refresco.

- [ ] **Step 7: Commit**

```bash
git add assets/ccmck-checkout.js
git commit -m "feat(checkout): toggle de skeleton loading en AJAX y carga inicial"
```

---

### Task 4: Despliegue y verificación final

**Files:** ninguno (deploy + smoke test)

- [ ] **Step 1: Recordar deploy al usuario**

Subir por File Manager: `assets/ccmck-checkout.css`, `assets/ccmck-checkout.js` y `templates/checkout/form-checkout.php`. El CSS/JS rompe caché solo por `force_version()`; el PHP de la plantilla **requiere purgar OPcache** para que aplique.

- [ ] **Step 2: Smoke test contra el sitio real**

Tras el deploy + purgar OPcache: recargar `/pago/` y confirmar que el skeleton aparece de inmediato en la carga (sin parpadeo de contenido→skeleton) y se retira al estar listo; pulsar +/− y cambiar ciudad para ver el skeleton AJAX. Screenshots de cada caso. Confirmar que `force_version()` sirvió la versión nueva (fetch cache-bust buscando `ccmck-preload` en el JS/CSS).

- [ ] **Step 3: CHANGELOG**

Añadir entrada en `docs/CHANGELOG.md` (sección *Añadido*) describiendo el skeleton loading shimmer (carga inicial + AJAX, dos paletas).

---

## Notas de verificación

- Sin tests JS en el repo → verificación **en vivo por chrome-devtools MCP** sobre
  `dev.ccmtiendadelsonido.com/pago/`.
- Los `top`/`height` de los paneles skeleton (envío 44px, pago 84px, resumen 24/116px) son
  aproximados al layout actual; ajustar finos **en vivo por MCP** si algún panel queda
  desalineado respecto al encabezado de su sección.
- La transición de reaparición (`opacity .3s`) puede dejar un brevísimo destello al retirar el
  skeleton; si se nota, afinar la duración o mantener el pseudo con `opacity` en transición —
  decisión visual a validar por MCP, no bloquea la lógica.
