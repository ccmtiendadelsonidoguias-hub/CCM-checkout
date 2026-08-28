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
		// Catalogo vacio (plugin de ciudades caido): mejor deshabilitado que un
		// desplegable de una sola opcion vacia que parece roto.
		$args = CCMCK_Cart_Shipping::city_field_args( array( 'type' => 'text' ), array(), 'Cundinamarca' );
		$this->assertTrue( isset( $args['custom_attributes']['disabled'] ) );
		$this->assertStringContainsString( 'No hay ciudades', reset( $args['options'] ) );
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
}
