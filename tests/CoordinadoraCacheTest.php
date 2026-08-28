<?php
use PHPUnit\Framework\TestCase;

final class CoordinadoraCacheTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ccmck_test_transients'] = array();
	}

	private function args(): array {
		return array(
			'nit' => '123', 'origen' => '08001000', 'destino' => '11001000',
			'valoracion' => 700000, 'detalle' => array( array( 'peso' => 30 ) ),
			'apikey' => 'LLAVE', 'clave' => 'SECRETO',
		);
	}

	public function test_el_mismo_envio_da_la_misma_clave(): void {
		$this->assertSame(
			CCMCK_Coordinadora::cache_key( $this->args() ),
			CCMCK_Coordinadora::cache_key( $this->args() )
		);
	}

	public function test_otro_destino_da_otra_clave(): void {
		$otro = $this->args();
		$otro['destino'] = '05001000';
		$this->assertNotSame( CCMCK_Coordinadora::cache_key( $this->args() ), CCMCK_Coordinadora::cache_key( $otro ) );
	}

	public function test_otro_contenido_da_otra_clave(): void {
		$otro = $this->args();
		$otro['detalle'] = array( array( 'peso' => 60 ) );
		$this->assertNotSame( CCMCK_Coordinadora::cache_key( $this->args() ), CCMCK_Coordinadora::cache_key( $otro ) );
	}

	public function test_las_credenciales_no_entran_en_la_clave(): void {
		// Rotar la llave no debe invalidar la cache, y sobre todo: la clave se
		// escribe en la base de datos y no puede llevar el secreto dentro.
		$otro = $this->args();
		$otro['apikey'] = 'OTRA'; $otro['clave'] = 'OTRO';
		$this->assertSame( CCMCK_Coordinadora::cache_key( $this->args() ), CCMCK_Coordinadora::cache_key( $otro ) );
		$this->assertStringNotContainsString( 'LLAVE', CCMCK_Coordinadora::cache_key( $this->args() ) );
	}
}
