<?php
use PHPUnit\Framework\TestCase;

final class CartShippingTest extends TestCase {

	/** Catálogo de juguete con la forma real del plugin de ciudades. */
	private function catalogo(): array {
		return array(
			'Cundinamarca' => array(
				'11001000' => 'BOGOTA (C/MARCA)',
				'25269000' => 'FACATATIVA (C/MARCA)',
				'25001000' => 'AGUA DE DIOS (C/MARCA)',
			),
			'Atlantico' => array(
				'08001000' => 'BARRANQUILLA (ATL)',
			),
		);
	}

	public function test_el_valor_lleva_el_dane_al_final_como_lo_postea_el_checkout(): void {
		// dane_from_city() busca 8 digitos al FINAL. Si el formato cambia, el
		// carrito deja de cotizar en silencio.
		$this->assertSame(
			'BOGOTA (C/MARCA) (11001000)',
			CCMCK_Cart_Shipping::format_value( '11001000', 'BOGOTA (C/MARCA)' )
		);
	}

	public function test_el_valor_que_generamos_lo_entiende_el_motor(): void {
		// La prueba que ata las dos piezas: lo que escribe el carrito tiene que
		// poder leerlo quien cotiza.
		$valor = CCMCK_Cart_Shipping::format_value( '11001000', 'BOGOTA (C/MARCA)' );
		$this->assertSame( '11001000', CCMCK_Coordinadora::dane_from_city( $valor ) );
	}

	public function test_el_motor_aguanta_tildes_y_parentesis_anidados(): void {
		// El spec lo pide explicitamente: son los dos formatos que el carrito
		// puede acabar mandando y que romperian la extraccion del DANE.
		$this->assertSame( '11001000', CCMCK_Coordinadora::dane_from_city( 'BOGOTÁ (C/MARCA) (11001000)' ) );
		$this->assertSame( '05001000', CCMCK_Coordinadora::dane_from_city( 'MEDELLÍN (ANT) (05001000)' ) );
		// Y lo que NO debe colar: ciudad escrita a mano, sin DANE.
		$this->assertSame( '', CCMCK_Coordinadora::dane_from_city( 'Bogotá' ) );
		$this->assertSame( '', CCMCK_Coordinadora::dane_from_city( 'Bogota (Cundinamarca)' ) );
	}

	public function test_las_ciudades_salen_del_departamento_pedido(): void {
		$opts = CCMCK_Cart_Shipping::city_options( $this->catalogo(), 'Cundinamarca' );
		$this->assertCount( 3, $opts );
		$this->assertArrayHasKey( 'BOGOTA (C/MARCA) (11001000)', $opts );
		$this->assertSame( 'BOGOTA (C/MARCA)', $opts['BOGOTA (C/MARCA) (11001000)'] );
	}

	public function test_las_ciudades_van_ordenadas_por_nombre(): void {
		$opts = CCMCK_Cart_Shipping::city_options( $this->catalogo(), 'Cundinamarca' );
		$this->assertSame(
			array( 'AGUA DE DIOS (C/MARCA)', 'BOGOTA (C/MARCA)', 'FACATATIVA (C/MARCA)' ),
			array_values( $opts )
		);
	}

	public function test_un_departamento_desconocido_no_devuelve_nada(): void {
		$this->assertSame( array(), CCMCK_Cart_Shipping::city_options( $this->catalogo(), 'Narnia' ) );
		$this->assertSame( array(), CCMCK_Cart_Shipping::city_options( $this->catalogo(), '' ) );
	}

	public function test_tolera_mayusculas_y_espacios_en_el_departamento(): void {
		// El desplegable del carrito postea "Atlantico"; si alguien guarda
		// "atlantico " en sesion, no puede quedarse sin ciudades.
		$opts = CCMCK_Cart_Shipping::city_options( $this->catalogo(), ' atlantico ' );
		$this->assertArrayHasKey( 'BARRANQUILLA (ATL) (08001000)', $opts );
	}

	public function test_un_catalogo_vacio_no_revienta(): void {
		// Si el plugin de ciudades se desactiva, el carrito debe seguir cargando.
		$this->assertSame( array(), CCMCK_Cart_Shipping::city_options( array(), 'Cundinamarca' ) );
	}

	public function test_sin_departamento_el_campo_queda_deshabilitado_y_lo_dice(): void {
		$args = CCMCK_Cart_Shipping::city_field_args( array( 'type' => 'text' ), array(), '' );
		$this->assertSame( 'select', $args['type'] );
		$this->assertTrue( isset( $args['custom_attributes']['disabled'] ) );
		$this->assertStringContainsString( 'Elige primero', reset( $args['options'] ) );
	}

