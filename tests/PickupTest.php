<?php
use PHPUnit\Framework\TestCase;

final class PickupTest extends TestCase {
    public function test_is_pickup_rate_matches_own_id(): void {
        $this->assertTrue( CCMCK_Pickup::is_pickup_rate( 'ccmck_local_pickup' ) );
    }

    public function test_is_pickup_rate_rejects_other_methods(): void {
        $this->assertFalse( CCMCK_Pickup::is_pickup_rate( 'coordinadora:3' ) );
        $this->assertFalse( CCMCK_Pickup::is_pickup_rate( '' ) );
    }

    public function test_chosen_is_pickup_true_when_any_package_is_pickup(): void {
        $this->assertTrue( CCMCK_Pickup::chosen_is_pickup( array( 0 => 'ccmck_local_pickup' ) ) );
        $this->assertTrue( CCMCK_Pickup::chosen_is_pickup( array( 0 => 'coordinadora:3', 1 => 'ccmck_local_pickup' ) ) );
    }

    public function test_chosen_is_pickup_false_otherwise(): void {
        $this->assertFalse( CCMCK_Pickup::chosen_is_pickup( array( 0 => 'coordinadora:3' ) ) );
        $this->assertFalse( CCMCK_Pickup::chosen_is_pickup( array() ) );
    }

    public function test_inject_adds_free_pickup_rate(): void {
        $rates = CCMCK_Pickup::inject( array(), array() );
        $this->assertArrayHasKey( CCMCK_Pickup::RATE_ID, $rates );
        $rate = $rates[ CCMCK_Pickup::RATE_ID ];
        $this->assertSame( 'Recogida local', $rate->get_label() );
        $this->assertSame( 0.0, (float) $rate->get_cost() );
    }

    public function test_inject_is_idempotent(): void {
        $rates = CCMCK_Pickup::inject( array(), array() );
        $again = CCMCK_Pickup::inject( $rates, array() );
        $this->assertCount( 1, $again );
    }

    public function test_inject_preserves_existing_rates(): void {
        $existing = array( 'coordinadora:3' => new WC_Shipping_Rate( 'coordinadora:3', 'Coordinadora', 19350 ) );
        $rates = CCMCK_Pickup::inject( $existing, array() );
        $this->assertArrayHasKey( 'coordinadora:3', $rates );
        $this->assertArrayHasKey( CCMCK_Pickup::RATE_ID, $rates );
    }

    public function test_relax_fields_makes_address_optional_when_pickup(): void {
        $fields = array( 'billing' => array(
            'billing_address_1' => array( 'required' => true,  'label' => 'Dirección' ),
            'billing_city'      => array( 'required' => true,  'label' => 'Ciudad' ),
            'billing_state'     => array( 'required' => true,  'label' => 'Departamento' ),
            'billing_postcode'  => array( 'required' => true,  'label' => 'Cédula / NIT' ),
            'billing_email'     => array( 'required' => true,  'label' => 'Email' ),
        ) );
        $out = CCMCK_Pickup::relax_fields( $fields, true );
        $this->assertFalse( $out['billing']['billing_address_1']['required'] );
        $this->assertFalse( $out['billing']['billing_city']['required'] );
        $this->assertFalse( $out['billing']['billing_state']['required'] );
        // El email NO es un campo de dirección: sigue obligatorio.
        $this->assertTrue( $out['billing']['billing_email']['required'] );
        // billing_postcode está rotulado "Cédula / NIT" y el cliente debe llenarlo
        // aun en pickup: NO se relaja.
        $this->assertTrue( $out['billing']['billing_postcode']['required'] );
    }

    public function test_relax_fields_noop_when_not_pickup(): void {
        $fields = array( 'billing' => array(
            'billing_address_1' => array( 'required' => true ),
        ) );
        $out = CCMCK_Pickup::relax_fields( $fields, false );
        $this->assertTrue( $out['billing']['billing_address_1']['required'] );
    }

    public function test_current_is_pickup_reads_post_array(): void {
        $_POST['shipping_method'] = array( 0 => CCMCK_Pickup::RATE_ID );
        try {
            $this->assertTrue( CCMCK_Pickup::current_is_pickup() );
        } finally {
            unset( $_POST['shipping_method'] );
        }
    }

    public function test_current_is_pickup_reads_post_scalar(): void {
        $_POST['shipping_method'] = 'coordinadora:3';
        try {
            $this->assertFalse( CCMCK_Pickup::current_is_pickup() );
        } finally {
            unset( $_POST['shipping_method'] );
        }
    }
}
