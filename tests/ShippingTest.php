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
        $this->assertStringContainsString( ' checked', $html );
        $this->assertStringContainsString( '12.900', $html );
    }

    public function test_render_cards_empty_shows_notice(): void {
        $this->assertStringContainsString( 'ccmck-no-shipping', CCMCK_Shipping::render_cards( array() ) );
    }

    public function test_build_aligns_chosen_per_package_index(): void {
        $packages = array(
            array( 'rates' => array( $this->rate( 'a1', 'A1', 100 ), $this->rate( 'a2', 'A2', 200 ) ) ),
            array( 'rates' => array( $this->rate( 'b1', 'B1', 300 ) ) ),
        );
        $out = CCMCK_Shipping::build_methods( $packages, array( 0 => 'a2', 1 => 'b1' ) );
        $this->assertSame( 1, $out[1]['index'] );
        $this->assertFalse( $out[0]['rates'][0]['checked'] );
        $this->assertTrue( $out[0]['rates'][1]['checked'] );
        $this->assertTrue( $out[1]['rates'][0]['checked'] );
    }

    public function test_render_cards_skips_package_with_no_rates(): void {
        $methods = array(
            array( 'index' => 0, 'rates' => array() ),
            array( 'index' => 1, 'rates' => array( array( 'id' => 'x', 'label' => 'X', 'cost' => 0.0, 'checked' => true ) ) ),
        );
        $html = CCMCK_Shipping::render_cards( $methods );
        $this->assertSame( 1, substr_count( $html, '<ul' ) );
    }

    // --- collect_zone_method_labels (PURO) ---

    public function test_collect_labels_keeps_only_enabled_and_unique_in_order(): void {
        $zones = array(
            array( 'methods' => array(
                array( 'title' => 'Coordinadora', 'enabled' => true ),
                array( 'title' => 'Servientrega', 'enabled' => false ),
            ) ),
            array( 'methods' => array(
                array( 'title' => 'Recoger en tienda', 'enabled' => true ),
                array( 'title' => 'Coordinadora', 'enabled' => true ), // duplicado
            ) ),
        );
        $this->assertSame(
            array( 'Coordinadora', 'Recoger en tienda' ),
            CCMCK_Shipping::collect_zone_method_labels( $zones )
        );
    }

    public function test_collect_labels_drops_empty_titles(): void {
        $zones = array(
            array( 'methods' => array(
                array( 'title' => '', 'enabled' => true ),
                array( 'title' => '   ', 'enabled' => true ),
                array( 'title' => 'Coordinadora', 'enabled' => true ),
            ) ),
        );
        $this->assertSame( array( 'Coordinadora' ), CCMCK_Shipping::collect_zone_method_labels( $zones ) );
    }

    public function test_collect_labels_empty_input(): void {
        $this->assertSame( array(), CCMCK_Shipping::collect_zone_method_labels( array() ) );
    }

    // --- render_placeholder_cards (PURO) ---

    public function test_placeholder_renders_each_label_disabled_without_cost(): void {
        $html = CCMCK_Shipping::render_placeholder_cards( array( 'Coordinadora', 'Recoger en tienda' ) );
        $this->assertStringContainsString( 'ccmck-shipping-hint', $html );
        $this->assertStringContainsString( 'Coordinadora', $html );
        $this->assertStringContainsString( 'Recoger en tienda', $html );
        $this->assertStringContainsString( 'disabled', $html );
        $this->assertStringContainsString( 'ccmck-shipping-method--disabled', $html );
        $this->assertStringNotContainsString( 'ccmck-ship-cost', $html );
        // No debe postear: sin atributo name en el radio.
        $this->assertStringNotContainsString( 'name="shipping_method', $html );
    }

    public function test_placeholder_empty_labels_returns_empty_string(): void {
        $this->assertSame( '', CCMCK_Shipping::render_placeholder_cards( array() ) );
    }

    // --- extract_zone_methods (PURO) ---

    /** Stub de WC_Shipping_Method: get_title() + propiedad enabled ('yes'/'no'). */
    private function shipMethod( string $title, string $enabled ): object {
        return new class( $title, $enabled ) {
            public $enabled; private $t;
            public function __construct( $t, $e ) { $this->t = $t; $this->enabled = $e; }
            public function get_title() { return $this->t; }
        };
    }

    public function test_extract_from_array_zone_reads_shipping_methods_key(): void {
        // get_zones() entrega cada zona como ARRAY con los métodos bajo 'shipping_methods'.
        $zone = array( 'shipping_methods' => array(
            $this->shipMethod( 'Coordinadora', 'yes' ),
            $this->shipMethod( 'Servientrega', 'no' ),
        ) );
        $this->assertSame(
            array(
                array( 'title' => 'Coordinadora', 'enabled' => true ),
                array( 'title' => 'Servientrega', 'enabled' => false ),
            ),
            CCMCK_Shipping::extract_zone_methods( $zone )
        );
    }

    public function test_extract_from_object_zone_calls_get_shipping_methods(): void {
        // get_zone(0) entrega un OBJETO con get_shipping_methods().
        $zone = new class {
            public $list;
            public function __construct() {
                $m = new class {
                    public $enabled = 'yes';
                    public function get_title() { return 'Recoger en tienda'; }
                };
                $this->list = array( $m );
            }
            public function get_shipping_methods( $enabled_only = false ) { return $this->list; }
        };
        $this->assertSame(
            array( array( 'title' => 'Recoger en tienda', 'enabled' => true ) ),
            CCMCK_Shipping::extract_zone_methods( $zone )
        );
    }

    public function test_extract_unknown_zone_shape_returns_empty(): void {
        $this->assertSame( array(), CCMCK_Shipping::extract_zone_methods( array() ) );
        $this->assertSame( array(), CCMCK_Shipping::extract_zone_methods( 'nope' ) );
    }
}
