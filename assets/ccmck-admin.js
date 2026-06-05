/**
 * ccmck-admin.js
 *
 * Gestión dinámica de la página de ajustes del CCM Checkout.
 *
 * Responsabilidades:
 *   - Color picker (wp-color-picker) para inputs .ccmck-color.
 *   - Repeaters genéricos: añadir / eliminar filas con token __i__.
 *   - Media picker de WordPress para botones .ccmck-upload.
 *   - Sortable (jQuery UI) para reordenar pasarelas de pago.
 *   - Antes del submit, reordena los hidden inputs ccmck_settings[payment_order][]
 *     para reflejar el orden visual actual de las filas.
 */

( function ( $ ) {
	'use strict';

	/* =========================================================
	   Índice de repeater — contador global por repeater.
	   ========================================================= */

	/**
	 * Devuelve el siguiente índice disponible para un repeater dado.
	 * Busca el máximo data-index existente y suma 1.
	 *
	 * @param  {jQuery} $repeater Contenedor .ccmck-repeater.
	 * @return {number}
	 */
	function nextIndex( $repeater ) {
		var max = -1;
		$repeater.find( '.ccmck-row:not(.ccmck-row-template)' ).each( function () {
			var idx = parseInt( $( this ).attr( 'data-index' ), 10 );
			if ( ! isNaN( idx ) && idx > max ) {
				max = idx;
			}
		} );
		return max + 1;
	}

	/* =========================================================
	   INICIALIZACIÓN
	   ========================================================= */

	$( document ).ready( function () {
		initColorPickers( $( document ) );
		initRepeaters();
		initMediaUpload();
		initPaymentSortable();
		hookFormSubmit();
	} );

	/* =========================================================
	   COLOR PICKERS
	   ========================================================= */

	/**
	 * Inicializa wp-color-picker en todos los .ccmck-color dentro del scope dado.
	 *
	 * @param {jQuery} $scope Elemento contenedor en el que buscar.
	 */
	function initColorPickers( $scope ) {
		$scope.find( '.ccmck-color' ).each( function () {
			var $input = $( this );
			// Evitar re-inicializar si ya tiene el widget.
			if ( $input.hasClass( 'wp-color-picker' ) ) {
				return;
			}
			$input.wpColorPicker();
		} );
	}

	/* =========================================================
	   REPEATERS
	   ========================================================= */

	function initRepeaters() {

		/* --- Añadir fila --- */
		$( document ).on( 'click', '.ccmck-add-row', function () {
			var repeaterId = $( this ).data( 'repeater' );
			var $repeater  = $( '#' + repeaterId );
			if ( ! $repeater.length ) {
				return;
			}

			var idx      = nextIndex( $repeater );
			var $template = $repeater.find( '.ccmck-row-template' );
			if ( ! $template.length ) {
				return;
			}

			// Clonar la plantilla (sin el flag de template).
			var $newRow = $template.clone();
			$newRow.removeClass( 'ccmck-row-template' );
			$newRow.removeAttr( 'style' );
			$newRow.attr( 'data-index', String( idx ) );

			// Reemplazar el token __i__ en atributos name, id y for (y value si corresponde).
			$newRow.find( '[name]' ).each( function () {
				var name = $( this ).attr( 'name' ).replace( /__i__/g, String( idx ) );
				$( this ).attr( 'name', name );
			} );
			$newRow.find( '[id]' ).each( function () {
				var id = $( this ).attr( 'id' ).replace( /__i__/g, String( idx ) );
				$( this ).attr( 'id', id );
			} );
			$newRow.find( '[for]' ).each( function () {
				var forAttr = $( this ).attr( 'for' ).replace( /__i__/g, String( idx ) );
				$( this ).attr( 'for', forAttr );
			} );

			// Insertar antes del botón "Añadir".
			$( this ).before( $newRow );

			// Inicializar color pickers dentro de la nueva fila.
			initColorPickers( $newRow );
		} );

		/* --- Eliminar fila --- */
		$( document ).on( 'click', '.ccmck-remove-row', function () {
			$( this ).closest( '.ccmck-row' ).remove();
		} );
	}

	/* =========================================================
	   MEDIA UPLOAD
	   ========================================================= */

	function initMediaUpload() {
		if ( typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		$( document ).on( 'click', '.ccmck-upload', function ( e ) {
			e.preventDefault();

			var $btn        = $( this );
			// Soporte para data-target (ID del input) o data-target-name (name del input).
			var targetId    = $btn.data( 'target' );
			var targetName  = $btn.data( 'target-name' );

			var frame = wp.media( {
				title:    'Seleccionar imagen',
				multiple: false,
				library:  { type: 'image' },
				button:   { text: 'Usar imagen' },
			} );

			frame.on( 'select', function () {
				var selection  = frame.state().get( 'selection' ).first();
				if ( ! selection ) {
					return;
				}
				var attachment = selection.toJSON();
				var url        = attachment.url || '';

				if ( targetId ) {
					$( '#' + targetId ).val( url );
				} else if ( targetName ) {
					$( '[name="' + targetName + '"]' ).val( url );
				}
			} );

			frame.open();
		} );
	}

	/* =========================================================
	   SORTABLE DE PASARELAS DE PAGO
	   ========================================================= */

	function initPaymentSortable() {
		var $list = $( '#ccmck-payment-gateways-list' );
		if ( ! $list.length ) {
			return;
		}

		// jQuery UI Sortable está disponible en la admin de WordPress.
		if ( $.fn.sortable ) {
			$list.sortable( {
				handle:    '.ccmck-gateway-handle',
				axis:      'y',
				tolerance: 'pointer',
			} );
		}
	}

	/* =========================================================
	   SERIALIZACIÓN DEL ORDEN DE PAGO EN SUBMIT
	   ========================================================= */

	/**
	 * Antes de enviar el formulario, actualiza los hidden inputs
	 * ccmck_settings[payment_order][] para que reflejen el orden
	 * visual actual de las filas .ccmck-gateway-row.
	 *
	 * Cada fila ya contiene un <input type="hidden" class="ccmck-payment-order-input">
	 * con el ID de la pasarela. El sortable solo mueve el DOM, así que
	 * el orden de los inputs refleja automáticamente el orden de las filas.
	 * Este hook es un seguro explícito: reasigna los values en el orden actual.
	 */
	function hookFormSubmit() {
		var $form = $( 'form' ).has( '#ccmck-payment-gateways-list' ).first();
		if ( ! $form.length ) {
			// Fallback: any options.php form on this page.
			$form = $( 'form[action="options.php"]' ).first();
		}

		$form.on( 'submit', function () {
			// Recoger ids en orden actual del DOM y reasignar a sus inputs hidden.
			var $rows = $( '#ccmck-payment-gateways-list .ccmck-gateway-row' );
			$rows.each( function () {
				var id     = $( this ).data( 'gateway-id' );
				var $input = $( this ).find( '.ccmck-payment-order-input' );
				$input.val( id );
			} );
		} );
	}

} )( jQuery );
