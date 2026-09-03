#!/usr/bin/env node
/**
 * Arnés de regresión para assets/ccmck-cart.js — el flujo CORE-FIRST.
 *
 * No hay infraestructura de pruebas JS en este repo (sin package.json, sin
 * jsdom) y PHPUnit no puede ejecutar JS de navegador. Este arnés monta un DOM
 * mínimo A MANO, con bubbling real hasta `document`, y ejecuta el FICHERO REAL
 * del repo con `vm` de Node — no una copia ni una reimplementación. Mismo
 * patrón que `cart-city-delegation.test.mjs`.
 *
 * Lo que vigila, y por qué cada cosa:
 *
 *   · El fichero corre SIN `window.ccmckCart`. Ya no debe depender de
 *     `ajaxUrl` ni de un nonce: quien manda la petición es WooCommerce.
 *   · Cero `fetch` propio y cero `location.reload`. Antes había los dos.
 *   · Los + / − cambian `input.qty` y respetan `min` y `max`.
 *   · Escribir a mano manda en `change`, nunca en `input`.
 *   · El 0 se manda (es como el núcleo quita una línea).
 *   · El camino termina SIEMPRE en un clic sobre `[name="update_cart"]`, que es
 *     lo que lleva a `cart_submit` -> `quantity_update` del núcleo.
 *   · Los listeners sobreviven a que `update_wc_div()` reemplace el formulario
 *     entero. Un listener enganchado al formulario inicial muere ahí.
 *   · La plantilla lleva las dos clases gancho del núcleo, y el <a> de quitar
 *     sigue siendo hijo directo.
 *
 * Uso:
 *   node cart-core-flow.test.mjs           # el árbol actual, debe salir VERDE
 *   node cart-core-flow.test.mjs --old     # el HEAD anterior, debe salir ROJO
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const REPO = path.resolve( __dirname, '..', '..' );
const JS_RELATIVE = 'assets/ccmck-cart.js';
const TPL_RELATIVE = 'templates/cart/cart.php';

const useOld = process.argv.includes( '--old' );

function leerDelRepo( relativo ) {
	if ( useOld ) {
		return execFileSync( 'git', [ '-C', REPO, 'show', 'HEAD:' + relativo ], { encoding: 'utf8' } );
	}
	return fs.readFileSync( path.join( REPO, relativo ), 'utf8' );
}

// ---------------------------------------------------------------------------
// DOM mínimo. Selectores simples: tag, .clase, [attr="valor"], y listas con
// coma. Con padres/hijos y dispatchEvent que burbujea de verdad.
// ---------------------------------------------------------------------------

function matchesSimple( el, simple ) {
	simple = simple.trim();
	const attr = simple.match( /^\[([a-zA-Z_-]+)="([^"]*)"\]$/ );
	if ( attr ) {
		return !! el.attributes && el.attributes[ attr[ 1 ] ] === attr[ 2 ];
	}
	const tagClasses = simple.match( /^([a-zA-Z0-9]*)((?:\.[a-zA-Z0-9_-]+)*)$/ );
	if ( tagClasses ) {
		const tag = tagClasses[ 1 ];
		if ( tag && el.tagName !== tag.toUpperCase() ) {
			return false;
		}
		const classes = ( tagClasses[ 2 ] || '' ).split( '.' ).filter( Boolean );
		return classes.every( ( c ) => el.classList.contains( c ) );
	}
	return false;
}

function matchesSelector( el, selector ) {
	return selector.split( ',' ).some( ( s ) => matchesSimple( el, s ) );
}

function collect( node, selector, out ) {
	( node.children || [] ).forEach( ( child ) => {
		if ( matchesSelector( child, selector ) ) {
			out.push( child );
		}
		collect( child, selector, out );
	} );
}

/** classList con `contains` (lo que usa el código) y `has` (por si acaso). */
class Clases {
	constructor( valor ) {
		this.set = new Set( String( valor || '' ).split( /\s+/ ).filter( Boolean ) );
	}
	add( c ) { this.set.add( c ); }
	remove( c ) { this.set.delete( c ); }
	contains( c ) { return this.set.has( c ); }
	has( c ) { return this.set.has( c ); }
	toString() { return Array.from( this.set ).join( ' ' ); }
}

