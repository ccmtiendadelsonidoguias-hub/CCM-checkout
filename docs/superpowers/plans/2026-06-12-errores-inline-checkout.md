# Errores WC inline por campo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repartir los errores server-side de WooCommerce (evento `checkout_error`) inline bajo cada campo estilo Shopify, dejando los no-mapeables en un aviso compacto junto al botón de pago.

**Architecture:** Todo en `assets/ccmck-checkout.js`. Se reemplaza el handler actual de `checkout_error` (que solo reubica el banner completo) por uno que: (1) construye un índice `label/alias → form-row`, (2) reparte cada `<li>` de error a su fila o a "sueltos", (3) pinta inline los mapeados (reusando `ccmckSetRowError`/`.ccmck-invalid`/`.ccmck-field-error`) y reubica solo los sueltos. Sin PHP, sin CSS nuevo (reusa estilos existentes).

**Tech Stack:** jQuery (WooCommerce checkout), CSS existente del plugin, verificación en vivo con chrome-devtools MCP.

---

## File Structure

- **Modify:** `assets/ccmck-checkout.js`
  - Añadir helpers: `ccmckNorm`, `CCMCK_ERR_ALIASES`, `ccmckBuildFieldIndex`, `ccmckMatchRow`, `ccmckMapServerErrors`.
  - Reemplazar el handler `$( document.body ).on( 'checkout_error', … )` (actualmente líneas ~321-331) por una llamada a `ccmckMapServerErrors`.
- **Reusa sin cambios:** `ccmckSetRowError`, `ccmckClearRowError`, `ccmckFieldControl` (ya existen), y el CSS `.ccmck-invalid` / `.ccmck-field-error` / `.ccmck-notice-relocated`.

No se crean archivos nuevos. No hay harness de tests JS → cada tarea se verifica inyectando errores reales por MCP en `https://dev.dev.ccmtiendadelsonido.com/pago/`.

---

### Task 1: Helpers de normalización, alias e índice de campos

**Files:**
- Modify: `assets/ccmck-checkout.js` (insertar antes del handler `checkout_error`, es decir antes de la línea ~321)

- [ ] **Step 1: Añadir los helpers**

Insertar este bloque justo después de la función `ccmckClearRowError` (≈línea 260) y antes del comentario "Valida los obligatorios visibles":

```javascript
    /* ------------------------------------------------------------------ */
    /*  Errores server-side de WooCommerce → inline por campo (Shopify)    */
    /*  WC envuelve el nombre del campo en <strong> dentro de cada <li>.    */
    /*  Casamos ese texto (o un alias) contra el label de cada .form-row y  */
    /*  pintamos el error DEBAJO del campo; los que no casan van a un aviso */
    /*  compacto junto al botón (ccmckMapServerErrors, más abajo).          */
    /* ------------------------------------------------------------------ */

    // Normaliza para comparar: minúsculas, sin acentos, sin '*', espacios colapsados.
    function ccmckNorm( s ) {
        return ( s || '' ).toString().toLowerCase()
            .normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' )
            .replace( /\*/g, '' ).replace( /\s+/g, ' ' ).trim();
    }

    // Sinónimos que pueden aparecer en el mensaje → id del control destino.
    var CCMCK_ERR_ALIASES = {
        billing_email:           [ 'correo', 'correo electronico', 'email', 'e-mail' ],
        billing_phone:           [ 'telefono', 'movil', 'celular', 'numero de telefono' ],
        billing_document_number: [ 'numero de documento', 'documento' ],
        billing_document_type:   [ 'tipo de documento' ],
        billing_city:            [ 'poblacion', 'ciudad' ],
        billing_state:           [ 'departamento', 'provincia', 'estado' ],
        billing_address_1:       [ 'direccion', 'calle', 'direccion de la calle' ],
        billing_first_name:      [ 'nombre' ],
        billing_last_name:       [ 'apellido', 'apellidos' ],
        billing_postcode:        [ 'cedula', 'nit', 'codigo postal' ]
    };

    // Construye [{ key, row }] (key normalizada): por label de cada fila + aliases.
    function ccmckBuildFieldIndex() {
        var index = [];
        $( '.checkout-main .form-row' ).each( function () {
            var $row = $( this );
            var txt  = ccmckNorm( $row.children( 'label' ).first().text() );
            if ( txt ) { index.push( { key: txt, row: $row } ); }
        } );
        $.each( CCMCK_ERR_ALIASES, function ( id, words ) {
            var $row = $( '#' + id ).closest( '.checkout-main .form-row' );
            if ( ! $row.length ) { return; }
            $.each( words, function ( i, w ) { index.push( { key: w, row: $row } ); } );
        } );
        return index;
    }

    // Devuelve la fila para un error: match exacto del <strong> gana; si no, la
    // key MÁS LARGA contenida como subcadena en el texto completo (más específica).
    function ccmckMatchRow( strongTxt, fullTxt, index ) {
        var nStrong = ccmckNorm( strongTxt );
        var nFull   = ccmckNorm( fullTxt );
        var best = null, bestLen = 0;
        for ( var i = 0; i < index.length; i++ ) {
            var k = index[ i ].key;
            if ( ! k ) { continue; }
            if ( nStrong && nStrong === k ) { return index[ i ].row; }
            if ( nFull.indexOf( k ) !== -1 && k.length > bestLen ) {
                best = index[ i ].row; bestLen = k.length;
            }
        }
        return best;
    }
```

