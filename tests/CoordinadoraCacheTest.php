<?php
use PHPUnit\Framework\TestCase;

/**
 * wpdb mínimo para probar purge_cache(): solo registra las queries que le
 * llegan y escapa LIKE como el $wpdb real (addcslashes de '_%\'), para poder
 * verificar que el SQL escapa los guiones bajos de 'ccmck_cot_'.
 */
final class CCMCK_Fake_Wpdb_Cache {
	public $options = 'wp_options';
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 0;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
}

final class CoordinadoraCacheTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ccmck_test_transients'] = array();
		$GLOBALS['ccmck_test_http']       = array( 'calls' => 0, 'queue' => array() );
	}

	private function args(): array {
		return array(
			'nit' => '123', 'origen' => '08001000', 'destino' => '11001000',
			'valoracion' => 700000, 'detalle' => array( array( 'peso' => 30 ) ),
			'apikey' => 'LLAVE', 'clave' => 'SECRETO',
		);
	}

	private function respuesta_ok(): array {
		return array(
			'body' => '{"jsonrpc":"2.0","id":0,"result":{"flete_total":15700,"dias_entrega":2},"error":null}',
			'code' => 200,
		);
	}

	// --- cache_key(): pureza (banco original del brief) ---

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

	public function test_otra_cuenta_da_otra_clave(): void {
		// CCMCK_Guias cotiza la guia CE con guias_cuenta_ce (6, ver
		// class-ccmck-guias.php:616) y el carrito/checkout con la cuenta por
		// defecto (2). Hoy tarifan igual, pero el comentario de guias.php dice
		// que se usa 6 A PROPOSITO por si las tarifas divergen a futuro: una
		// clave compartida entre las dos anularia esa previsión en silencio.
		$dos  = $this->args(); // sin 'cuenta': cae al default (2), como rates().
		$seis = $this->args();
		$seis['cuenta'] = 6; // como CCMCK_Guias::generar() para la guia CE.
		$this->assertNotSame(
			CCMCK_Coordinadora::cache_key( $dos ),
			CCMCK_Coordinadora::cache_key( $seis ),
			'cuenta 2 (carrito) y cuenta 6 (guia CE) no deben compartir clave de cache'
		);
	}

	public function test_cuenta_por_defecto_es_2_como_build_request(): void {
		// cache_key() debe asumir el MISMO default que build_request() (2):
		// si no, una llamada sin 'cuenta' explicito (el carrito) y una con
		// 'cuenta' => 2 explicito (si algun dia se hace explicito) deberian
		// seguir compartiendo clave, porque piden exactamente lo mismo.
		$sin_cuenta = $this->args();
		$con_dos    = $this->args();
		$con_dos['cuenta'] = 2;
		$this->assertSame( CCMCK_Coordinadora::cache_key( $sin_cuenta ), CCMCK_Coordinadora::cache_key( $con_dos ) );
	}

	public function test_otro_nit_da_otra_clave(): void {
		$otro = $this->args();
		$otro['nit'] = '999999999';
		$this->assertNotSame( CCMCK_Coordinadora::cache_key( $this->args() ), CCMCK_Coordinadora::cache_key( $otro ) );
	}

	public function test_cuentas_distintas_no_comparten_cache_de_quote(): void {
		// No solo cache_key() en aislado: quote() de verdad debe pegarle a la
		// API una vez POR CUENTA para el mismo envio, no reusar la respuesta
		// de la otra cuenta.
		$GLOBALS['ccmck_test_http']['queue'] = array( $this->respuesta_ok(), $this->respuesta_ok() );

		$dos  = $this->args();
		$seis = $this->args();
		$seis['cuenta'] = 6;

		CCMCK_Coordinadora::quote( $dos );
		CCMCK_Coordinadora::quote( $seis );

		$this->assertSame( 2, $GLOBALS['ccmck_test_http']['calls'], 'cuenta 2 y cuenta 6 deben pegarle a la API cada una, no compartir cache' );
	}

	// --- quote(): que la caché MUERDA de verdad, no solo que cache_key() sea pura ---

	public function test_acierto_de_cache_no_llama_a_la_api(): void {
		$GLOBALS['ccmck_test_http']['queue'] = array( $this->respuesta_ok() );
		$args = $this->args();

		$primero = CCMCK_Coordinadora::quote( $args );
		$segundo = CCMCK_Coordinadora::quote( $args );

		$this->assertSame( 1, $GLOBALS['ccmck_test_http']['calls'], 'la segunda llamada debe salir de la caché, no de la red' );
		$this->assertSame( $primero, $segundo );
		$this->assertTrue( $primero['ok'] );
		$this->assertSame( 15700, $primero['flete_total'] );
	}

	public function test_sin_cache_cada_llamada_pega_a_la_api(): void {
		// Prueba de control: si no hay nada cacheado entre llamadas (mismo
		// envío, dos visitantes en sesiones distintas), sí debe volver a pegar
		// a la API. Esto es lo que demuestra que la prueba de arriba muerde:
		// aquí el contador SÍ debe subir a 2.
		$GLOBALS['ccmck_test_http']['queue'] = array( $this->respuesta_ok(), $this->respuesta_ok() );
		$args = $this->args();

		CCMCK_Coordinadora::quote( $args );
		$GLOBALS['ccmck_test_transients'] = array(); // simula una sesión/visitante nuevo, sin caché compartida
		CCMCK_Coordinadora::quote( $args );

		$this->assertSame( 2, $GLOBALS['ccmck_test_http']['calls'] );
	}

	public function test_ttl_ok_es_12_horas_y_fallo_de_api_es_5_minutos(): void {
		$GLOBALS['ccmck_test_http']['queue'] = array( $this->respuesta_ok() );
		$args_ok = $this->args();
		CCMCK_Coordinadora::quote( $args_ok );
		$key_ok = CCMCK_Coordinadora::cache_key( $args_ok );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $GLOBALS['ccmck_test_transients'][ $key_ok ]['expira'] );

		$GLOBALS['ccmck_test_http']['queue'] = array( array( 'body' => 'no-es-json', 'code' => 200 ) );
		$args_fail = $this->args();
		$args_fail['destino'] = '05001000'; // clave distinta, para no pisar el transient de arriba
		$resultado = CCMCK_Coordinadora::quote( $args_fail );
		$this->assertFalse( $resultado['ok'] );
		$key_fail = CCMCK_Coordinadora::cache_key( $args_fail );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, $GLOBALS['ccmck_test_transients'][ $key_fail ]['expira'] );
	}

	public function test_fallo_de_red_tambien_cachea_con_ttl_corto(): void {
		// Antes del fix del revisor, is_wp_error() devolvía ANTES de cachear:
		// una caída de red no quedaba protegida por el TTL corto y cada
		// visitante se comía el timeout de 5s completo mientras Coordinadora
		// estaba caída.
		$GLOBALS['ccmck_test_http']['queue'] = array(
			new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' ),
		);
		$args = $this->args();

		$primero = CCMCK_Coordinadora::quote( $args );
		$this->assertFalse( $primero['ok'] );

		// Segunda llamada: la cola de red ya está vacía (solo encolamos un
		// WP_Error). Si el fallo de red no se hubiera cacheado, esto volvería
		// a pegar a wp_remote_post() (que devolvería el default '{}'/200, no
		// un WP_Error) en vez de reusar $primero.
		$segundo = CCMCK_Coordinadora::quote( $args );
		$this->assertSame( 1, $GLOBALS['ccmck_test_http']['calls'], 'el fallo de red debe cachearse: no debe volver a tocar la red' );
		$this->assertSame( $primero, $segundo );

		$key = CCMCK_Coordinadora::cache_key( $args );
		$hit = get_transient( $key );
		$this->assertIsArray( $hit );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, $GLOBALS['ccmck_test_transients'][ $key ]['expira'] );
	}

	// --- purge_cache(): borra lo cacheado, escapando el LIKE ---

	public function test_purge_cache_escapa_los_guiones_bajos_del_like(): void {
		global $wpdb;
		$wpdb = new CCMCK_Fake_Wpdb_Cache();

		CCMCK_Coordinadora::purge_cache();

		$this->assertNotEmpty( $wpdb->queries );
		$sql = implode( ' ', $wpdb->queries );
		// '_' es comodín de UN carácter en LIKE de MySQL: sin escapar, el patrón
		// también matchearía basura como 'ccmckXcotX'. esc_like() debe convertir
		// 'ccmck_cot_' en 'ccmck\_cot\_'.
		$this->assertStringContainsString( 'ccmck\\_cot\\_', $sql );
		$this->assertStringNotContainsString( "ccmck_cot_%", $sql, 'el guion bajo sin escapar no debe aparecer en el SQL' );
		$this->assertStringContainsString( '_transient_', $sql );
		$this->assertStringContainsString( '_transient_timeout_', $sql );
	}

	// --- init(): purge_cache() debe engancharse tambien al PRIMER guardado ---

	public function test_purge_cache_se_engancha_tambien_al_primer_guardado(): void {
		// update_option_{$option} NO dispara la primera vez que se guarda la
		// opcion (WordPress dispara add_option_{$option} en su lugar, porque
		// la opcion todavia no existe). init() no se puede invocar bajo
		// PHPUnit (add_action() no esta definida en el bootstrap: ninguna
		// prueba de este banco carga WordPress entero), asi que se verifica
		// en el CODIGO FUENTE que las DOS acciones queden enganchadas a
		// purge_cache.
		$ruta   = dirname( __DIR__ ) . '/includes/class-ccmck-coordinadora.php';
		$fuente = file_get_contents( $ruta );
		$this->assertNotFalse( $fuente, "no pude leer $ruta" );
		$this->assertStringContainsString(
			"add_action( 'update_option_' . CCMCK_Settings::OPTION, array( __CLASS__, 'purge_cache' ) )",
			$fuente
		);
		$this->assertStringContainsString(
			"add_action( 'add_option_' . CCMCK_Settings::OPTION, array( __CLASS__, 'purge_cache' ) )",
			$fuente,
			'falta enganchar add_option_ (el primer guardado no dispara update_option_)'
		);
	}
}