class FakeNode {
	constructor( tag ) {
		this.tagName = tag.toUpperCase();
		this.attributes = {};
		this.classList = new Clases( '' );
		this.children = [];
		this.parentNode = null;
		this.listeners = {};
		this.value = '';
		this.disabled = false;
		this.clics = 0;
	}
	setAttribute( name, value ) {
		this.attributes[ name ] = String( value );
		if ( 'class' === name ) {
			this.classList = new Clases( value );
		}
	}
	getAttribute( name ) {
		return Object.prototype.hasOwnProperty.call( this.attributes, name ) ? this.attributes[ name ] : null;
	}
	appendChild( child ) {
		child.parentNode = this;
		this.children.push( child );
		return child;
	}
	removeChild( child ) {
		const i = this.children.indexOf( child );
		if ( i >= 0 ) { this.children.splice( i, 1 ); }
		child.parentNode = null;
		return child;
	}
	matches( selector ) { return matchesSelector( this, selector ); }
	closest( selector ) {
		let node = this;
		while ( node ) {
			if ( node.matches && node.matches( selector ) ) { return node; }
			node = node.parentNode;
		}
		return null;
	}
	querySelector( selector ) {
		const out = [];
		collect( this, selector, out );
		return out[ 0 ] || null;
	}
	querySelectorAll( selector ) {
		const out = [];
		collect( this, selector, out );
		return out;
	}
	addEventListener( type, fn ) {
		( this.listeners[ type ] = this.listeners[ type ] || [] ).push( fn );
	}
	dispatchEvent( event ) {
		event.target = this;
		let node = this;
		while ( node ) {
			const ls = node.listeners[ event.type ];
			if ( ls ) { ls.slice().forEach( ( fn ) => fn( event ) ); }
			node = node.parentNode;
		}
		return true;
	}
	/** Un clic de verdad: cuenta y burbujea, como el del navegador. */
	click() {
		this.clics++;
		this.dispatchEvent( new FakeEvent( 'click', { bubbles: true } ) );
	}
}

class FakeEvent {
	constructor( type, opciones ) {
		this.type = type;
		this.bubbles = !! ( opciones && opciones.bubbles );
		this.defaultPrevented = false;
		this.target = null;
	}
	preventDefault() { this.defaultPrevented = true; }
}

function crearDocumento() {
	const doc = new FakeNode( '#document' );
	doc.createElement = ( tag ) => new FakeNode( tag );
	return doc;
}

/**
 * Monta el carrito con la estructura real de la plantilla:
 *
 *   div.ccmck-cart > form.woocommerce-cart-form
 *     > ul.ccmck-cart__items.woocommerce-cart-form__contents
 *         > li.ccmck-item.cart_item[data-key]
 *             > div.ccmck-qty > button.ccmck-qty__menos + input.qty + button.ccmck-qty__mas
 *             > span.ccmck-item__remove.product-remove > a.remove
 *     > button[name="update_cart"]
 */
function montarCarrito( doc, opciones ) {
	const o = opciones || {};
	const cart = doc.createElement( 'div' );
	cart.setAttribute( 'class', 'ccmck-cart' );

	const form = doc.createElement( 'form' );
	form.setAttribute( 'class', 'woocommerce-cart-form ccmck-cart__form' );
	cart.appendChild( form );

	const ul = doc.createElement( 'ul' );
	ul.setAttribute( 'class', 'ccmck-cart__items woocommerce-cart-form__contents' );
	form.appendChild( ul );

	const li = doc.createElement( 'li' );
	li.setAttribute( 'class', 'ccmck-item cart_item' );
	li.setAttribute( 'data-key', 'abc123' );
	ul.appendChild( li );

	const qtyBox = doc.createElement( 'div' );
	qtyBox.setAttribute( 'class', 'ccmck-qty' );
	li.appendChild( qtyBox );

	const menos = doc.createElement( 'button' );
	menos.setAttribute( 'type', 'button' );
	menos.setAttribute( 'class', 'ccmck-qty__menos' );
	qtyBox.appendChild( menos );

	const campo = doc.createElement( 'input' );
	campo.setAttribute( 'class', 'qty' );
	campo.setAttribute( 'type', 'number' );
	campo.setAttribute( 'name', 'cart[abc123][qty]' );
	campo.setAttribute( 'min', String( 'min' in o ? o.min : 0 ) );
	if ( 'max' in o && null !== o.max ) { campo.setAttribute( 'max', String( o.max ) ); }
	campo.value = String( 'valor' in o ? o.valor : 2 );
	qtyBox.appendChild( campo );

	const mas = doc.createElement( 'button' );
	mas.setAttribute( 'type', 'button' );
	mas.setAttribute( 'class', 'ccmck-qty__mas' );
	qtyBox.appendChild( mas );

	const quitarBox = doc.createElement( 'span' );
	quitarBox.setAttribute( 'class', 'ccmck-item__remove product-remove' );
	li.appendChild( quitarBox );

	const quitar = doc.createElement( 'a' );
	quitar.setAttribute( 'class', 'remove' );
	quitarBox.appendChild( quitar );

	const actualizar = doc.createElement( 'button' );
	actualizar.setAttribute( 'type', 'submit' );
	actualizar.setAttribute( 'name', 'update_cart' );
	actualizar.disabled = true;
	form.appendChild( actualizar );

	doc.appendChild( cart );

	return { cart, form, ul, li, menos, campo, mas, quitarBox, quitar, actualizar };
}