	public function test_con_departamento_el_campo_trae_sus_ciudades(): void {
		$opts = CCMCK_Cart_Shipping::city_options( $this->catalogo(), 'Atlantico' );
		$args = CCMCK_Cart_Shipping::city_field_args( array( 'type' => 'text' ), $opts, 'Atlantico' );
		$this->assertSame( 'select', $args['type'] );
		$this->assertFalse( isset( $args['custom_attributes']['disabled'] ) );
		$this->assertArrayHasKey( 'BARRANQUILLA (ATL) (08001000)', $args['options'] );
		// La primera opcion invita a elegir, no preselecciona una ciudad.
		$this->assertSame( '', array_key_first( $args['options'] ) );
	}

	public function test_un_departamento_sin_ciudades_no_finge_que_las_tiene(): void {
		// Catalogo DISPONIBLE (parametro por defecto true) pero SIN ciudades
		// para este departamento puntual: un hueco real de datos, no el
		// plugin caido. Aqui si tiene sentido deshabilitar: no hay nada que
		// perder porque nunca hubo una ciudad valida que ofrecer.
		$args = CCMCK_Cart_Shipping::city_field_args( array( 'type' => 'text' ), array(), 'Cundinamarca' );
		$this->assertTrue( isset( $args['custom_attributes']['disabled'] ) );
		$this->assertStringContainsString( 'No hay ciudades', reset( $args['options'] ) );
	}

	public function test_catalogo_no_disponible_no_deshabilita_el_campo(): void {
		// Fail-open, alineado con CCMCK_Cities::validate_destination(): si el
		// plugin de ciudades esta desactivado (catalogo entero vacio),
		// deshabilitar el <select> lo saca del serialize() de jQuery y el
		// proximo envio de la calculadora borraria la ciudad ya guardada en
		// la sesion del cliente. Aqui NUNCA debe quedar disabled, pase lo que
		// pase con $options o $state.
		$args = CCMCK_Cart_Shipping::city_field_args( array( 'type' => 'text' ), array(), 'Cundinamarca', false );
		$this->assertSame( 'select', $args['type'] );
		$this->assertFalse( isset( $args['custom_attributes']['disabled'] ), 'el campo NO debe quedar disabled sin catalogo' );
		$this->assertStringContainsString( 'no disponible', reset( $args['options'] ) );
	}

	public function test_catalogo_no_disponible_gana_incluso_sin_departamento_elegido(): void {
		// Mismo fail-open aunque tampoco haya departamento en sesion: no debe
		// colarse el mensaje "Elige primero el departamento" (que si implica
		// que el catalogo funciona) cuando en realidad no hay catalogo.
		$args = CCMCK_Cart_Shipping::city_field_args( array( 'type' => 'text' ), array(), '', false );
		$this->assertFalse( isset( $args['custom_attributes']['disabled'] ) );
		$this->assertStringContainsString( 'no disponible', reset( $args['options'] ) );
	}

	public function test_texto_departamento_sin_ciudades_es_una_sola_fuente(): void {
		// El JS (repoblado por AJAX) y el servidor (render inicial) deben
		// mostrar el MISMO texto para "este departamento no tiene ciudades".
		// Antes eran dos literales copiados por coincidencia; ahora los dos
		// deben leer de CCMCK_Cart_Shipping::texto_departamento_sin_ciudades().
		$opts = CCMCK_Cart_Shipping::city_field_args( array( 'type' => 'text' ), array(), 'Cundinamarca' );
		$this->assertSame( CCMCK_Cart_Shipping::texto_departamento_sin_ciudades(), reset( $opts['options'] ) );

		// Y el localize del JS debe leer del MISMO metodo, no de un literal
		// propio: se verifica en el CODIGO FUENTE (wp_localize_script()
		// necesita WordPress entero y no corre bajo PHPUnit).
		$ruta   = dirname( __DIR__ ) . '/includes/class-ccmck-assets.php';
		$fuente = file_get_contents( $ruta );
		$this->assertNotFalse( $fuente, "no pude leer $ruta" );
		$this->assertStringContainsString(
			"'vacio'    => CCMCK_Cart_Shipping::texto_departamento_sin_ciudades()",
			$fuente,
			'el localize del JS debe usar texto_departamento_sin_ciudades(), no un literal propio que pueda desincronizarse'
		);
	}

