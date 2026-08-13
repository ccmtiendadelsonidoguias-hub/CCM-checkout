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

	protected function setUp(): void {
		// Otra prueba de esta misma clase pudo dejar transients puestos.
		$GLOBALS['ccmck_test_transients'] = array();
	}

	// --- Caché del rótulo ---

	public function test_the_first_click_asks_coordinadora_and_stores_the_label(): void {
		$llamadas = 0;
		$fetcher  = function () use ( &$llamadas ) {
			$llamadas++;
			return 'UERGLWZha2U=';
		};

		$this->assertSame( 'UERGLWZha2U=', CCMCK_Guias::label_from_cache_or( '330420', $fetcher ) );
		$this->assertSame( 1, $llamadas );
	}

	public function test_the_second_click_does_not_ask_coordinadora_again(): void {
		$llamadas = 0;
		$fetcher  = function () use ( &$llamadas ) {
			$llamadas++;
			return 'UERGLWZha2U=';
		};

		CCMCK_Guias::label_from_cache_or( '330420', $fetcher );
		$segundo = CCMCK_Guias::label_from_cache_or( '330420', $fetcher );

		$this->assertSame( 'UERGLWZha2U=', $segundo );
		$this->assertSame( 1, $llamadas, 'la segunda descarga volvio a llamar a Coordinadora' );
	}

	public function test_a_failure_is_cached_so_an_insistent_customer_is_not_a_hammer(): void {
		// Sin esto, un cliente que pulse "Descargar" veinte veces son veinte
		// llamadas SOAP contra la API de Coordinadora.
		$llamadas = 0;
		$fetcher  = function () use ( &$llamadas ) {
			$llamadas++;
			return '';
		};

		$this->assertSame( '', CCMCK_Guias::label_from_cache_or( '330420', $fetcher ) );
		$this->assertSame( '', CCMCK_Guias::label_from_cache_or( '330420', $fetcher ) );
		$this->assertSame( '', CCMCK_Guias::label_from_cache_or( '330420', $fetcher ) );

		$this->assertSame( 1, $llamadas, 'el fallo no se cacheo' );
	}

	public function test_the_failure_sentinel_never_comes_back_as_a_pdf(): void {
		// El centinela del fallo se guarda en el mismo sitio que el rotulo. Si
		// se devolviera tal cual, el cliente se bajaria un archivo con la
		// palabra centinela dentro y extension .pdf.
		CCMCK_Guias::label_from_cache_or( '330420', static fn() => '' );

		$this->assertSame( '', CCMCK_Guias::label_from_cache_or( '330420', static fn() => 'UERGLWZha2U=' ) );
	}

	public function test_each_guide_has_its_own_cache(): void {
		CCMCK_Guias::label_from_cache_or( '330420', static fn() => 'QUFB' );

		$this->assertSame( 'QkJC', CCMCK_Guias::label_from_cache_or( '330421', static fn() => 'QkJC' ) );
	}

	// --- Quién puede bajar un rótulo ---

	private function ctx( array $cambios = array() ): array {
		return array_merge( array(
			'logged_in'         => true,
			'user_id'           => 7,
			'order_found'       => true,
			'order_customer_id' => 7,
			'guia'              => '33042000392',
		), $cambios );
	}

	public function test_the_owner_can_download_their_label(): void {
		$r = CCMCK_Guias::customer_label_check( $this->ctx() );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['reason'] );
	}

	public function test_an_anonymous_visitor_cannot(): void {
		$r = CCMCK_Guias::customer_label_check( $this->ctx( array( 'logged_in' => false, 'user_id' => 0 ) ) );

		$this->assertFalse( $r['ok'] );
	}

	public function test_a_customer_cannot_download_someone_elses_label(): void {
		$r = CCMCK_Guias::customer_label_check( $this->ctx( array( 'order_customer_id' => 99 ) ) );

		$this->assertFalse( $r['ok'] );
	}

	public function test_a_guest_order_belongs_to_nobody(): void {
		// customer_id 0 es un pedido de invitado. Un cliente con sesion no
		// puede reclamarlo solo porque su propio id tampoco sea 0.
		$r = CCMCK_Guias::customer_label_check( $this->ctx( array( 'order_customer_id' => 0 ) ) );

		$this->assertFalse( $r['ok'] );
	}

	public function test_an_order_without_a_guide_has_nothing_to_download(): void {
		$r = CCMCK_Guias::customer_label_check( $this->ctx( array( 'guia' => '' ) ) );

		$this->assertFalse( $r['ok'] );
	}

	public function test_missing_and_not_yours_are_indistinguishable(): void {
		// Si los motivos difieren, un cliente puede averiguar que pedidos
		// existen probando numeros.
		$ajeno       = CCMCK_Guias::customer_label_check( $this->ctx( array( 'order_customer_id' => 99 ) ) );
		$inexistente = CCMCK_Guias::customer_label_check( $this->ctx( array( 'order_found' => false ) ) );

		$this->assertSame( $ajeno['reason'], $inexistente['reason'] );
	}
}
