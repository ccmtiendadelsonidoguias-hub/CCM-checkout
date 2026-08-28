/**
 * Repuebla el desplegable de ciudad del carrito al cambiar de departamento.
 * Mejora, no requisito: sin JS el formulario se envía igual y el servidor
 * devuelve la lista correcta.
 */
( function () {
	'use strict';

	function limpiar( select ) {
		while ( select.firstChild ) {
			select.removeChild( select.firstChild );
		}
	}

	function opcion( valor, etiqueta ) {
		var el = document.createElement( 'option' );
		el.value = valor;
		el.textContent = etiqueta;
		return el;
	}

	function ciudades( form ) {
		var estado = form.querySelector( '[name="calc_shipping_state"]' );
		var ciudad = form.querySelector( '[name="calc_shipping_city"]' );
		if ( ! estado || ! ciudad ) {
			return;
		}

		// Contador de peticiones: si el departamento cambia dos veces seguidas,
		// una respuesta vieja que llega tarde no debe pisar la mas reciente.
		var secuencia = 0;

		estado.addEventListener( 'change', function () {
			var propia = ++secuencia;

			ciudad.disabled = true;
			limpiar( ciudad );
			ciudad.appendChild( opcion( '', window.ccmckCartCity.cargando || '' ) );

			fetch( window.ccmckCartCity.rest + '?departamento=' + encodeURIComponent( estado.value ) )
				.then( function ( r ) { return r.ok ? r.json() : { opciones: {} }; } )
				.then( function ( data ) {
					if ( propia !== secuencia ) {
						return; // Llego tarde: ya hay una peticion mas nueva en curso.
					}
					var opciones = data.opciones || {};
					var claves = Object.keys( opciones );

					limpiar( ciudad );
					ciudad.appendChild( opcion( '', claves.length ? window.ccmckCartCity.elige : window.ccmckCartCity.vacio ) );
					claves.forEach( function ( valor ) {
						ciudad.appendChild( opcion( valor, opciones[ valor ] ) );
					} );
					ciudad.disabled = 0 === claves.length;
				} )
				.catch( function () {
					if ( propia !== secuencia ) {
						return; // Tambien aplica al fallo: no pisar un resultado mas nuevo.
					}
					// Fallo de red: decirlo, no dejar "Cargando..." para siempre ni un
					// desplegable habilitado sin ninguna ciudad elegible (eso se ve
					// igual que "no hay envio" y nadie lo nota). El formulario se
					// puede enviar igual: recarga completa y el servidor repuebla el
					// desplegable correcto desde la sesion.
					limpiar( ciudad );
					ciudad.appendChild( opcion( '', window.ccmckCartCity.error || '' ) );
					ciudad.disabled = true;
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'form.woocommerce-shipping-calculator, .shipping-calculator-form' ).forEach( ciudades );
	} );
}() );
