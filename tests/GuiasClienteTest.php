<?php
use PHPUnit\Framework\TestCase;

final class GuiasClienteTest extends TestCase {

	// --- has_guide: decide qué pedido aparece en Descargas ---

	public function test_an_order_with_a_guide_qualifies(): void {
		$this->assertTrue( CCMCK_Guias::has_guide( '33042000392' ) );
	}

	public function test_an_order_without_a_guide_does_not(): void {
		$this->assertFalse( CCMCK_Guias::has_guide( '' ) );
		$this->assertFalse( CCMCK_Guias::has_guide( '   ' ) );
	}

	// --- label_filename: el valor va a una cabecera HTTP ---

	public function test_the_filename_uses_the_guide_number(): void {
		$this->assertSame( 'guia-33042000392.pdf', CCMCK_Guias::label_filename( '33042000392' ) );
	}

	public function test_the_filename_never_carries_header_breaking_characters(): void {
		// Content-Disposition es una cabecera: un salto de linea o unas
		// comillas ahi son inyeccion de cabeceras, no un nombre feo.
		foreach ( array( "330\r\nX: y", '33/04"2', '../../etc/passwd', '33 04' ) as $sucio ) {
			$nombre = CCMCK_Guias::label_filename( $sucio );
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-]+\.pdf$/', $nombre, "sucio: $sucio" );
		}
	}

	public function test_an_empty_guide_still_gives_a_usable_filename(): void {
		// Nunca "guia-.pdf", que parece un archivo roto.
		$this->assertSame( 'rotulo.pdf', CCMCK_Guias::label_filename( '' ) );
	}

	// --- label_cache_key: los transients tienen limite de longitud ---

	public function test_the_cache_key_is_stable_and_unique_per_guide(): void {
		$a = CCMCK_Guias::label_cache_key( '33042000392' );

		$this->assertSame( $a, CCMCK_Guias::label_cache_key( '33042000392' ) );
		$this->assertNotSame( $a, CCMCK_Guias::label_cache_key( '33042000393' ) );
	}

	public function test_the_cache_key_fits_the_transient_limit(): void {
		// Una clave de transient con expiracion no puede pasar de 172
		// caracteres: WordPress le antepone "_transient_timeout_" y la columna
		// option_name tiene 191. Con un numero de guia absurdo tiene que seguir
		// cabiendo, asi que la clave va por md5 y no por el numero en crudo.
		$clave = CCMCK_Guias::label_cache_key( str_repeat( '9', 500 ) );

		$this->assertLessThanOrEqual( 172, strlen( $clave ) );
		$this->assertStringStartsWith( 'ccmck_rotulo_', $clave );
	}
}
