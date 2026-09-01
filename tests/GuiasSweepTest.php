<?php
use PHPUnit\Framework\TestCase;

final class GuiasSweepTest extends TestCase {

	/** Contexto de un pedido que SÍ debe barrerse; cada prueba cambia una cosa. */
	private function ctx( array $over = array() ): array {
		return array_merge( array(
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
}