function correrEnSandbox( source, doc, extras ) {
	const registro = { fetch: 0, reload: 0 };
	const win = {
		location: { reload: () => { registro.reload++; } },
		// A PROPÓSITO sin `ccmckCart`: el fichero nuevo no puede depender de él.
		...( extras && extras.window ? extras.window : {} ),
	};
	const sandbox = {
		document: doc,
		window: win,
		Event: FakeEvent,
		fetch: () => { registro.fetch++; return Promise.resolve( { json: () => Promise.resolve( {} ) } ); },
		console,
		parseInt,
		isNaN,
		String,
		Number,
		Object,
		Promise,
		setTimeout,
		FormData: function FormData() { this.append = () => {}; },
	};
	vm.createContext( sandbox );
	vm.runInContext( source, sandbox, { filename: JS_RELATIVE } );
	return { sandbox, registro, win };
}

// ---------------------------------------------------------------------------
// Casos
// ---------------------------------------------------------------------------

const fuente = leerDelRepo( JS_RELATIVE );
const plantilla = leerDelRepo( TPL_RELATIVE );
const fallos = [];
let pasados = 0;

function caso( nombre, fn ) {
	try {
		fn();
		pasados++;
		console.log( '  ok   ' + nombre );
	} catch ( err ) {
		fallos.push( { nombre, err } );
		console.log( '  FALLO ' + nombre + '\n         ' + String( err.message ).split( '\n' )[ 0 ] );
	}
}

caso( 'el + sube la cantidad y conduce al submit del núcleo', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 2 } );
	const { registro } = correrEnSandbox( fuente, doc );

	dom.mas.click();

	assert.equal( dom.campo.value, '3', 'el campo tiene que subir a 3' );
	assert.equal( dom.actualizar.clics, 1, 'un solo clic en [name=update_cart]' );
	assert.equal( dom.actualizar.disabled, false, 'el botón llega disabled y hay que habilitarlo' );
	assert.equal( registro.fetch, 0, 'cero fetch propio' );
	assert.equal( registro.reload, 0, 'cero location.reload' );
} );

caso( 'el − baja la cantidad', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 3 } );
	correrEnSandbox( fuente, doc );

	dom.menos.click();

	assert.equal( dom.campo.value, '2' );
	assert.equal( dom.actualizar.clics, 1 );
} );

caso( 'escribir a mano manda en change', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 2 } );
	correrEnSandbox( fuente, doc );

	dom.campo.value = '7';
	dom.campo.dispatchEvent( new FakeEvent( 'change', { bubbles: true } ) );

	assert.equal( dom.actualizar.clics, 1, 'el change tiene que mandar' );
} );

caso( 'escribir NO manda en input (una petición, no una por tecla)', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 2 } );
	correrEnSandbox( fuente, doc );

	dom.campo.value = '1';
	dom.campo.dispatchEvent( new FakeEvent( 'input', { bubbles: true } ) );
	dom.campo.value = '12';
	dom.campo.dispatchEvent( new FakeEvent( 'input', { bubbles: true } ) );

	assert.equal( dom.actualizar.clics, 0, 'ningún input puede mandar' );
} );

caso( 'el tope de stock frena el +', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 3, max: 3 } );
	correrEnSandbox( fuente, doc );

	dom.mas.click();

	assert.equal( dom.campo.value, '3', 'no puede pasar del max' );
	assert.equal( dom.actualizar.clics, 0, 'y no manda nada' );
} );

caso( 'el suelo frena el − en el mínimo', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 0, min: 0 } );
	correrEnSandbox( fuente, doc );

	dom.menos.click();

	assert.equal( dom.campo.value, '0' );
	assert.equal( dom.actualizar.clics, 0 );
} );

caso( 'bajar a 0 SÍ se manda: es como el núcleo quita una línea', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 1, min: 0 } );
	correrEnSandbox( fuente, doc );

	dom.menos.click();

	assert.equal( dom.campo.value, '0' );
	assert.equal( dom.actualizar.clics, 1, 'el 0 tiene que llegar al servidor' );
} );