- [ ] **Step 2: Verificar en vivo que el índice resuelve los campos esperados**

En el checkout de dev (con el JS editado cargado por inyección), evaluar por MCP:

```javascript
() => {
  // Reproduce la lógica para confirmar el mapeo (las fns son de closure;
  // probamos vía el handler real en Task 2). Aquí solo confirmamos que los
  // ids/labels existen en el DOM.
  const ids = ['billing_email','billing_phone','billing_document_number',
    'billing_city','billing_state','billing_address_1','billing_first_name',
    'billing_last_name','billing_postcode'];
  return ids.map(id => {
    const el = document.getElementById(id);
    const row = el && el.closest('.form-row');
    const label = row && row.querySelector('label');
    return { id, present: !!el, label: label ? label.textContent.trim() : null };
  });
}
```

Expected: todos `present:true` (o los que existan en este checkout) con su `label`. Confirma que los aliases tienen fila destino.

- [ ] **Step 3: Commit**

```bash
git add assets/ccmck-checkout.js
git commit -m "feat(checkout): helpers de mapeo de errores WC a campos"
```

---

### Task 2: Reemplazar el handler `checkout_error` por el reparto inline

**Files:**
- Modify: `assets/ccmck-checkout.js` (handler `checkout_error`, ≈líneas 319-331)

- [ ] **Step 1: Añadir `ccmckMapServerErrors` y reemplazar el handler**

Borrar el handler actual completo:

```javascript
    // Reubica el aviso general de WooCommerce (pago/envío/errores de formato que sí
    // llegan al servidor) justo encima del botón Pagar, en vez de arriba del form.
    $( document.body ).on( 'checkout_error', function () {
        var $group = $( 'form.checkout > .woocommerce-NoticeGroup-checkout' );
        if ( ! $group.length ) { return; }
        var $anchor = $( '.ccmck-payment-section .place-order' ).first();
        if ( ! $anchor.length ) {
            $anchor = $( '#place_order' ).closest( '.form-row' );
        }
        if ( $anchor.length ) {
            $group.addClass( 'ccmck-notice-relocated' ).insertBefore( $anchor );
        }
    } );
```

y sustituirlo por:

