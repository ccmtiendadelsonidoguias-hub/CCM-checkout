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

    // --- fix_b_tags: neutraliza el <div> dentro de <b> de Addi ---

    public function test_fix_b_tags_converts_block_wrapping_b_to_span(): void {
        // El caso que rompe el DOM: <div> dentro de <b>.
        $in  = '<b> <div class="mini-frame">x</div></b>';
        $out = CCMCK_Payments::fix_b_tags( $in );
        $this->assertStringNotContainsString( '<b', $out );
        $this->assertStringNotContainsString( '</b>', $out );
        $this->assertStringContainsString( '<span> <div class="mini-frame">x</div></span>', $out );
    }

    public function test_fix_b_tags_preserves_attributes(): void {
        $this->assertSame( '<span class="title">Cuotas</span>', CCMCK_Payments::fix_b_tags( '<b class="title">Cuotas</b>' ) );
    }

    public function test_fix_b_tags_leaves_html_without_b_untouched(): void {
        $html = '<div class="addi_description_container"><p>texto</p></div>';
        $this->assertSame( $html, CCMCK_Payments::fix_b_tags( $html ) );
    }
}
