/* ccm-checkout frontend — Fase 7: AJAX de carrito + acordeones + toggle móvil */
( function ( $ ) {
    'use strict';

    // Bandera para evitar peticiones simultáneas (doble clic)
    var busy = false;

    /* ------------------------------------------------------------------ */
    /*  Estado visual del método de pago seleccionado                     */
    /*  Sincroniza la clase .selected con el radio realmente marcado.     */
    /*  No toca la lógica de WooCommerce: WC sigue gestionando el radio,  */
    /*  la visibilidad de .payment_box y payment_fields(). Aquí solo      */
    /*  reflejamos visualmente qué li.wc_payment_method está elegido.     */
    /* ------------------------------------------------------------------ */
    function ccmckSyncSelectedPayment() {
        var $methods = $( '.wc_payment_method' );
        if ( ! $methods.length ) {
            return;
        }
        $methods.removeClass( 'selected' );
        $( 'input[name="payment_method"]:checked' )
            .closest( '.wc_payment_method' )
            .addClass( 'selected' );
    }

    /* Persistencia de la selección de método de pago.
       Problema: al re-renderizar #payment en updated_checkout (lo dispara un
       cambio de dirección/envío, o el propio gateway de Addi/MercadoPago), WC
       repinta los radios con el método de SESIÓN marcado (normalmente el
       primero, Wompi) y se pierde la elección del cliente. Síntoma real:
       seleccionar Addi y terminar pagando con Wompi. Recordamos la elección
       explícita del usuario y la re-aplicamos tras cada refresco. */
    var ccmckUserPayment = null;
    var ccmckReapplyingPayment = false;

    function ccmckRestoreUserPayment() {
        if ( ! ccmckUserPayment || ccmckReapplyingPayment ) {
            return;
        }
        var $radio = $( 'input[name="payment_method"][value="' + ccmckUserPayment + '"]' );
        if ( $radio.length && ! $radio.prop( 'checked' ) ) {
            ccmckReapplyingPayment = true;
            // 'change' deja que WC muestre el .payment_box correcto y sincroniza .selected.
            $radio.prop( 'checked', true ).trigger( 'change' );
            ccmckReapplyingPayment = false;
        }
    }

    // Cambio de método: el click en radio/label dispara 'change' de forma nativa.
    $( document ).on( 'change', 'input[name="payment_method"]', function () {
        if ( ! ccmckReapplyingPayment ) {
            ccmckUserPayment = $( this ).val();
        }
        ccmckSyncSelectedPayment();
    } );
    // Tras un refresco AJAX del checkout, WooCommerce repinta #payment:
    // restauramos la elección del usuario y re-sincronizamos el estado visual.
    $( document.body ).on( 'updated_checkout', function () {
        ccmckRestoreUserPayment();
        ccmckSyncSelectedPayment();
    } );
    // Estado inicial al cargar.
    $( ccmckSyncSelectedPayment );

    /* Addi lee la cédula del campo billing_id (que el plugin elimina del
       formulario por ser duplicado). Mantenemos un input oculto billing_id
       sincronizado con el número de documento, como refuerzo cliente del fix
       server-side (CCMCK_Document::mirror_document_to_billing_id). */
    function ccmckSyncBillingId() {
        var $form = $( 'form.checkout' );
        var $num  = $( '#billing_document_number' );
        if ( ! $form.length || ! $num.length ) {
            return;
        }
        var $hidden = $form.find( 'input[name="billing_id"]' );
        if ( ! $hidden.length ) {
            $hidden = $( '<input type="hidden" name="billing_id" />' ).appendTo( $form );
        }
        $hidden.val( $.trim( $num.val() || '' ) );
    }
    $( document ).on( 'input change', '#billing_document_number', ccmckSyncBillingId );
    $( document.body ).on( 'updated_checkout', ccmckSyncBillingId );
    $( ccmckSyncBillingId );

    /* ------------------------------------------------------------------ */
    /*  Wompi como POPUP (no página nueva)                                  */
    /*  Al colocar el pedido con Wompi, WC procesa y responde con un        */
    /*  redirect a la página order-pay (donde el plugin Wompi pinta un      */
    /*  botón que REDIRIGE a checkout.wompi.co). Interceptamos esa          */
    /*  respuesta, traemos los datos FIRMADOS del widget desde order-pay y  */
    /*  abrimos el modal WidgetCheckout aquí mismo. Cualquier fallo →       */
    /*  se deja el flujo normal (redirección a order-pay). Reutilizamos la  */
    /*  firma del plugin: NO la recreamos.                                  */
    /* ------------------------------------------------------------------ */
    function ccmckWompiSelected() {
        return $( 'input[name="payment_method"]:checked' ).val() === 'wompi';
    }

    function ccmckOpenWompiPopup( orderPayUrl ) {
        if ( typeof window.WidgetCheckout === 'undefined' ) {
            return false; // sin el widget cargado → dejar el flujo normal
        }
        fetch( orderPayUrl, { credentials: 'include' } )
            .then( function ( r ) { return r.text(); } )
            .then( function ( html ) {
                var doc = new DOMParser().parseFromString( html, 'text/html' );
                var s = doc.querySelector( 'script[src*="checkout.wompi.co/widget.js"][data-reference]' );
                if ( ! s ) { window.location = orderPayUrl; return; }
                var cfg = {
                    currency:      s.getAttribute( 'data-currency' ),
                    amountInCents: parseInt( s.getAttribute( 'data-amount-in-cents' ), 10 ),
                    reference:     s.getAttribute( 'data-reference' ),
                    publicKey:     s.getAttribute( 'data-public-key' ),
                    redirectUrl:   s.getAttribute( 'data-redirect-url' ),
                    signature:     { integrity: s.getAttribute( 'data-signature:integrity' ) }
                };
                try {
                    new window.WidgetCheckout( cfg ).open( function () {
                        // Wompi redirige a su redirectUrl (página de gracias) que
                        // resuelve el estado de la transacción para cualquier resultado.
                        window.location = cfg.redirectUrl || orderPayUrl;
                    } );
                } catch ( e ) {
                    window.location = orderPayUrl; // fallback
                }
            } )
            .catch( function () { window.location = orderPayUrl; } ); // fallback
        return true;
    }

    var ccmckOrigAjax = $.ajax;
    $.ajax = function ( opts ) {
        if ( opts && typeof opts.url === 'string' && opts.url.indexOf( 'wc-ajax=checkout' ) !== -1 ) {
            var origSuccess = opts.success;
            opts.success = function ( data ) {
                var handled = false;
                try {
                    if ( data && 'success' === data.result && data.redirect &&
                         /order-pay|pay_for_order/.test( data.redirect ) && ccmckWompiSelected() ) {
                        handled = ccmckOpenWompiPopup( data.redirect );
                    }
                } catch ( e ) { handled = false; }
                if ( handled ) {
                    // Evitamos que WC redirija; el popup (o su fallback) navega.
                    try { $( 'form.checkout' ).removeClass( 'processing' ).unblock(); } catch ( e2 ) {}
                    return;
                }
                return origSuccess ? origSuccess.apply( this, arguments ) : undefined;
            };
        }
        return ccmckOrigAjax.apply( this, arguments );
    };

    /* ------------------------------------------------------------------ */
    /*  Acordeón de preguntas frecuentes                                   */
    /* ------------------------------------------------------------------ */
    $( document ).on( 'click', '.faq-header', function () {
        $( this ).closest( '.faq-item' ).toggleClass( 'open' );
    } );

    /* ------------------------------------------------------------------ */
    /*  Toggle de resumen en móvil                                         */
    /* ------------------------------------------------------------------ */
    $( document ).on( 'click', '.mobile-summary-toggle', function () {
        var open = $( this ).toggleClass( 'open' ).hasClass( 'open' );
        $( this ).attr( 'aria-expanded', open ? 'true' : 'false' )
            .siblings( '.sidebar-inner' ).slideToggle( 150 );
    } );

    // Refleja el total del pedido en la cabecera plegable (estado cerrado, móvil).
    // El total cambia con el envío/cantidades, así que se re-sincroniza tras cada
    // refresco AJAX del checkout.
    function ccmckSyncMobileTotal() {
        var $total = $( '.checkout-sidebar .order-total td' ).last();
        var $price = $( '.mobile-summary-toggle .toggle-price' );
        if ( $total.length && $price.length ) {
            $price.text( $.trim( $total.text() ) );
        }
    }
    $( document.body ).on( 'updated_checkout', ccmckSyncMobileTotal );
    $( ccmckSyncMobileTotal );

    /* ------------------------------------------------------------------ */
    /*  Controles de cantidad del carrito                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Envía la actualización de cantidad al servidor y recarga el resumen WC.
     *
     * @param {string} key  Clave del ítem en el carrito WC.
     * @param {number} qty  Nueva cantidad (0 = eliminar el ítem).
     */
    function ccmckUpdateQty( key, qty ) {
        if ( busy ) {
            return; // Ignora si ya hay una petición en curso
        }
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
    }

    // Botón "+" — incrementa cantidad
    $( document ).on( 'click', '.ccmck-qty-plus', function ( e ) {
        e.preventDefault();
        var $row = $( this ).closest( '[data-cart-item-key]' );
        var cur  = parseInt( $row.find( '.ccmck-qty-value' ).first().text(), 10 ) || 1;
        ccmckUpdateQty( $row.data( 'cart-item-key' ), cur + 1 );
    } );

    // Botón "−" — decrementa cantidad (0 elimina el ítem)
    $( document ).on( 'click', '.ccmck-qty-minus', function ( e ) {
        e.preventDefault();
        var $row = $( this ).closest( '[data-cart-item-key]' );
        var cur  = parseInt( $row.find( '.ccmck-qty-value' ).first().text(), 10 ) || 1;
        ccmckUpdateQty( $row.data( 'cart-item-key' ), Math.max( 0, cur - 1 ) );
    } );

    // Botón "×" — elimina el ítem directamente
    $( document ).on( 'click', '.ccmck-remove', function ( e ) {
        e.preventDefault();
        var $row = $( this ).closest( '[data-cart-item-key]' );
        ccmckUpdateQty( $row.data( 'cart-item-key' ), 0 );
    } );

    /* ------------------------------------------------------------------ */
    /*  Floating labels (estilo Shopify)                                   */
    /*  El <label> de cada campo hace de placeholder y, al enfocar o       */
    /*  rellenar, sube y se encoge (lo anima el CSS con :placeholder-shown */
    /*  y :has). Aquí mostramos el label (P6 lo ocultaba), vaciamos el     */
    /*  placeholder nativo, acortamos algunos textos y marcamos las filas. */
    /*  selects y textarea van SIEMPRE flotados. Idempotente: se reejecuta */
    /*  en updated_checkout sin re-vaciar textos ni duplicar.              */
    /* ------------------------------------------------------------------ */
    var CCMCK_FL_SHORT = {
        billing_address_1: 'Dirección',
        billing_address_2: 'Apartamento, etc. (opcional)',
        billing_postcode:  'Código postal (opcional)'
    };

    function ccmckFloatLabels() {
        $( '.checkout-main .form-row' ).each( function () {
            var $row   = $( this );
            var $label = $row.children( 'label' ).first();
            if ( ! $label.length || $label.hasClass( 'checkbox' ) ) {
                return;
            }
            var $input  = $row.find( 'input.input-text, textarea' ).first();
            var $select = $row.find( 'select' ).first();
            $row.addClass( 'ccmck-fl-row' );

            if ( $input.length ) {
                var id = $input.attr( 'id' ) || '';
                var ph = $input.attr( 'placeholder' );
                if ( ! $label.hasClass( 'ccmck-fl' ) ) {
                    $label.text( ( CCMCK_FL_SHORT[ id ] || ( ph && ph.trim() ) || $label.text() ).toString().replace( '*', '' ).trim() );
                }
                $label.addClass( 'ccmck-fl' );
                // Vacía el placeholder nativo (incluido el de Información adicional/notas).
                if ( ph !== ' ' ) {
                    $input.attr( 'placeholder', ' ' );
                }
                if ( $input.is( 'textarea' ) ) {
                    $label.addClass( 'ccmck-fl-always' );
                }
                // Estado "lleno" estable (no depende de :placeholder-shown, que el
                // re-render de direcciones de WC altera al quitar el placeholder).
                $row.toggleClass( 'ccmck-fl-filled', $.trim( $input.val() || '' ) !== '' );
            } else if ( $select.length ) {
                var sid = $select.attr( 'id' ) || '';
                if ( ! $label.hasClass( 'ccmck-fl' ) ) {
                    $label.text( ( CCMCK_FL_SHORT[ sid ] || $label.text() ).replace( '*', '' ).trim() );
                }
                $label.addClass( 'ccmck-fl ccmck-fl-always' );
                // Vacía la opción placeholder para que no duplique al label flotado.
                var $opt = $select.find( 'option[value=""]' ).first();
                if ( $opt.length ) {
                    $opt.text( '' );
                }
            }
        } );
    }

    // Mantiene .ccmck-fl-filled al escribir (estado flotado estable).
    $( document ).on( 'input change', '.checkout-main .form-row .input-text', function () {
        $( this ).closest( '.form-row' ).toggleClass( 'ccmck-fl-filled', $.trim( $( this ).val() || '' ) !== '' );
    } );

    // Tras un refresco AJAX del checkout, reasegura las clases (idempotente).
    $( document.body ).on( 'updated_checkout', ccmckFloatLabels );
    // Estado inicial al cargar.
    $( ccmckFloatLabels );

    /* ------------------------------------------------------------------ */
    /*  Recogida local: al elegir pickup, los campos de dirección dejan    */
    /*  de ser obligatorios (UX; el servidor es la fuente de verdad).      */
    /* ------------------------------------------------------------------ */
    var CCMCK_PICKUP_ID = 'ccmck_local_pickup';
    // billing_postcode NO se incluye: es "Código postal" opcional (lo fuerza
    // CCMCK_Document::force_postcode_label), no es un campo de dirección a relajar.
    var CCMCK_ADDR_IDS  = [ 'billing_address_1', 'billing_city', 'billing_state' ];

    function ccmckSyncPickupRequired() {
        var chosen = $( 'input[name^="shipping_method"]:checked' ).val() || '';
        var pickup = chosen === CCMCK_PICKUP_ID;
        $.each( CCMCK_ADDR_IDS, function ( i, id ) {
            var $row = $( '#' + id ).closest( '.form-row' );
            if ( ! $row.length ) { return; }
            $row.toggleClass( 'validate-required', ! pickup );
            $row.toggleClass( 'ccmck-optional-pickup', pickup );
        } );
    }

    $( document ).on( 'change', 'input[name^="shipping_method"]', ccmckSyncPickupRequired );
    $( document.body ).on( 'updated_checkout', ccmckSyncPickupRequired );
    $( ccmckSyncPickupRequired );

    /* ------------------------------------------------------------------ */
    /*  Validación inline estilo Shopify (solo campos obligatorios)         */
    /*  Al pulsar "Finalizar pedido": si hay campos obligatorios vacíos,    */
    /*  bloqueamos el envío de WooCommerce y mostramos el error DEBAJO de    */
    /*  cada campo (en vez del banner rojo arriba). Nos guiamos por la clase */
    /*  .validate-required, que ccmckSyncPickupRequired ya alterna según la  */
    /*  recogida local — así pickup queda respetado sin lógica extra. Los    */
    /*  errores generales que SÍ llegan al servidor (pago/envío) se reubican */
    /*  junto al botón vía el evento checkout_error.                         */
    /* ------------------------------------------------------------------ */

    // Devuelve el control relevante de una fila (checkbox > select > input/textarea).
    function ccmckFieldControl( $row ) {
        var $cb = $row.find( 'input[type="checkbox"]' ).first();
        if ( $cb.length ) { return $cb; }
        var $sel = $row.find( 'select' ).first();
        if ( $sel.length ) { return $sel; }
        return $row.find( 'input.input-text, input[type="tel"], input[type="email"], input[type="text"], input[type="number"], textarea' ).first();
    }

    function ccmckRowEmpty( $ctrl ) {
        if ( ! $ctrl || ! $ctrl.length ) { return false; }
        if ( $ctrl.is( 'input[type="checkbox"]' ) ) {
            return ! $ctrl.is( ':checked' );
        }
        return $.trim( $ctrl.val() || '' ) === '';
    }

    // Texto del label flotado (ya limpio de '*'); minúscula inicial para la frase.
    function ccmckRowLabel( $row ) {
        var txt = $.trim( ( $row.children( 'label' ).first().text() || '' ).replace( '*', '' ) );
        return txt;
    }
    function ccmckLcFirst( s ) {
        return s ? s.charAt( 0 ).toLowerCase() + s.slice( 1 ) : s;
    }

    function ccmckSetRowError( $row, msg ) {
        $row.addClass( 'ccmck-invalid' );
        var $err = $row.children( '.ccmck-field-error' ).first();
        if ( ! $err.length ) {
            $err = $( '<span class="ccmck-field-error" role="alert"></span>' );
            $row.append( $err );
        }
        $err.text( msg );
    }

    function ccmckClearRowError( $row ) {
        $row.removeClass( 'ccmck-invalid' );
        $row.children( '.ccmck-field-error' ).remove();
    }

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

    // Valida los obligatorios visibles; pinta/limpia errores y devuelve la 1ª fila inválida.
    function ccmckValidateRequired() {
        var $first = null;
        $( '.checkout-main .form-row.validate-required' ).each( function () {
            var $row = $( this );
            if ( ! $row.is( ':visible' ) ) {
                ccmckClearRowError( $row );
                return;
            }
            var $ctrl = ccmckFieldControl( $row );
            if ( ccmckRowEmpty( $ctrl ) ) {
                var label = ccmckRowLabel( $row );
                var msg;
                if ( $ctrl.is( 'input[type="checkbox"]' ) ) {
                    msg = 'Debes marcar esta casilla para continuar.';
                } else if ( $ctrl.is( 'select' ) ) {
                    msg = label ? ( 'Selecciona ' + ccmckLcFirst( label ) ) : 'Selecciona una opción.';
                } else {
                    msg = label ? ( 'Ingresa ' + ccmckLcFirst( label ) ) : 'Este campo es obligatorio.';
                }
                ccmckSetRowError( $row, msg );
                if ( ! $first ) { $first = $row; }
            } else {
                ccmckClearRowError( $row );
            }
        } );
        return $first;
    }

    // Intercepta el submit ANTES que WooCommerce (captura en document → se ejecuta
    // antes que el handler de WC, bound en la fase de burbuja sobre form.checkout).
    document.addEventListener( 'submit', function ( e ) {
        var form = e.target;
        if ( ! form || ! form.classList || ! form.classList.contains( 'checkout' ) ) {
            return;
        }
        var $first = ccmckValidateRequired();
        if ( $first ) {
            e.preventDefault();
            e.stopImmediatePropagation(); // corta el submit de WooCommerce (sin banner arriba)
            $( 'html, body' ).animate( { scrollTop: $first.offset().top - 120 }, 300 );
            var $ctrl = ccmckFieldControl( $first );
            if ( $ctrl && $ctrl.length && $ctrl.is( ':visible' ) ) {
                $ctrl.trigger( 'focus' );
            }
        }
    }, true );

    // Limpia el error de un campo en cuanto el usuario lo corrige (no añade nuevos).
    $( document ).on( 'input change', '.checkout-main .form-row.ccmck-invalid', function ( e ) {
        var $row  = $( e.currentTarget );
        var $ctrl = ccmckFieldControl( $row );
        if ( ! ccmckRowEmpty( $ctrl ) ) {
            ccmckClearRowError( $row );
        }
    } );

    // Reparte los errores server-side de WC: cada <li> que casa con un campo se
    // pinta inline bajo ese campo (Shopify); los sobrantes (pago/genéricos) quedan
    // en un aviso compacto reubicado junto al botón. Si todos se mapean, no queda banner.
    function ccmckMapServerErrors() {
        var $group = $( 'form.checkout .woocommerce-NoticeGroup-checkout' ).first();
        if ( ! $group.length ) { return; }
        var $list = $group.find( 'ul.woocommerce-error' ).first();
        if ( ! $list.length ) { return; }

        var index     = ccmckBuildFieldIndex();
        var leftover  = [];          // HTML de los <li> sin campo
        var usedRows  = [];          // un error por fila (el primero)
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

} )( jQuery );
