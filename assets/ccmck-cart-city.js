/**
 * Repuebla el desplegable de ciudad del carrito al cambiar de departamento.
 * Mejora, no requisito: sin JS el formulario se envía igual y el servidor
 * devuelve la lista correcta.
 *
 * Delegado en `document`, NUNCA enganchado al elemento en sí. WooCommerce
 * reemplaza el bloque de la calculadora constantemente: cart.js hace
 * `$('.cart_totals').replaceWith(...)` al elegir método de envío, y
 * update_wc_div lo rehace al enviar la calculadora, cambiar cantidades,
 * quitar un ítem o aplicar un cupón; country-select.js reemplaza el <select>
 * de departamento al cambiar de país. Un listener atado al nodo de esa
 * primera carga queda huérfano en cuanto WooCommerce sustituye el bloque: el
 * desplegable de ciudad se queda con las opciones del departamento anterior,
 * y ESE valor viejo sí se postea. Delegar en `document` sobrevive a todos
 * esos reemplazos porque el `change` se resuelve en el momento (mira
 * `e.target`), no contra una referencia guardada al enganchar.
 *
 * También evita el doble listener de la versión anterior: el selector
 * `'form.woocommerce-shipping-calculator, .shipping-calculator-form'` casaba
 * a la vez el <form> y la <section> de dentro, así que el mismo <select> de
 * departamento recibía DOS listeners (y disparaba dos peticiones REST por
 * cambio). Con un único listener en `document` eso desaparece: no importa
 * cuántos contenedores casen, el 'change' burbujea una sola vez.
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

	// Contador de peticiones: si el departamento cambia dos veces seguidas,
	// una respuesta vieja que llega tarde no debe pisar la mas reciente. Vive
	// a nivel de módulo (no por nodo) porque el listener tambien es único.
	var secuencia = 0;

	document.addEventListener( 'change', function ( e ) {
		var estado = e.target;
		if ( ! estado || 'function' !== typeof estado.matches || ! estado.matches( '[name="calc_shipping_state"]' ) ) {
			return;
		}
		// Resuelto en cada evento, nunca cacheado: el <form> (y lo que haya
		// dentro) puede ser un nodo distinto al de la primera carga.
		var form = estado.closest( 'form' );
		var ciudad = form ? form.querySelector( '[name="calc_shipping_city"]' ) : null;
		if ( ! ciudad ) {
			return;
		}

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
}() );
