<?php
use PHPUnit\Framework\TestCase;

final class CartAjaxTest extends TestCase {

	public function test_sin_tope_el_mas_nunca_se_apaga(): void {
		// -1 es lo que devuelve WooCommerce cuando NO hay tope: no gestiona
		// stock, o admite reservas. Si esto se compara a pelo, el boton de mas
		// sale apagado en TODA la tienda, que es justo lo contrario.
		$this->assertFalse( CCMCK_Cart_Ajax::en_tope( 1, -1 ) );
		$this->assertFalse( CCMCK_Cart_Ajax::en_tope( 99, -1 ) );
		$this->assertFalse( CCMCK_Cart_Ajax::en_tope( 0, -1 ) );
	}

	public function test_cero_tambien_es_sin_tope(): void {
		// Defensivo: 0 no lo devuelve get_max_purchase_quantity(), pero si algun
		// filtro lo cuela, apagar el boton para siempre seria peor que no hacer
		// nada.
		$this->assertFalse( CCMCK_Cart_Ajax::en_tope( 1, 0 ) );
	}

	public function test_ultima_unidad_apaga_el_mas(): void {
		// El caso que motivo esto: queda 1 y el cliente ya la tiene.
		$this->assertTrue( CCMCK_Cart_Ajax::en_tope( 1, 1 ) );
	}

	public function test_por_debajo_del_tope_sigue_encendido(): void {
		$this->assertFalse( CCMCK_Cart_Ajax::en_tope( 0, 1 ) );
		$this->assertFalse( CCMCK_Cart_Ajax::en_tope( 4, 5 ) );
	}

	public function test_por_encima_del_tope_tambien_apaga(): void {
		// El endpoint acepta de buen grado cantidades por encima del stock (esta
		// escrito a proposito en ccmck-cart.js), asi que esta linea EXISTE. El
		// mas tiene que estar apagado tambien ahi.
		$this->assertTrue( CCMCK_Cart_Ajax::en_tope( 6, 5 ) );
	}
}