	public function test_la_respuesta_rest_devuelve_valor_y_etiqueta(): void {
		// Se prueba el armado de la respuesta, no WP_REST_Request.
		$payload = CCMCK_Cart_Shipping::rest_payload( $this->catalogo(), 'Atlantico' );
		$this->assertSame(
			array( 'opciones' => array( 'BARRANQUILLA (ATL) (08001000)' => 'BARRANQUILLA (ATL)' ) ),
			$payload
		);
	}

	public function test_un_departamento_desconocido_devuelve_lista_vacia_no_un_error(): void {
		// El desplegable debe quedar vacio y deshabilitado, no romper la pagina.
		$this->assertSame( array( 'opciones' => array() ), CCMCK_Cart_Shipping::rest_payload( $this->catalogo(), 'Narnia' ) );
	}

	public function test_el_encolado_del_carrito_va_antes_del_return_de_is_checkout(): void {
		// Precedente que ya costo produccion: is_checkout() corta con un return
		// temprano. Si alguien mueve el bloque del carrito despues de ese
		// return (por "orden mas prolijo", por ejemplo), el JS deja de
		// cargarse en el carrito y ningun otro test de este banco lo nota,
		// porque enqueue() no se ejecuta bajo PHPUnit (necesita WordPress
		// entero). Por eso la prueba lee el CODIGO FUENTE, no el comportamiento.
		$ruta   = dirname( __DIR__ ) . '/includes/class-ccmck-assets.php';
		$fuente = file_get_contents( $ruta );
		$this->assertNotFalse( $fuente, "no pude leer $ruta" );

		$inicio_enqueue = strpos( $fuente, 'function enqueue(' );
		$this->assertNotFalse( $inicio_enqueue, 'no encontre function enqueue() en class-ccmck-assets.php' );

		// A partir de aqui, todo lo que sigue es el CUERPO de enqueue(). Ojo:
		// preload_lcp() tiene su propio "is_checkout()" ANTES en el archivo;
		// recortar desde este punto evita que ese otro match arruine la prueba.
		$cuerpo_enqueue = substr( $fuente, $inicio_enqueue );

		$pos_carrito  = strpos( $cuerpo_enqueue, "'ccmck-cart-city'" );
		$pos_checkout = strpos( $cuerpo_enqueue, '! is_checkout()' );

		$this->assertNotFalse( $pos_carrito, "no encontre el handle 'ccmck-cart-city' dentro de enqueue()" );
		$this->assertNotFalse( $pos_checkout, "no encontre '! is_checkout()' dentro de enqueue()" );
		$this->assertLessThan(
			$pos_checkout,
			$pos_carrito,
			'el encolado de ccmck-cart-city debe ir ANTES del return de is_checkout(), o nunca se ejecuta en el carrito'
		);
	}

	public function test_sin_ciudad_invita_a_elegirla(): void {
		$e = CCMCK_Cart_Shipping::estado( false, array() );
		$this->assertSame( 'sin_ciudad', $e['clave'] );
		$this->assertStringContainsString( 'ciudad', $e['texto'] );
	}

	public function test_un_producto_sin_medidas_se_nombra(): void {
		// El motor se rinde a proposito cuando falta peso o dimensiones. Hoy eso
		// se ve como "solo Recogida local" y nadie sabe por que.
		$e = CCMCK_Cart_Shipping::estado( true, array( 'Cabina X' ) );
		$this->assertSame( 'sin_medidas', $e['clave'] );
		$this->assertStringContainsString( 'Cabina X', $e['texto'] );
	}

	public function test_con_ciudad_y_medidas_no_hay_mensaje(): void {
		$e = CCMCK_Cart_Shipping::estado( true, array() );
		$this->assertSame( 'ok', $e['clave'] );
		$this->assertSame( '', $e['texto'] );
	}

	public function test_varios_productos_sin_medidas_se_listan(): void {
		$e = CCMCK_Cart_Shipping::estado( true, array( 'Cabina X', 'Trípode Y' ) );
		$this->assertStringContainsString( 'Cabina X', $e['texto'] );
		$this->assertStringContainsString( 'Trípode Y', $e['texto'] );
	}

	public function test_sin_ciudad_manda_aunque_tambien_falten_medidas(): void {
		// Precedencia: sin ciudad es lo primero que se arregla.
		$e = CCMCK_Cart_Shipping::estado( false, array( 'Cabina X' ) );
		$this->assertSame( 'sin_ciudad', $e['clave'] );
		$this->assertStringContainsString( 'ciudad', $e['texto'] );
	}

