/* ccm-checkout frontend — Fase 7: AJAX de carrito + acordeones + toggle móvil */
( function ( $ ) {
    'use strict';

    // Bandera para evitar peticiones simultáneas (doble clic)
    var busy = false;

    /* ------------------------------------------------------------------ */
    /*  Acordeón de métodos de pago (cosmético sobre WC)                   */
    /* ------------------------------------------------------------------ */
    $( document ).on( 'click', '.wc_payment_method label, .wc_payment_method > input', function () {
        var $li = $( this ).closest( '.wc_payment_method' );
        // Marca el radio nativo y dispara el evento WC para que los gateways reaccionen
        $li.find( 'input[name="payment_method"]' ).prop( 'checked', true ).trigger( 'change' );
        $( document.body ).trigger( 'payment_method_selected' );
        // Clase visual opcional; WC maneja la visibilidad de .payment_box
        $li.addClass( 'open' ).siblings().removeClass( 'open' );
    } );

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