```javascript
    // Reparte los errores server-side de WC: cada <li> que casa con un campo se
    // pinta inline bajo ese campo (Shopify); los sobrantes quedan en un aviso
    // compacto reubicado junto al botón. Si todos se mapean, no queda banner.
    function ccmckMapServerErrors() {
        var $group = $( 'form.checkout .woocommerce-NoticeGroup-checkout' ).first();
        if ( ! $group.length ) { return; }
        var $list = $group.find( 'ul.woocommerce-error' ).first();
        if ( ! $list.length ) { return; }

        var index    = ccmckBuildFieldIndex();
        var leftover = [];          // HTML de los <li> sin campo
        var usedRows = [];          // un error por fila (el primero)
        var $firstRow = null;

        $list.children( 'li' ).each( function () {
            var $li  = $( this );
            var $row = ccmckMatchRow( $li.find( 'strong' ).first().text(), $li.text(), index );
            if ( $row && $row.length && $.inArray( $row.get( 0 ), usedRows ) === -1 ) {
                ccmckSetRowError( $row, $.trim( $li.text() ) );
                usedRows.push( $row.get( 0 ) );
                if ( ! $firstRow ) { $firstRow = $row; }
            } else if ( ! $row || ! $row.length ) {
                leftover.push( $.trim( $li.html() ) );
            }
        } );

        if ( leftover.length ) {
            $list.html( $.map( leftover, function ( h ) { return '<li>' + h + '</li>'; } ).join( '' ) );
            var $anchor = $( '.ccmck-payment-section .place-order' ).first();
            if ( ! $anchor.length ) { $anchor = $( '#place_order' ).closest( '.form-row' ); }
            if ( $anchor.length ) {
                $group.addClass( 'ccmck-notice-relocated' ).insertBefore( $anchor );
            }
        } else {
            $group.remove();
        }

        var $scroll = $firstRow || ( leftover.length ? $( '.ccmck-notice-relocated' ) : null );
        if ( $scroll && $scroll.length ) {
            $( 'html, body' ).animate( { scrollTop: $scroll.offset().top - 120 }, 300 );
            if ( $firstRow ) {
                var $ctrl = ccmckFieldControl( $firstRow );
                if ( $ctrl && $ctrl.length && $ctrl.is( ':visible' ) ) { $ctrl.trigger( 'focus' ); }
            }
        }
    }

    $( document.body ).on( 'checkout_error', ccmckMapServerErrors );
```

- [ ] **Step 2: Verificar — error mapeable a campo (required)**

Recargar el checkout de dev con el JS editado. Inyectar y disparar por MCP:

```javascript
() => {
  const $ = window.jQuery, form = document.querySelector('form.checkout');
  $('.woocommerce-NoticeGroup-checkout', form).remove();
  $(form).prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">'
    + '<ul class="woocommerce-error" role="alert">'
    + '<li><strong>Población</strong> es un campo requerido.</li></ul></div>');
  $(document.body).trigger('checkout_error');
  const row = document.getElementById('billing_city').closest('.form-row');
  return { invalid: row.classList.contains('ccmck-invalid'),
           msg: row.querySelector('.ccmck-field-error')?.textContent,
           banner: !!document.querySelector('.ccmck-notice-relocated') };
}
```

Expected: `{ invalid: true, msg: "Población es un campo requerido.", banner: false }` (sin banner; el error quedó inline bajo Población). Tomar screenshot para confirmar el look.

- [ ] **Step 3: Verificar — mezcla mapeable + suelto**

```javascript
() => {
  const $ = window.jQuery, form = document.querySelector('form.checkout');
  $('.woocommerce-NoticeGroup-checkout', form).remove();
  $(form).prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">'
    + '<ul class="woocommerce-error" role="alert">'
    + '<li><strong>Número de documento</strong> es un campo requerido.</li>'
    + '<li>El pago con la pasarela ha fallado. Inténtalo de nuevo.</li></ul></div>');
  $(document.body).trigger('checkout_error');
  const docRow = document.getElementById('billing_document_number').closest('.form-row');
  const banner = document.querySelector('.ccmck-notice-relocated');
  return {
    docInline: docRow.querySelector('.ccmck-field-error')?.textContent,
    bannerItems: banner ? [...banner.querySelectorAll('li')].map(li=>li.textContent.trim()) : null
  };
}
```

Expected: `docInline` = "Número de documento es un campo requerido." y `bannerItems` = ["El pago con la pasarela ha fallado. Inténtalo de nuevo."] (solo el suelto en el banner). Screenshot del banner junto al botón.

- [ ] **Step 4: Verificar — alias de email**