	// --- estado(): las 3 salidas de rates() que antes no se distinguian de
	// "no hay envio" (toggle apagado, credenciales vacias, cotizacion
	// fallida). Antes de este fix, las 3 caian silenciosamente en el mismo
	// "solo Recogida local" que un destino sin cobertura real. ---

	public function test_toggle_apagado_lo_dice(): void {
		$e = CCMCK_Cart_Shipping::estado( true, array(), false, true, false );
		$this->assertSame( 'toggle_apagado', $e['clave'] );
		$this->assertNotSame( '', $e['texto'] );
	}

	public function test_credenciales_incompletas_lo_dice(): void {
		$e = CCMCK_Cart_Shipping::estado( true, array(), true, false, false );
		$this->assertSame( 'sin_credenciales', $e['clave'] );
		$this->assertNotSame( '', $e['texto'] );
	}

	public function test_cotizacion_fallida_lo_dice(): void {
		$e = CCMCK_Cart_Shipping::estado( true, array(), true, true, true );
		$this->assertSame( 'cotizacion_fallida', $e['clave'] );
		$this->assertNotSame( '', $e['texto'] );
	}

	public function test_todo_en_orden_y_sin_fallo_sigue_dando_ok(): void {
		// Los parametros nuevos tienen default (true, true, false) para no
		// romper las llamadas existentes de estado(tiene_dane, sin_medidas):
		// deben comportarse identico a como se comportaban antes de este fix.
		$e = CCMCK_Cart_Shipping::estado( true, array() );
		$this->assertSame( 'ok', $e['clave'] );
	}

	public function test_toggle_apagado_manda_sobre_todo_lo_demas(): void {
		// Toggle apagado es un bloqueo de configuracion, no algo que el
		// cliente resuelva eligiendo ciudad o completando medidas: gana
		// incluso si TAMBIEN faltan ciudad y medidas.
		$e = CCMCK_Cart_Shipping::estado( false, array( 'Cabina X' ), false, false, true );
		$this->assertSame( 'toggle_apagado', $e['clave'] );
	}

	public function test_credenciales_incompletas_manda_sobre_ciudad_y_medidas(): void {
		$e = CCMCK_Cart_Shipping::estado( false, array( 'Cabina X' ), true, false, true );
		$this->assertSame( 'sin_credenciales', $e['clave'] );
	}

	public function test_cotizacion_fallida_solo_se_ve_si_todo_lo_demas_esta_en_orden(): void {
		// Si ademas de fallar la cotizacion falta la ciudad, sin_ciudad sigue
		// ganando (mismo precedente que sin_ciudad vs sin_medidas): la
		// cotizacion fallida solo es visible una vez que todo lo demas paso.
		$e = CCMCK_Cart_Shipping::estado( false, array(), true, true, true );
		$this->assertSame( 'sin_ciudad', $e['clave'] );
	}

	public function test_falta_medida_castea_a_float_como_rates(): void {
		// rates() hace (float) $it['weight'] <= 0. Aqui debe ser igual.
		// Casos que rompen si se usa lógica booleana cruda (! $peso):
		$this->assertTrue( CCMCK_Cart_Shipping::falta_medida( '0.00' ), 'string "0.00" debe contar como falta' );
		$this->assertTrue( CCMCK_Cart_Shipping::falta_medida( '0' ), 'string "0" debe contar como falta' );
		$this->assertTrue( CCMCK_Cart_Shipping::falta_medida( '' ), 'string vacío debe contar como falta' );
		$this->assertTrue( CCMCK_Cart_Shipping::falta_medida( null ), 'null debe contar como falta' );
		$this->assertTrue( CCMCK_Cart_Shipping::falta_medida( 0 ), 'número 0 debe contar como falta' );
		$this->assertTrue( CCMCK_Cart_Shipping::falta_medida( 0.0 ), 'float 0.0 debe contar como falta' );
		// Casos que SÍ tienen medidas:
		$this->assertFalse( CCMCK_Cart_Shipping::falta_medida( '10.50' ), 'string "10.50" no debe contar como falta' );
		$this->assertFalse( CCMCK_Cart_Shipping::falta_medida( 10 ), 'número 10 no debe contar como falta' );
		$this->assertFalse( CCMCK_Cart_Shipping::falta_medida( 0.01 ), 'float 0.01 no debe contar como falta' );
	}

