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

    // Cambio de método: el click en radio/label dispara 'change' de forma nativa.
    $( document ).on( 'change', 'input[name="payment_method"]', ccmckSyncSelectedPayment );
    // Tras un refresco AJAX del checkout, WooCommerce repinta #payment: re-sincronizamos.
    $( document.body ).on( 'updated_checkout', ccmckSyncSelectedPayment );
    // Estado inicial al cargar.
    $( ccmckSyncSelectedPayment );

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
    // billing_postcode NO se incluye: está rotulado "Cédula / NIT" y sigue obligatorio.
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

} )( jQuery );
