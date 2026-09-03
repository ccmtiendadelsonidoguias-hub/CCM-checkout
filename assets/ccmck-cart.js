/**
 * Cantidad en la página del carrito. SOLO UX.
 *
 * ANTES este fichero hacía su propio AJAX a `ccmck_update_cart_item` y después
 * un `window.location.reload()` para repintar. Medido en dev: 2 peticiones por
 * acción, p50 1.912 ms, de los cuales 1.198 eran la recarga. Y el flete se
 * calculaba UNA sola vez —en el POST—, no dos: la recarga lo servía de la caché
 * de sesión de WooCommerce sin una llamada a `rates()`.
 *
 * AHORA no hay AJAX propio. `cart.js` de WooCommerce 10.7.0 ya trae el contrato
 * completo y este fichero solo conduce el formulario hasta él:
 *
 *   clic en + / −     cambia `input.qty` y dispara `change`
 *   `change`          clic en `[name="update_cart"]`
 *   ese clic          `submit_click` marca el botón, y el submit REAL entra en
 *                     `cart_submit`, que llama a `quantity_update`
 *   `quantity_update` inyecta el hidden `update_cart`, bloquea el formulario y
 *                     `div.cart_totals`, serializa, hace UNA petición y repinta
 *                     con `update_wc_div`
 *
 * Los eventos —`updated_cart_totals`, `updated_wc_div`, `wc_cart_emptied`— los
 * emite WooCommerce. Aquí NO se dispara ninguno a mano: hacerlo le mentiría a
 * los plugins que los escuchan.
 *
 * POR QUÉ NO `wc_update_cart`
 * Está a un `trigger` de distancia y es la tentación obvia, pero el spike lo
 * midió: refresca y NO aplica la cantidad.
 * `WC_Form_Handler::update_cart_action()` sale antes de hacer nada si no ve
 * `update_cart` en la petición, y `$form.serialize()` de jQuery no incluye los
 * `<button>`. El único camino que sí la aplica es el submit, porque
 * `quantity_update()` se inyecta ese campo él mismo.
 *
 * POR QUÉ TODO DELEGADO EN `document`
 * `update_wc_div()` hace `$('.woocommerce-cart-form').replaceWith($new_form)`:
 * cualquier listener enganchado al formulario o a sus hijos muere en el primer
 * cambio. Es el mismo gotcha que ya documenta `ccmck-cart-city.js`.
 *
 * POR QUÉ NO HAY CANDADO PROPIO
 * `cart.js` marca el formulario con la clase `processing` mientras tiene una
 * petición en vuelo, y su propio `cart_submit` se niega a mandar otra. Mirar
 * ESA marca —en vez de inventar una bandera nuestra— es lo que evita el doble
 * envío cuando el Enter dispara `keypress` y `change` seguidos, y no puede
 * desincronizarse de su estado real.
 *
 * DEGRADACIÓN
 * Sin este fichero el botón «Actualizar carrito» sigue visible (el CSS solo lo
 * esconde cuando existe `.ccmck-js`) y el formulario funciona por POST normal.
 * Y con él, el clic va a un `<button type="submit">` de verdad: si `cart.js` no
 * llegara a interceptar, el navegador manda el formulario y WooCommerce lo
 * procesa igual. No queda ningún camino muerto.
 */

