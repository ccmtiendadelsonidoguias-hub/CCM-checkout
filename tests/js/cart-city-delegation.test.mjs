#!/usr/bin/env node
/**
 * Arnés de regresión para assets/ccmck-cart-city.js.
 *
 * No hay infraestructura de pruebas JS en este repo (sin package.json, sin
 * jsdom instalado) y el banco de PHPUnit no puede cargar ni ejecutar JS del
 * navegador. Este arnés monta un DOM mínimo A MANO (sin dependencias) con
 * bubbling real de eventos y padres/hijos, y ejecuta el ARCHIVO REAL del
 * repo con Node `vm`, no una copia ni una reimplementación.
 *
 * Simula el gotcha exacto que describe el CLAUDE.md del repo: WooCommerce
 * reemplaza el bloque de la calculadora por AJAX (cart.js hace
 * `$('.cart_totals').replaceWith(...)`, update_wc_div lo rehace en cada
 * cambio de cantidad/cupón/calculadora). Aquí se crea el DOM, se dispara un
 * 'change', se SUSTITUYE el nodo por uno nuevo (misma estructura, objetos
 * JS distintos) y se dispara otro 'change'. Un listener enganchado al nodo
 * (no delegado) muere ahí; uno delegado en `document` sobrevive.
 *
 * Uso:
 *   node cart-city-delegation.test.mjs                  # prueba el archivo actual del repo (debe salir en VERDE)
 *   node cart-city-delegation.test.mjs --old             # prueba el HEAD anterior al fix (debe salir en ROJO)
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const REPO = path.resolve( __dirname, '..', '..' );
const JS_RELATIVE = 'assets/ccmck-cart-city.js';

const useOld = process.argv.includes( '--old' );

function loadSource() {
	if ( useOld ) {
		// Contenido en HEAD (el commit de la rama antes de este fix), sea cual
		// sea el estado del árbol de trabajo ahora mismo.
		return execFileSync( 'git', [ '-C', REPO, 'show', 'HEAD:' + JS_RELATIVE ], { encoding: 'utf8' } );
	}
	return fs.readFileSync( path.join( REPO, JS_RELATIVE ), 'utf8' );
}

// ---------------------------------------------------------------------------
// DOM mínimo: selectores simples (tag, .clase, [attr="valor"], separados por
// coma), padres/hijos, y dispatchEvent con bubbling real hasta `document`.
// ---------------------------------------------------------------------------

function matchesSimple( el, simple ) {
	simple = simple.trim();
	var attr = simple.match( /^\[([a-zA-Z-]+)="([^"]*)"\]$/ );
	if ( attr ) {
		return !! el.attributes && el.attributes[ attr[ 1 ] ] === attr[ 2 ];
	}
	var tagClasses = simple.match( /^([a-zA-Z0-9]*)((?:\.[a-zA-Z0-9_-]+)*)$/ );
	if ( tagClasses ) {
		var tag = tagClasses[ 1 ];
		if ( tag && el.tagName !== tag.toUpperCase() ) {
			return false;
		}
		var classes = ( tagClasses[ 2 ] || '' ).split( '.' ).filter( Boolean );
		for ( var i = 0; i < classes.length; i++ ) {
			if ( ! el.classList.has( classes[ i ] ) ) {
				return false;
			}
		}
		return true;
	}
	return false;
}

function matchesSelector( el, selector ) {
	return selector.split( ',' ).some( function ( s ) { return matchesSimple( el, s ); } );
}

function collect( node, selector, out ) {
	( node.children || [] ).forEach( function ( child ) {
		if ( matchesSelector( child, selector ) ) {
			out.push( child );
		}
		collect( child, selector, out );
	} );
}

class FakeNode {
	constructor( tag ) {
		this.tagName = tag.toUpperCase();
		this.attributes = {};
		this.classList = new Set();
		this.children = [];
		this.parentNode = null;
		this.listeners = {};
		this.value = '';
		this.disabled = false;
		this._text = '';
	}
	setAttribute( name, value ) {
		this.attributes[ name ] = value;
		if ( 'class' === name ) {
			this.classList = new Set( String( value ).split( /\s+/ ).filter( Boolean ) );
		}
	}
	appendChild( child ) {
		child.parentNode = this;
		this.children.push( child );
		return child;
	}
	removeChild( child ) {
		var i = this.children.indexOf( child );
		if ( i >= 0 ) {
			this.children.splice( i, 1 );
		}
		child.parentNode = null;
		return child;
	}
	get firstChild() {
		return this.children[ 0 ] || null;
	}
	set textContent( v ) {
		this._text = v;
	}
	get textContent() {
		return this._text;
	}
	addEventListener( type, fn ) {
		( this.listeners[ type ] = this.listeners[ type ] || [] ).push( fn );
	}
	matches( selector ) {
		return matchesSelector( this, selector );
	}
	closest( selector ) {
		var node = this;
		while ( node ) {
			if ( node.matches && node.matches( selector ) ) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}
	querySelector( selector ) {
		var out = [];
		collect( this, selector, out );
		return out[ 0 ] || null;
	}
	querySelectorAll( selector ) {
		var out = [];
		collect( this, selector, out );
		return out;
	}
	dispatchEvent( event ) {
		event.target = this;
		var node = this;
		while ( node ) {
			var ls = node.listeners[ event.type ];
			if ( ls ) {
				ls.slice().forEach( function ( fn ) { fn( event ); } );
			}
			node = node.parentNode;
		}
		return true;
	}
}

function makeDocument() {
	var doc = new FakeNode( '#document' );
	doc.createElement = function ( tag ) { return new FakeNode( tag ); };
	return doc;
}

/** Arma form > section > p > select[state] + select[city], colgado de `document`. */
function montarCalculadora( doc ) {
	var form = doc.createElement( 'form' );
	form.setAttribute( 'class', 'woocommerce-shipping-calculator' );

	var section = doc.createElement( 'section' );
	section.setAttribute( 'class', 'shipping-calculator-form' );
	form.appendChild( section );

	var estado = doc.createElement( 'select' );
	estado.setAttribute( 'name', 'calc_shipping_state' );
	section.appendChild( estado );

	var ciudad = doc.createElement( 'select' );
	ciudad.setAttribute( 'name', 'calc_shipping_city' );
	section.appendChild( ciudad );

	doc.appendChild( form );

	return { form: form, section: section, estado: estado, ciudad: ciudad };
}