```javascript
() => {
  const $ = window.jQuery, form = document.querySelector('form.checkout');
  $('.woocommerce-NoticeGroup-checkout', form).remove();
  $(form).prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">'
    + '<ul class="woocommerce-error" role="alert">'
    + '<li>La dirección de correo electrónico no es válida.</li></ul></div>');
  $(document.body).trigger('checkout_error');
  const row = document.getElementById('billing_email').closest('.form-row');
  return { invalid: row.classList.contains('ccmck-invalid'),
           msg: row.querySelector('.ccmck-field-error')?.textContent,
           banner: !!document.querySelector('.ccmck-notice-relocated') };
}
```

Expected: `{ invalid: true, msg: "La dirección de correo electrónico no es válida.", banner: false }` (el alias "correo" casó con `billing_email` aunque no había `<strong>`).

- [ ] **Step 5: Verificar — error 100% genérico va al banner**

```javascript
() => {
  const $ = window.jQuery, form = document.querySelector('form.checkout');
  $('.woocommerce-NoticeGroup-checkout', form).remove();
  $(form).prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">'
    + '<ul class="woocommerce-error" role="alert">'
    + '<li>Ha ocurrido un error al procesar tu pedido.</li></ul></div>');
  $(document.body).trigger('checkout_error');
  const banner = document.querySelector('.ccmck-notice-relocated');
  return { bannerItems: banner ? [...banner.querySelectorAll('li')].map(li=>li.textContent.trim()) : null };
}
```

Expected: `bannerItems` = ["Ha ocurrido un error al procesar tu pedido."] (no casó con ningún campo → banner). Ningún `.ccmck-invalid` nuevo.

- [ ] **Step 6: Verificar — limpieza al editar**

Tras el Step 2 (Población inválida), escribir en el campo y confirmar que el inline desaparece:

```javascript
() => {
  const row = document.getElementById('billing_city').closest('.form-row');
  // simula corrección: el handler input/change limpia .ccmck-invalid si no está vacío
  const sel = row.querySelector('select');
  if (sel && sel.options.length > 1) { sel.selectedIndex = 1; window.jQuery(sel).trigger('change'); }
  return { stillInvalid: row.classList.contains('ccmck-invalid') };
}
```

Expected: `{ stillInvalid: false }` (al elegir ciudad se limpia el error inline).

- [ ] **Step 7: Commit**

```bash
git add assets/ccmck-checkout.js
git commit -m "feat(checkout): errores WC server-side inline por campo (estilo Shopify)"
```

---

### Task 3: Despliegue y verificación final

**Files:** ninguno (solo deploy + smoke test)

- [ ] **Step 1: Recordar al usuario el deploy**

`assets/ccmck-checkout.js` debe subirse por **File Manager del hosting** (la carpeta local NO sincroniza, ver `deploy-dev-server`). El CSS/JS rompe caché solo por `force_version()` (filemtime), no requiere purgar OPcache (es asset, no PHP).

- [ ] **Step 2: Smoke test en vivo tras el deploy**

Confirmar por MCP que el archivo servido es el nuevo (fetch cache-bust `?cb=rand`, buscar `ccmckMapServerErrors` en el cuerpo) y repetir los escenarios de Task 2 contra el JS REAL del servidor (no inyectado). Screenshot del resultado mapeable + del banner sobrante.

- [ ] **Step 3: Actualizar memoria / CHANGELOG**

Anotar en `docs/CHANGELOG.md` el cambio y, si procede, actualizar la nota de proyecto en memoria (`ccm-checkout-project`).

---

## Notas de verificación

- No hay harness de tests JS en el repo → la verificación es **inyección en vivo por
  chrome-devtools MCP** sobre `dev.dev.ccmtiendadelsonido.com/pago/`, consistente con cómo se
  verificó todo el checkout hasta ahora.
- El estilo inline (`.ccmck-field-error`, borde rojo) y el aviso (`.ccmck-notice-relocated`)
  **ya existen**: no se añade CSS. Si tras ver el resultado el usuario quiere igualar
  exactamente el rojo/tamaño de Shopify (`#dd1d1d`, 14px), es un ajuste CSS posterior fuera
  de este plan.