( function () {
	'use strict';

	var SEL_CARRITO = '.ccmck-cart';
	var SEL_FORM    = 'form.woocommerce-cart-form';
	var SEL_FILA    = '.ccmck-item';
	var SEL_QTY     = 'input.qty';
	var SEL_MAS     = '.ccmck-qty__mas';
	var SEL_MENOS   = '.ccmck-qty__menos';
	var SEL_UPDATE  = '[name="update_cart"]';

	// Marca de que esto está vivo: el CSS esconde el botón manual solo si existe.
	// Va sobre `.ccmck-cart`, que es PADRE del formulario y por tanto sobrevive
	// al `replaceWith` de `update_wc_div`.
	var contenedor = document.querySelector( SEL_CARRITO );

	if ( contenedor ) {
		contenedor.classList.add( 'ccmck-js' );
	}

	/** ¿Hay una petición del carrito en vuelo? Lo dice la marca de `cart.js`. */
	function ocupado( nodo ) {
		while ( nodo ) {
			if ( nodo.classList && nodo.classList.contains( 'processing' ) ) {
				return true;
			}
			nodo = nodo.parentNode;
		}
		return false;
	}

	/**
	 * Conduce el formulario al flujo `update_cart` del núcleo.
	 *
	 * El botón llega `disabled` en cada formulario nuevo (lo desactiva
	 * `update_wc_div`), así que se habilita antes de pulsarlo. Se busca fresco
	 * cada vez porque el formulario de hace un momento ya no está en el DOM.
	 */
	function pedirActualizacion( form ) {
		if ( ! form || ocupado( form ) ) {
			return;
		}

		var boton = form.querySelector( SEL_UPDATE );

		if ( ! boton ) {
			return;
		}

		boton.disabled = false;
		boton.click();
	}

	/** El campo de cantidad de la fila a la que pertenece `nodo`. */
	function campoDe( nodo ) {
		var fila = nodo.closest( SEL_FILA );

		return fila ? fila.querySelector( SEL_QTY ) : null;
	}

	/** Entero de un atributo, o `null` si no lo hay o no es un número. */
	function entero( campo, nombre ) {
		var valor = parseInt( campo.getAttribute( nombre ), 10 );

		return isNaN( valor ) ? null : valor;
	}

	// --- + y − -------------------------------------------------------------
	// Delegado en `document`. Solo cambia el valor y avisa con `change`: quien
	// manda la petición es el único camino de abajo.
	document.addEventListener( 'click', function ( event ) {
		var boton = event.target.closest( SEL_MAS + ', ' + SEL_MENOS );

		if ( ! boton ) {
			return;
		}

		var campo = campoDe( boton );
		var form  = boton.closest( SEL_FORM );

		if ( ! campo || ! form ) {
			return;
		}

		event.preventDefault();

		var actual = parseInt( campo.value, 10 );

		if ( isNaN( actual ) ) {
			actual = 0;
		}

		var sube  = boton.classList.contains( 'ccmck-qty__mas' );
		var nueva = sube ? actual + 1 : actual - 1;
		var min   = entero( campo, 'min' );
		var max   = entero( campo, 'max' );

		// El suelo lo pone `min`: en el carrito WooCommerce lo deja en 0 para
		// que bajar hasta cero quite la línea. Sin atributo, 0 es el suelo.
		if ( nueva < ( null === min ? 0 : min ) ) {
			return;
		}

		// El techo es comodidad, no frontera: quien avisa de verdad al pasarse
		// de stock es el carrito al repintar, igual que con «Actualizar carrito».
		if ( null !== max && nueva > max ) {
			return;
		}

		if ( nueva === actual ) {
			return;
		}

		campo.value = String( nueva );
		campo.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	// --- `change`: el ÚNICO sitio que manda ---------------------------------
	// Vale para los botones (que lo disparan arriba) y para escribir a mano.
	// En `change`, nunca en `input`: escribir «12» no puede costar dos
	// peticiones. El Enter no pasa por aquí — lo atiende `input_keypress` del
	// núcleo, que bloquea el formulario, y entonces `ocupado()` corta este.
	document.addEventListener( 'change', function ( event ) {
		var campo = event.target;

		if ( ! campo || ! campo.matches || ! campo.matches( SEL_QTY ) ) {
			return;
		}

		if ( ! campo.closest( SEL_FILA ) ) {
			return;
		}

		var form = campo.closest( SEL_FORM );

		if ( ! form ) {
			return;
		}

		var nueva = parseInt( campo.value, 10 );

		// Vacío o negativo: no se manda nada y el campo se queda como está.
		// El cero SÍ se manda: es como el núcleo quita una línea.
		if ( isNaN( nueva ) || nueva < 0 ) {
			return;
		}

		pedirActualizacion( form );
	} );
} )();