function tick( n ) {
	var p = Promise.resolve();
	for ( var i = 0; i < ( n || 6 ); i++ ) {
		p = p.then( function () { return new Promise( function ( r ) { setImmediate( r ); } ); } );
	}
	return p;
}

function correrEnSandbox( source, doc, win, fetchImpl ) {
	var sandbox = {
		document: doc,
		window: win,
		fetch: fetchImpl,
		console: console,
		encodeURIComponent: encodeURIComponent,
		setTimeout: setTimeout,
		Object: Object,
		Promise: Promise,
	};
	vm.createContext( sandbox );
	vm.runInContext( source, sandbox, { filename: JS_RELATIVE } );
	return sandbox;
}

function fakeFetch( registro ) {
	return function ( url ) {
		registro.llamadas++;
		registro.urls.push( url );
		var respuesta = registro.cola.shift();
		if ( ! respuesta ) {
			respuesta = { opciones: {} };
		}
		return Promise.resolve( {
			ok: true,
			json: function () { return Promise.resolve( respuesta ); },
		} );
	};
}

function ccmckCartCity() {
	return {
		rest: 'https://ejemplo.test/wp-json/ccmck/v1/ciudades',
		elige: 'Elige tu ciudad',
		cargando: 'Cargando ciudades…',
		vacio: 'No hay ciudades para ese departamento',
		error: 'No se pudieron cargar las ciudades. Envía el formulario para intentar de nuevo.',
	};
}

function opcionesDe( select ) {
	return select.children.map( function ( o ) { return { value: o.value, texto: o.textContent }; } );
}

// ---------------------------------------------------------------------------
// Escenario: primera interacción, luego WooCommerce reemplaza el bloque
// (nodos NUEVOS, misma estructura), y una segunda interacción.
// ---------------------------------------------------------------------------

