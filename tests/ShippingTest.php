<?php
use PHPUnit\Framework\TestCase;

final class ShippingTest extends TestCase {
    /** Crea un stub de WC_Shipping_Rate con get_id/get_label/get_cost. */
    private function rate( string $id, string $label, float $cost ): object {
        return new class( $id, $label, $cost ) {
            public $id; public $label; public $cost;
            public function __construct( $i, $l, $c ) { $this->id = $i; $this->label = $l; $this->cost = $c; }
            public function get_id() { return $this->id; }
            public function get_label() { return $this->label; }
            public function get_cost() { return $this->cost; }
        };
    }

    public function test_build_marks_the_chosen_rate(): void {
        $packages = array( array( 'rates' => array(
            $this->rate( 'coordinadora:1', 'Coordinadora', 12900 ),
            $this->rate( 'local_pickup:2', 'Recoger en tienda', 0 ),
        ) ) );
        $out = CCMCK_Shipping::build_methods( $packages, array( 0 => 'local_pickup:2' ) );
        $this->assertFalse( $out[0]['rates'][0]['checked'] );
        $this->assertTrue( $out[0]['rates'][1]['checked'] );
        $this->assertSame( 0, $out[0]['index'] );
    }

    public function test_build_defaults_to_first_when_none_chosen(): void {
        $packages = array( array( 'rates' => array( $this->rate( 'a', 'A', 100 ) ) ) );
        $out = CCMCK_Shipping::build_methods( $packages, array() );
        $this->assertTrue( $out[0]['rates'][0]['checked'] );
    }

    public function test_render_cards_emits_radio_label_and_cost(): void {
        $methods = array( array( 'index' => 0, 'rates' => array(
            array( 'id' => 'coordinadora:1', 'label' => 'Coordinadora', 'cost' => 12900.0, 'checked' => true ),
        ) ) );
        $html = CCMCK_Shipping::render_cards( $methods );
        $this->assertStringContainsString( 'name="shipping_method[0]"', $html );
        $this->assertStringContainsString( 'value="coordinadora:1"', $html );
        $this->assertStringContainsString( 'Coordinadora', $html );
        $this->assertStringContainsString( 'checked', $html );
        $this->assertStringContainsString( '12.900', $html );
    }

    public function test_render_cards_empty_shows_notice(): void {
        $this->assertStringContainsString( 'ccmck-no-shipping', CCMCK_Shipping::render_cards( array() ) );
    }
}
