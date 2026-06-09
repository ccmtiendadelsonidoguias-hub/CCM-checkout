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
        $( this ).toggleClass( 'open' ).siblings( '.sidebar-inner' ).slideToggle( 150 );
    } );

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

} )( jQuery );