	// --- lines_sin_medidas(): el log NO debe subestimar cuantos productos
	// distintos faltan solo porque comparten nombre. ---

	private function linea( string $name, int $id, string $sku, float $weight = 0.0 ): array {
		return array(
			'name'           => $name,
			'id'             => $id,
			'sku'            => $sku,
			'needs_shipping' => true,
			'weight'         => $weight,
			'length'         => 10,
			'width'          => 10,
			'height'         => 10,
		);
	}

	public function test_lines_sin_medidas_no_deduplica(): void {
		// Dos productos DISTINTOS (id/sku distintos) con el MISMO nombre,
		// ambos sin peso: deben salir DOS filas, no una.
		$lineas = array(
			$this->linea( 'Bafle 15"', 101, 'BAF-15-A' ),
			$this->linea( 'Bafle 15"', 202, 'BAF-15-B' ),
		);
		$falta = CCMCK_Cart_Shipping::lines_sin_medidas( $lineas );
		$this->assertCount( 2, $falta, 'dos productos distintos con el mismo nombre no deben colapsar en una fila' );
		$this->assertSame( 'BAF-15-A', $falta[0]['log'] );
		$this->assertSame( 'BAF-15-B', $falta[1]['log'] );
	}

	public function test_lines_sin_medidas_usa_id_si_no_hay_sku(): void {
		$falta = CCMCK_Cart_Shipping::lines_sin_medidas( array( $this->linea( 'Genérico', 555, '' ) ) );
		$this->assertSame( '#555', $falta[0]['log'] );
	}

	public function test_lines_sin_medidas_ignora_lo_que_no_necesita_envio(): void {
		$linea                    = $this->linea( 'Descargable', 9, 'DESC-9' );
		$linea['needs_shipping'] = false;
		$this->assertSame( array(), CCMCK_Cart_Shipping::lines_sin_medidas( array( $linea ) ) );
	}

	public function test_lines_sin_medidas_respeta_falta_medida_como_rates(): void {
		// Con TODAS las medidas presentes, no debe salir en la lista.
		$linea = $this->linea( 'Cabina OK', 7, 'CAB-7', 5.0 );
		$this->assertSame( array(), CCMCK_Cart_Shipping::lines_sin_medidas( array( $linea ) ) );
	}

	public function test_items_sin_medidas_log_no_deduplica_items_sin_medidas_si(): void {
		// El mensaje al CLIENTE (items_sin_medidas, nombres) SÍ deduplica: no
		// tiene sentido repetir el mismo nombre dos veces en una frase. El
		// LOG interno (items_sin_medidas_log, ids/skus) NO debe deduplicar:
		// son productos distintos de verdad. Sin WC() disponible bajo
		// PHPUnit ambos devuelven listas vacías; esta prueba fija el
		// contrato de lines_sin_medidas() (de donde salen los dos) para que
        // quien lo intente "simplificar" con un array_unique() global no
        // vuelva a colar el bug.
		$lineas = array(
			$this->linea( 'Bafle 15"', 101, 'BAF-15-A' ),
			$this->linea( 'Bafle 15"', 202, 'BAF-15-B' ),
		);
		$falta   = CCMCK_Cart_Shipping::lines_sin_medidas( $lineas );
		$nombres = array_values( array_unique( array_column( $falta, 'name' ) ) );
		$logs    = array_column( $falta, 'log' );

		$this->assertCount( 1, $nombres, 'el texto al cliente sí deduplica por nombre' );
		$this->assertCount( 2, $logs, 'el log NO debe deduplicar: son dos productos distintos' );
	}

	public function test_print_notice_usa_el_logger_de_woocommerce_no_error_log(): void {
		// error_log() escribia el TEXTO TRADUCIDO de cara al cliente en cada
		// render del carrito y cada refresco AJAX, fuera del canal del
		// proyecto (wc_get_logger()). Se verifica en el CODIGO FUENTE: esta
		// clase no llama a wc_get_logger() bajo PHPUnit (WC() no existe en el
		// bootstrap), asi que no se puede probar por comportamiento.
		$ruta   = dirname( __DIR__ ) . '/includes/class-ccmck-cart-shipping.php';
		$fuente = file_get_contents( $ruta );
		$this->assertNotFalse( $fuente, "no pude leer $ruta" );
		$this->assertStringNotContainsString( 'error_log(', $fuente, 'no debe quedar ningun error_log() en esta clase' );
		$this->assertStringContainsString( 'wc_get_logger()', $fuente );
	}
}
