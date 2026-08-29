<?php
use PHPUnit\Framework\TestCase;

final class SideCartNoticesTest extends TestCase {

	private function aviso( string $txt ): array {
		return array( 'notice' => $txt, 'data' => array() );
	}

	public function test_devuelve_lo_que_el_cajon_se_llevo(): void {
		$guardadas = array( 'success' => array( $this->aviso( 'Producto eliminado. Deshacer?' ) ) );
		$this->assertSame( $guardadas, CCMCK_Side_Cart_Notices::unir( $guardadas, array() ) );
	}

	public function test_no_pisa_lo_que_haya_llegado_despues(): void {
		// Entre el guardado y la devolucion puede haber entrado algo nuevo. Se
		// suman los dos, no se elige uno.
		$guardadas = array( 'success' => array( $this->aviso( 'Producto eliminado' ) ) );
		$actuales  = array( 'error' => array( $this->aviso( 'No hay suficiente stock' ) ) );
		$union = CCMCK_Side_Cart_Notices::unir( $guardadas, $actuales );
		$this->assertCount( 1, $union['success'] );
		$this->assertCount( 1, $union['error'] );
	}

	public function test_no_duplica_el_mismo_aviso(): void {
		// El de stock lo REGENERA WooCommerce en WC_Shortcode_Cart::output().
		// Si ademas lo devolvemos nosotros, el cliente lo veria dos veces.
		$mismo = array( 'error' => array( $this->aviso( 'No hay suficiente stock de X' ) ) );
		$union = CCMCK_Side_Cart_Notices::unir( $mismo, $mismo );
		$this->assertCount( 1, $union['error'] );
	}

	public function test_conserva_el_orden_de_llegada(): void {
		$guardadas = array( 'success' => array( $this->aviso( 'primero' ) ) );
		$actuales  = array( 'success' => array( $this->aviso( 'segundo' ) ) );
		$union = CCMCK_Side_Cart_Notices::unir( $guardadas, $actuales );
		$this->assertSame( 'primero', $union['success'][0]['notice'] );
		$this->assertSame( 'segundo', $union['success'][1]['notice'] );
	}

	public function test_sin_nada_que_devolver_no_inventa_una_cola(): void {
		// Devolver array() vacio y no null: null es lo que WooCommerce entiende
		// por "sin avisos", y escribirlo por gusto seria tocar la sesion sin
		// necesidad.
		$this->assertSame( array(), CCMCK_Side_Cart_Notices::unir( array(), array() ) );
	}

	public function test_aguanta_formas_que_no_espera(): void {
		// La cola la pueden haber tocado otros plugins.
		$this->assertSame( array(), CCMCK_Side_Cart_Notices::unir( array( 'success' => 'no soy lista' ), array() ) );
	}

	public function test_solo_en_carrito_y_pago(): void {
		// En ficha de producto el cajon SI es la interfaz -- se abre solo al
		// anadir -- y ahi quedarse los avisos es lo correcto.
		$this->assertTrue( CCMCK_Side_Cart_Notices::debe_actuar( true, false ) );
		$this->assertTrue( CCMCK_Side_Cart_Notices::debe_actuar( false, true ) );
		$this->assertFalse( CCMCK_Side_Cart_Notices::debe_actuar( false, false ) );
	}
}