async function correrEscenario( source, dispararDOMContentLoaded ) {
	var doc = makeDocument();
	var registro = { llamadas: 0, urls: [], cola: [
		{ opciones: { '11001000': 'BOGOTA (C/MARCA)', '25269000': 'FACATATIVA (C/MARCA)' } },
		{ opciones: { '08001000': 'BARRANQUILLA (ATL)' } },
	] };
	var win = { ccmckCartCity: ccmckCartCity() };

	correrEnSandbox( source, doc, win, fakeFetch( registro ) );

	// --- Primera interacción: DOM tal cual lo pintó el servidor. El HTML del
	// calculador (incluida la calculadora) ya está armado ANTES de
	// DOMContentLoaded, como en una carga de página real. ---
	var uno = montarCalculadora( doc );

	if ( dispararDOMContentLoaded ) {
		doc.dispatchEvent( { type: 'DOMContentLoaded' } );
	}

	uno.estado.value = 'Cundinamarca';
	uno.estado.dispatchEvent( { type: 'change' } );
	await tick();

	var llamadasTrasPrimeraInteraccion = registro.llamadas;
	var opcionesTrasPrimeraInteraccion = opcionesDe( uno.ciudad );

	// --- WooCommerce reemplaza el bloque: nodos NUEVOS, mismos names/clases. ---
	// (cart.js: $('.cart_totals').replaceWith(...) / update_wc_div)
	doc.removeChild( uno.form );
	var dos = montarCalculadora( doc );

	dos.estado.value = 'Atlantico';
	dos.estado.dispatchEvent( { type: 'change' } );
	await tick();

	return {
		llamadasTrasPrimeraInteraccion: llamadasTrasPrimeraInteraccion,
		opcionesTrasPrimeraInteraccion: opcionesTrasPrimeraInteraccion,
		llamadasTotales: registro.llamadas,
		opcionesSegundoDesplegable: opcionesDe( dos.ciudad ),
	};
}

async function main() {
	var source = loadSource();
	var esDelegado = /document\.addEventListener\(\s*['"]change['"]/.test( source );

	console.log( '== Arnés cart-city: ' + ( useOld ? 'HEAD (antes del fix)' : 'archivo actual del repo' ) + ' ==' );
	console.log( 'Delegado en document: ' + esDelegado );

	var r = await correrEscenario( source, ! esDelegado );

	console.log( 'Peticiones REST tras la 1a interacción: ' + r.llamadasTrasPrimeraInteraccion );
	console.log( 'Opciones del 1er desplegable tras la 1a interacción: ' + JSON.stringify( r.opcionesTrasPrimeraInteraccion ) );
	console.log( 'Peticiones REST totales (tras reemplazo + 2a interacción): ' + r.llamadasTotales );
	console.log( 'Opciones del 2o desplegable (nodo NUEVO) tras la 2a interacción: ' + JSON.stringify( r.opcionesSegundoDesplegable ) );

	// Estas son las aserciones del comportamiento CORRECTO (delegado):
	// - Una sola petición por interacción (no dos).
	// - El desplegable NUEVO (tras el reemplazo de WooCommerce) SÍ se repuebla
	//   con las ciudades del NUEVO departamento.
	assert.equal( r.llamadasTrasPrimeraInteraccion, 1, 'la 1a interacción debe hacer UNA sola petición REST (no dos: ese es el bug del doble listener)' );
	assert.equal( r.llamadasTotales, 2, 'tras el reemplazo, la 2a interacción también debe pegarle a la REST' );
	assert.deepEqual(
		r.opcionesSegundoDesplegable.map( function ( o ) { return o.value; } ),
		[ '', '08001000' ],
		'el desplegable de ciudad del bloque NUEVO debe quedar con las ciudades del departamento NUEVO, no vacío ni con las del anterior'
	);
	assert.ok(
		r.opcionesSegundoDesplegable.some( function ( o ) { return 'BARRANQUILLA' === ( o.texto || '' ).split( ' ' )[ 0 ]; } ),
		'debe aparecer Barranquilla (la ciudad del departamento elegido en la 2a interacción)'
	);

	console.log( '\nPASA: el listener sobrevive al reemplazo del bloque y no duplica peticiones.' );
	process.exitCode = 0;
}

main().catch( function ( err ) {
	console.error( '\nFALLA: ' + err.message );
	process.exitCode = 1;
} );
