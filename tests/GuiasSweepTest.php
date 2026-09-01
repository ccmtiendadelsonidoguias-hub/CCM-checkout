<?php
use PHPUnit\Framework\TestCase;

final class GuiasSweepTest extends TestCase {

	/** Contexto de un pedido que SÍ debe barrerse; cada prueba cambia una cosa. */
	private function ctx( array $over = array() ): array {
		return array_merge( array(
			// Guards compartidos con should_generate() (C1): sin estos tres,
			// sweep_decision() bloquearía TODO por defecto.
			'enabled'       => true,
			'usuario'       => 'ccmtienda.ws',
			'clave'         => 'x',
			'status'        => 'processing',
			'shipping_ids'  => array( 'ccmck_coordinadora' ),
			'existing_guia' => '',
			'minutos'       => 30,
			'intentos'      => 0,
		), $over );
	}

	public function test_pedido_pagado_sin_guia_se_barre(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['reason'] );
	}

	public function test_no_toca_pedidos_fuera_de_processing(): void {
		foreach ( array( 'pending', 'failed', 'cancelled', 'completed', '' ) as $st ) {
			$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'status' => $st ) ) );
			$this->assertFalse( $r['ok'], "estado $st no debe barrerse" );
			$this->assertSame( 'no está en processing', $r['reason'] );
		}
	}

	public function test_no_crea_guias_de_coordinadora_para_otra_transportadora(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'shipping_ids' => array( 'flat_rate:3' ) ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'envío con otra transportadora', $r['reason'] );
	}

	public function test_no_duplica_si_ya_tiene_guia(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'existing_guia' => '33042000490' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'el pedido ya tiene guía', $r['reason'] );
	}

	/** Un espacio no es una guía: si no, el barredor se saltaría el pedido para siempre. */
	public function test_guia_en_blanco_no_cuenta_como_guia(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'existing_guia' => '   ' ) ) );
		$this->assertTrue( $r['ok'] );
	}

	// -- C1: guards delegados en should_generate() ------------------------
	//
	// El dueño puede desmarcar "Generar guías automáticamente" (guias_enabled)
	// porque despacha por otra empresa esa semana. El hook automático obedece
	// ese apagado; sin esta delegación el barredor lo ignoraba por completo y
	// generaba guías de todos los pedidos igual, sin forma de pararlo desde
	// WordPress.

	public function test_no_barre_con_generacion_automatica_apagada(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'enabled' => false ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'generación de guías desactivada', $r['reason'] );
	}

	public function test_no_barre_sin_usuario_del_ws(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'usuario' => '' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'faltan credenciales del WS de guías', $r['reason'] );
	}

	public function test_no_barre_sin_clave_del_ws(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'clave' => '' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'faltan credenciales del WS de guías', $r['reason'] );
	}

	/** Recogida local: el barredor tampoco debe rescatarla (a diferencia del botón manual). */
	public function test_no_barre_pedidos_de_recogida_local(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'shipping_ids' => array( 'local_pickup' ) ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'pedido con recogida local', $r['reason'] );
	}

	/** El guard de transportadora del barredor NUNCA se salta, ni con `manual` colado en el ctx. */
	public function test_ignora_manual_si_alguien_lo_cuela_en_el_contexto(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array(
			'manual'       => true,
			'shipping_ids' => array( 'flat_rate:3' ),
		) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'envío con otra transportadora', $r['reason'] );
	}

	/** Gracia: el camino normal tiene su oportunidad antes de que entre el barredor. */
	public function test_respeta_la_gracia_de_los_primeros_minutos(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'minutos' => 3 ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'demasiado reciente', $r['reason'] );
	}

	public function test_justo_en_el_limite_de_la_gracia_ya_se_barre(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'minutos' => CCMCK_Guias::SWEEP_GRACIA_MIN ) ) );
		$this->assertTrue( $r['ok'] );
	}

	/** Borde inmediatamente inferior al límite: un minuto antes, todavía NO se barre. */
	public function test_justo_debajo_del_limite_de_la_gracia_no_se_barre(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'minutos' => CCMCK_Guias::SWEEP_GRACIA_MIN - 1 ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'demasiado reciente', $r['reason'] );
	}

	/** Sin tope, un pedido con defecto permanente martillea a Coordinadora cada 15 min. */
	public function test_se_rinde_tras_agotar_los_intentos(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'intentos' => CCMCK_Guias::SWEEP_MAX_INTENTOS ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'agotados los reintentos', $r['reason'] );
	}

	public function test_el_ultimo_intento_disponible_si_se_usa(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'intentos' => CCMCK_Guias::SWEEP_MAX_INTENTOS - 1 ) ) );
		$this->assertTrue( $r['ok'] );
	}

	/** Un contexto vacío no debe reventar ni, peor, devolver ok. */
	public function test_contexto_vacio_no_revienta_y_no_barre(): void {
		$r = CCMCK_Guias::sweep_decision( array() );
		$this->assertFalse( $r['ok'] );
		$this->assertNotSame( '', $r['reason'] );
	}

	/** El motivo de descarte viaja al barredor para que pueda distinguir "ya está" de "se rindió". */
	public function test_motivos_son_estables_y_distinguibles(): void {
		$motivos = array();
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'status' => 'failed' ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'shipping_ids' => array( 'flat_rate:3' ) ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'existing_guia' => 'X' ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'minutos' => 1 ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'intentos' => 99 ) ) )['reason'];

		// Cinco motivos, cinco cadenas distintas: el barredor alerta solo con una.
		$this->assertCount( 5, array_unique( $motivos ) );
		$this->assertContains( 'agotados los reintentos', $motivos );
	}

	// -- sweep_minutos() -------------------------------------------------
	//
	// PURA y sin WooCommerce: si get_date_paid() es null, rest_sweep() no debe
	// quedarse en 0 minutos para siempre (eso deja al pedido invisible para el
	// barredor de por vida). Ancla de tiempo fija ($ahora) para no depender del
	// reloj del runner.

	private const SWEEP_AHORA = 1893456000;

	public function test_minutos_con_fecha_de_pago_normal(): void {
		$pagado = self::SWEEP_AHORA - ( 45 * 60 );
		$this->assertSame( 45, CCMCK_Guias::sweep_minutos( $pagado, null, self::SWEEP_AHORA ) );
	}

	/** Sin fecha de pago pero con fecha de creación: debe usar la de creación. */
	public function test_minutos_sin_fecha_de_pago_usa_la_de_creacion(): void {
		$creado = self::SWEEP_AHORA - ( 20 * 60 );
		$this->assertSame( 20, CCMCK_Guias::sweep_minutos( null, $creado, self::SWEEP_AHORA ) );
	}

	/** Sin ninguna de las dos marcas: 0, no un error ni un pedido invisible. */
	public function test_minutos_sin_pago_ni_creacion_da_cero(): void {
		$this->assertSame( 0, CCMCK_Guias::sweep_minutos( null, null, self::SWEEP_AHORA ) );
	}

	/** Un pago no positivo (0) cuenta como "no hay fecha de pago": cae a creación. */
	public function test_minutos_pago_no_positivo_cae_a_creacion(): void {
		$creado = self::SWEEP_AHORA - ( 10 * 60 );
		$this->assertSame( 10, CCMCK_Guias::sweep_minutos( 0, $creado, self::SWEEP_AHORA ) );
	}

	/** Marcas futuras (reloj desincronizado) nunca deben dar minutos negativos. */
	public function test_minutos_nunca_es_negativo_con_marcas_futuras(): void {
		$futuro = self::SWEEP_AHORA + 3600;
		$this->assertSame( 0, CCMCK_Guias::sweep_minutos( $futuro, null, self::SWEEP_AHORA ) );
		$this->assertSame( 0, CCMCK_Guias::sweep_minutos( null, $futuro, self::SWEEP_AHORA ) );
	}

	// -- sweep_alerta_decision() (I2) -------------------------------------
	//
	// AT-MOST-ONCE: el correo de "se rindió" debe salir una sola vez por
	// pedido. La decisión (¿marcar? ¿qué responder?) se prueba aquí, pura y
	// sin WooCommerce; rest_sweep() solo aplica el efecto (escribir la meta).

	/** Primera vez que el pedido se rinde: hay que marcar y el workflow debe avisar. */
	public function test_alerta_se_marca_la_primera_vez_que_se_rinde(): void {
		$r = CCMCK_Guias::sweep_alerta_decision( 'agotados los reintentos', '' );
		$this->assertTrue( $r['marcar'] );
		$this->assertFalse( $r['alerta_enviada'] );
	}

	/** Ya se había avisado: no se vuelve a marcar (ya está) y el workflow NO debe avisar otra vez. */
	public function test_alerta_no_se_repite_si_ya_se_habia_avisado(): void {
		$r = CCMCK_Guias::sweep_alerta_decision( 'agotados los reintentos', (string) time() );
		$this->assertFalse( $r['marcar'] );
		$this->assertTrue( $r['alerta_enviada'] );
	}

	/** Un espacio en blanco no es "ya avisado": mismo criterio que existing_guia. */
	public function test_alerta_meta_en_blanco_no_cuenta_como_ya_avisado(): void {
		$r = CCMCK_Guias::sweep_alerta_decision( 'agotados los reintentos', '   ' );
		$this->assertTrue( $r['marcar'] );
		$this->assertFalse( $r['alerta_enviada'] );
	}

	/** Cualquier otro motivo de descarte (gracia, otra transportadora, etc.) no toca esta meta. */
	public function test_alerta_no_se_marca_para_otros_motivos_de_descarte(): void {
		foreach ( array( 'demasiado reciente', 'el pedido ya tiene guía', 'envío con otra transportadora', 'generación en curso (lock)', '' ) as $motivo ) {
			$r = CCMCK_Guias::sweep_alerta_decision( $motivo, '' );
			$this->assertFalse( $r['marcar'], "motivo '$motivo' no debe marcar la alerta" );
			$this->assertFalse( $r['alerta_enviada'], "motivo '$motivo' no debe reportar alerta enviada" );
		}
	}
}
