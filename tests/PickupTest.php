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
}
