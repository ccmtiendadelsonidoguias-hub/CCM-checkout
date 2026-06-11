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
}
