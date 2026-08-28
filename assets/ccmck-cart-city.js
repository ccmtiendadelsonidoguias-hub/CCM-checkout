/**
 * Repuebla el desplegable de ciudad del carrito al cambiar de departamento.
 * Mejora, no requisito: sin JS el formulario se envía igual y el servidor
 * devuelve la lista correcta.
 */
( function () {
	'use strict';

	function ciudades( form ) {
		var estado = form.querySelector( '[name="calc_shipping_state"]' );
		var ciudad = form.querySelector( '[name="calc_shipping_city"]' );
		if ( ! estado || ! ciudad ) {
			return;
		}

		estado.addEventListener( 'change', function () {
			ciudad.disabled = true;
			ciudad.innerHTML = '<option value="">' + ( window.ccmckCartCity.cargando || '' ) + '</option>';

			fetch( window.ccmckCartCity.rest + '?departamento=' + encodeURIComponent( estado.value ) )
				.then( function ( r ) { return r.ok ? r.json() : { opciones: {} }; } )
				.then( function ( data ) {
					var opciones = data.opciones || {};
					var html = '<option value="">' + window.ccmckCartCity.elige + '</option>';
					Object.keys( opciones ).forEach( function ( valor ) {
						html += '<option value="' + valor.replace( /"/g, '&quot;' ) + '">' + opciones[ valor ] + '</option>';
					} );
					ciudad.innerHTML = html;
					ciudad.disabled = Object.keys( opciones ).length === 0;
				} )
				.catch( function () {
					// Si la red falla, el formulario sigue siendo usable enviándolo.
					ciudad.disabled = false;
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'form.woocommerce-shipping-calculator, .shipping-calculator-form' ).forEach( ciudades );
	} );
}() );