caso( 'un formulario en `processing` no recibe otra petición', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 2 } );
	correrEnSandbox( fuente, doc );

	dom.form.classList.add( 'processing' );   // lo que hace block() del núcleo
	dom.mas.click();

	assert.equal( dom.campo.value, '3', 'el número sí cambia (es UX optimista)' );
	assert.equal( dom.actualizar.clics, 0, 'pero no se manda una segunda petición' );
} );

caso( 'los listeners sobreviven a que update_wc_div reemplace el formulario', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 2 } );
	correrEnSandbox( fuente, doc );

	// `update_wc_div()` hace $('.woocommerce-cart-form').replaceWith($new_form):
	// el formulario de arriba deja de existir y aparece otro, con objetos JS
	// distintos. Un listener enganchado al formulario inicial muere aquí.
	dom.cart.removeChild( dom.form );
	const nuevo = montarCarrito( doc, { valor: 5 } );
	dom.cart.appendChild( nuevo.form );

	nuevo.mas.click();

	assert.equal( nuevo.campo.value, '6', 'el formulario NUEVO tiene que responder' );
	assert.equal( nuevo.actualizar.clics, 1 );
} );

caso( 'funciona sin `window.ccmckCart`: ya no depende de ajaxUrl ni nonce', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 2 } );
	const { win } = correrEnSandbox( fuente, doc );

	assert.equal( 'ccmckCart' in win, false, 'el arnés no le da config a propósito' );
	dom.mas.click();
	assert.equal( dom.actualizar.clics, 1, 'y aun así conduce el flujo' );
} );

caso( 'marca `.ccmck-js` en el contenedor, que sobrevive al replaceWith', () => {
	const doc = crearDocumento();
	const dom = montarCarrito( doc, { valor: 2 } );
	correrEnSandbox( fuente, doc );

	assert.equal( dom.cart.classList.contains( 'ccmck-js' ), true, 'sin la marca el CSS no esconde el botón manual' );
	assert.equal( dom.form.classList.contains( 'ccmck-js' ), false, 'y NO va en el formulario, que es lo que se reemplaza' );
} );

caso( 'el fichero no contiene fetch, reload ni el endpoint viejo', () => {
	const sinComentarios = fuente
		.replace( /\/\*[\s\S]*?\*\//g, '' )
		.replace( /^\s*\/\/.*$/gm, '' );

	assert.equal( /fetch\s*\(/.test( sinComentarios ), false, 'cero fetch propio' );
	assert.equal( /location\s*\.\s*reload/.test( sinComentarios ), false, 'cero location.reload' );
	assert.equal( /ccmck_update_cart_item/.test( sinComentarios ), false, 'el endpoint viejo no puede tener callers' );
	assert.equal( /ajaxUrl/.test( sinComentarios ), false, 'cero dependencia de ajaxUrl' );
	assert.equal( /trigger\s*\(\s*['"]wc_update_cart/.test( sinComentarios ), false, 'wc_update_cart refresca pero no aplica' );
	assert.equal( /trigger\s*\(\s*['"]updated_wc_div/.test( sinComentarios ), false, 'los eventos los emite WooCommerce' );
} );

caso( 'la plantilla lleva woocommerce-cart-form__contents', () => {
	assert.match( plantilla, /class="ccmck-cart__items woocommerce-cart-form__contents"/,
		'sin esa clase cart_submit() del núcleo aborta y ningún cambio llega' );
} );

caso( 'la plantilla lleva product-remove con el <a> como hijo directo', () => {
	assert.match( plantilla, /class="ccmck-item__remove product-remove"/,
		'el núcleo escucha en .woocommerce-cart-form .product-remove > a' );
	const tramo = plantilla.slice( plantilla.indexOf( 'ccmck-item__remove product-remove' ) );
	const hasta = tramo.slice( 0, tramo.indexOf( '</span>' ) );
	assert.match( hasta, /<a href=/, 'el <a> tiene que seguir siendo hijo directo del span' );
} );

caso( 'la plantilla conserva el botón manual como degradación', () => {
	assert.match( plantilla, /name="update_cart"/, 'sin JS el botón tiene que seguir ahí' );
	assert.match( plantilla, /class="woocommerce-cart-form/, 'y el formulario con su clase' );
} );

console.log( '\n' + ( fallos.length
	? '  ' + fallos.length + ' FALLO(S), ' + pasados + ' ok' + ( useOld ? '   <- ROJO contra HEAD, que es lo esperado' : '' )
	: '  ' + pasados + ' casos en verde' ) + '\n' );

process.exit( fallos.length ? 1 : 0 );
