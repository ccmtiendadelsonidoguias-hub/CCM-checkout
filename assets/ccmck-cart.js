/**
 * Cantidades del carrito, sin recargar a mano.
 *
 * Mejora progresiva: si esto no corre, la reserva es el botón "Actualizar
 * carrito" que pinta nuestra plantilla del carrito. Es NUESTRO, no el del
 * núcleo: el del núcleo vivía en la tabla que sustituimos, y con ella se fue.
 * Con él, escribir una cantidad en el campo y enviar el formulario sigue
 * cambiando el carrito, con una recarga completa.
 *
 * Por eso lo primero que hace este archivo es marcar el contenedor con
 * `ccmck-js`: el CSS esconde ese botón solo cuando esto corre.
 */
( function () {
	'use strict';

	var config = window.ccmckCart;

	if ( ! config || ! config.ajaxUrl || ! config.nonce ) {
		return;
	}

	// Marca de que esto está vivo. Va DESPUÉS de la comprobación de arriba a
	// propósito: sin `config` no hay AJAX, y entonces el botón "Actualizar
	// carrito" tiene que seguir visible.
	var contenedor = document.querySelector( '.ccmck-cart' );

	if ( contenedor ) {
		contenedor.classList.add( 'ccmck-js' );
	}

	// Una peticion a la vez. Sin esto hay carrera: escribir una cantidad y
	// pulsar el mas dispara el  (envia lo escrito) y el clic (envia
	// escrito+1), y gana el que responda ultimo. Deshabilitar el control que
	// disparo no basta: son controles distintos.
	var enviando = false;

	function campoDe( boton ) {
		var fila = boton.closest( '.ccmck-item' );
		return fila ? fila.querySelector( 'input.qty' ) : null;
	}

	// `control` es el elemento que disparó el envío: el botón de más o menos, o
	// el propio campo de cantidad cuando se escribe a mano. Los dos aceptan
	// `disabled`, que es lo único que se les hace aquí.
	function enviar( clave, cantidad, control ) {
		if ( enviando ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'ccmck_update_cart_item' );
		body.append( 'nonce', config.nonce );
		body.append( 'key', clave );
		body.append( 'qty', cantidad );

		enviando         = true;
		control.disabled = true;

		fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'no se pudo actualizar' );
				}
				// Se recarga a propósito: los importes dependen del envío
				// cotizado, de los recargos y de los cupones. Reconstruirlos
				// aquí sería reimplementar el carrito de WooCommerce.
				window.location.reload();
			} )
			.catch( function () {
				enviando         = false;
				control.disabled = false;
				// Recuperación: el botón "Actualizar carrito" de nuestra
				// plantilla sigue ahí y hace lo mismo con una recarga completa.
				// Un nonce caducado entra por aquí: el endpoint responde `-1`,
				// que no es JSON, y r.json() lanza.
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var boton = event.target.closest( '.ccmck-qty__mas, .ccmck-qty__menos' );

		if ( ! boton ) {
			return;
		}

		var fila  = boton.closest( '.ccmck-item' );
		var campo = campoDe( boton );

		if ( ! fila || ! campo ) {
			return;
		}

		event.preventDefault();

		var actual = parseInt( campo.value, 10 ) || 0;
		var maximo = parseInt( campo.getAttribute( 'max' ), 10 );
		var nueva  = boton.classList.contains( 'ccmck-qty__mas' ) ? actual + 1 : actual - 1;

		if ( nueva < 0 ) {
			return;
		}

		// El tope lo pone el stock, que WooCommerce ya escribió en `max`.
		// Es comodidad, no una frontera: comprobado que el endpoint acepta de
		// buen grado una cantidad por encima del stock, y quien avisa después
		// es la página del carrito, igual que con "Actualizar carrito".
		if ( ! isNaN( maximo ) && nueva > maximo ) {
			return;
		}

		enviar( fila.getAttribute( 'data-key' ), nueva, boton );
	} );

	// Escribir la cantidad a mano. Sin esto el campo es decorativo mientras el
	// botón "Actualizar carrito" esté escondido por CSS.
	document.addEventListener( 'change', function ( event ) {
		var campo = event.target;

		if ( ! campo.matches || ! campo.matches( '.ccmck-item input.qty' ) ) {
			return;
		}

		var fila = campo.closest( '.ccmck-item' );

		if ( ! fila ) {
			return;
		}

		var nueva = parseInt( campo.value, 10 );

		// Vacío o negativo: no se envía nada y el campo se queda como está.
		// Cero, si es válido, sí se envía: es como el núcleo quita una línea.
		if ( isNaN( nueva ) || nueva < 0 ) {
			return;
		}

		// El tope de stock no se comprueba aquí, al contrario que en el más: si
		// alguien escribe más de lo que hay, quien avisa es la página del
		// carrito al recargar, igual que con "Actualizar carrito".
		enviar( fila.getAttribute( 'data-key' ), nueva, campo );
	} );
} )();
