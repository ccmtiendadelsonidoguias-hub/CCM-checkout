<?php
use PHPUnit\Framework\TestCase;

final class UbicacionTest extends TestCase {

	private function catalogo(): array {
		return array(
			'Cundinamarca' => array(
				'11001000' => 'BOGOTA (C/MARCA)',
				'25269000' => 'FACATATIVA (C/MARCA)',
			),
			'Atlantico' => array(
				'08001000' => 'BARRANQUILLA (ATL)',
			),
		);
	}

	public function test_guarda_departamento_y_ciudad_juntos(): void {
		$this->assertSame(
			'Cundinamarca|BOGOTA (C/MARCA) (11001000)',
			CCMCK_Ubicacion::valor_cookie( 'Cundinamarca', 'BOGOTA (C/MARCA) (11001000)' )
		);
	}

	public function test_lo_que_guarda_se_puede_volver_a_leer(): void {
		// El ida y vuelta completo: lo que escribimos tiene que sobrevivir.
		$valor = CCMCK_Ubicacion::valor_cookie( 'Cundinamarca', 'BOGOTA (C/MARCA) (11001000)' );
		$this->assertSame(
			array( 'state' => 'Cundinamarca', 'city' => 'BOGOTA (C/MARCA) (11001000)' ),
			CCMCK_Ubicacion::leer_cookie( $valor, $this->catalogo() )
		);
	}

	public function test_una_cookie_manipulada_no_se_cree(): void {
		// La escribe el navegador: puede venir con cualquier cosa. Si no existe
		// en el catalogo, se descarta y el cliente elige. Nunca se cotiza a un
		// destino que no podemos resolver.
		$casos = array(
			'Narnia|BOGOTA (C/MARCA) (11001000)',      // departamento inventado
			'Cundinamarca|MORDOR (XX) (99999999)',     // DANE que no es suyo
			'Cundinamarca|BARRANQUILLA (ATL) (08001000)', // ciudad de OTRO departamento
			'Cundinamarca|Bogota',                     // sin DANE
			'Cundinamarca',                            // sin separador
			'|',                                       // vacia
			'',                                        // ausente
		);
		foreach ( $casos as $caso ) {
			$this->assertSame( array(), CCMCK_Ubicacion::leer_cookie( $caso, $this->catalogo() ), "colo: $caso" );
		}
	}

	public function test_sin_catalogo_no_se_repone_nada(): void {
		// Si el plugin de ciudades no esta, no hay con que validar: mejor
		// preguntar que arriesgarse.
		$valor = CCMCK_Ubicacion::valor_cookie( 'Cundinamarca', 'BOGOTA (C/MARCA) (11001000)' );
		$this->assertSame( array(), CCMCK_Ubicacion::leer_cookie( $valor, array() ) );
	}

	public function test_solo_se_repone_si_el_cliente_no_tiene_ciudad(): void {
		// Lo que el cliente acaba de elegir MANDA sobre lo recordado.
		$this->assertTrue( CCMCK_Ubicacion::debe_reponer( '' ) );
		$this->assertTrue( CCMCK_Ubicacion::debe_reponer( '   ' ) );
		$this->assertFalse( CCMCK_Ubicacion::debe_reponer( 'MEDELLIN (ANT) (05001000)' ) );
		$this->assertFalse( CCMCK_Ubicacion::debe_reponer( 'Bogota' ) );
	}

	public function test_la_ciudad_repuesta_la_entiende_el_motor(): void {
		// El candado que ata esto con quien cotiza: lo que reponemos tiene que
		// poder leerlo dane_from_city(), o el flete no aparece.
		$valor = CCMCK_Ubicacion::valor_cookie( 'Cundinamarca', 'BOGOTA (C/MARCA) (11001000)' );
		$ubi   = CCMCK_Ubicacion::leer_cookie( $valor, $this->catalogo() );
		$this->assertSame( '11001000', CCMCK_Coordinadora::dane_from_city( $ubi['city'] ) );
	}
}
