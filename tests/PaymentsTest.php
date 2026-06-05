<?php
use PHPUnit\Framework\TestCase;

final class PaymentsTest extends TestCase {
    private function gw( string $id ): object {
        return new class( $id ) { public $id; function __construct( $id ){ $this->id = $id; } };
    }

    public function test_hidden_gateways_are_removed(): void {
        $gws = array( 'stripe' => $this->gw('stripe'), 'wompi' => $this->gw('wompi') );
        $out = CCMCK_Payments::sort_and_filter( $gws, array( 'payment_order' => array(), 'payment_hidden' => array( 'wompi' ) ) );
        $this->assertArrayHasKey( 'stripe', $out );
        $this->assertArrayNotHasKey( 'wompi', $out );
    }

    public function test_gateways_are_ordered_by_settings(): void {
        $gws = array( 'a' => $this->gw('a'), 'b' => $this->gw('b'), 'c' => $this->gw('c') );
        $out = CCMCK_Payments::sort_and_filter( $gws, array( 'payment_order' => array( 'c', 'a' ), 'payment_hidden' => array() ) );
        $this->assertSame( array( 'c', 'a', 'b' ), array_keys( $out ) );
    }

    public function test_unlisted_gateways_keep_original_order_at_end(): void {
        $gws = array( 'a' => $this->gw('a'), 'b' => $this->gw('b'), 'c' => $this->gw('c') );
        $out = CCMCK_Payments::sort_and_filter( $gws, array( 'payment_order' => array( 'b' ), 'payment_hidden' => array() ) );
        $this->assertSame( array( 'b', 'a', 'c' ), array_keys( $out ) );
    }
}
