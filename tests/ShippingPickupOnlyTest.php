<?php
use PHPUnit\Framework\TestCase;

final class ShippingPickupOnlyTest extends TestCase {

    private function methods( array $rate_ids, bool $checked_first = true ): array {
        $rates = array();
        foreach ( $rate_ids as $i => $id ) {
            $rates[] = array( 'id' => $id, 'label' => $id, 'cost' => 0.0, 'checked' => ( $checked_first && 0 === $i ), 'eta' => 0 );
        }
        return array( array( 'index' => 0, 'rates' => $rates ) );
    }

    public function test_pickup_only_true_when_single_pickup_rate(): void {
        $this->assertTrue( CCMCK_Shipping::is_pickup_only( $this->methods( array( 'ccmck_local_pickup' ) ) ) );
    }

    public function test_pickup_only_false_with_real_shipping(): void {
        $this->assertFalse( CCMCK_Shipping::is_pickup_only( $this->methods( array( 'ccmck_local_pickup', 'coordinadora:1' ) ) ) );
    }

    public function test_pickup_only_false_without_rates(): void {
        $this->assertFalse( CCMCK_Shipping::is_pickup_only( array( array( 'index' => 0, 'rates' => array() ) ) ) );
    }

    public function test_unselect_all_clears_checked(): void {
        $out = CCMCK_Shipping::unselect_all( $this->methods( array( 'ccmck_local_pickup' ) ) );
        $this->assertFalse( $out[0]['rates'][0]['checked'] );
    }
}
