<?php
use PHPUnit\Framework\TestCase;

final class SurchargeTest extends TestCase {

	public function test_addi_and_sistecredito_have_surcharge(): void {
		$this->assertTrue( CCMCK_Surcharge::method_has_surcharge( 'addi' ) );
		$this->assertTrue( CCMCK_Surcharge::method_has_surcharge( 'wcsistecredito' ) );
	}

	public function test_other_methods_have_no_surcharge(): void {
		$this->assertFalse( CCMCK_Surcharge::method_has_surcharge( 'wompi' ) );
		$this->assertFalse( CCMCK_Surcharge::method_has_surcharge( 'woo-mercado-pago-ticket' ) );
		$this->assertFalse( CCMCK_Surcharge::method_has_surcharge( 'woo-mercado-pago-custom' ) );
		$this->assertFalse( CCMCK_Surcharge::method_has_surcharge( '' ) );
	}

	public function test_surcharge_amount_is_10_48_percent(): void {
		// 28.000 * 0,1048 = 2.934,4 → redondeo a 2 decimales.
		$this->assertSame( 2934.4, CCMCK_Surcharge::surcharge_amount( 28000.0 ) );
		// 100.000 * 0,1048 = 10.480.
		$this->assertSame( 10480.0, CCMCK_Surcharge::surcharge_amount( 100000.0 ) );
	}

	public function test_surcharge_amount_zero_for_zero_subtotal(): void {
		$this->assertSame( 0.0, CCMCK_Surcharge::surcharge_amount( 0.0 ) );
	}

	public function test_multiplier_for_surcharge_methods(): void {
		$this->assertSame( 1.1048, CCMCK_Surcharge::multiplier( 'addi' ) );
		$this->assertSame( 1.1048, CCMCK_Surcharge::multiplier( 'wcsistecredito' ) );
	}

	public function test_multiplier_is_one_for_other_methods(): void {
		$this->assertSame( 1.0, CCMCK_Surcharge::multiplier( 'wompi' ) );
		$this->assertSame( 1.0, CCMCK_Surcharge::multiplier( '' ) );
	}

	public function test_product_qualifies_all_when_no_brands_selected(): void {
		// Sin marcas seleccionadas → aplica a todos, tenga o no marca.
		$this->assertTrue( CCMCK_Surcharge::product_qualifies( array(), false ) );
		$this->assertTrue( CCMCK_Surcharge::product_qualifies( array(), true ) );
	}

	public function test_product_qualifies_only_matching_when_brands_selected(): void {
		$this->assertTrue( CCMCK_Surcharge::product_qualifies( array( 1253 ), true ) );
		$this->assertFalse( CCMCK_Surcharge::product_qualifies( array( 1253 ), false ) );
	}
}
