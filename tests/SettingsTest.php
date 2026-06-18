<?php
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
    public function test_sanitize_keeps_valid_hex_and_drops_invalid(): void {
        $out = CCMCK_Settings::sanitize( array( 'accent_color' => '#e63946', 'sidebar_color' => 'rojo' ) );
        $this->assertSame( '#e63946', $out['accent_color'] );
        $this->assertSame( '', $out['sidebar_color'] );
    }

    public function test_sanitize_whatsapp_enabled_is_boolean(): void {
        $out = CCMCK_Settings::sanitize( array( 'whatsapp_enabled' => '1' ) );
        $this->assertTrue( $out['whatsapp_enabled'] );
        $out = CCMCK_Settings::sanitize( array() );
        $this->assertFalse( $out['whatsapp_enabled'] );
    }

    public function test_sanitize_footer_links_keep_only_valid_urls(): void {
        $out = CCMCK_Settings::sanitize( array(
            'footer_links' => array(
                array( 'label' => 'Términos', 'url' => 'https://x.co/t' ),
                array( 'label' => 'Malo', 'url' => 'javascript:alert(1)' ),
            ),
        ) );
        $this->assertCount( 1, $out['footer_links'] );
        $this->assertSame( 'https://x.co/t', $out['footer_links'][0]['url'] );
    }

    public function test_sanitize_cards_drops_rows_without_title(): void {
        $out = CCMCK_Settings::sanitize( array( 'shipping_cards' => array(
            array( 'icon' => '🚚', 'title' => '1-3 días', 'text' => 'x' ),
            array( 'icon' => '📦', 'title' => '', 'text' => 'y' ),
        ) ) );
        $this->assertCount( 1, $out['shipping_cards'] );
        $this->assertSame( '1-3 días', $out['shipping_cards'][0]['title'] );
    }

    public function test_sanitize_tracker_labels_capped_at_four(): void {
        $out = CCMCK_Settings::sanitize( array( 'tracker_labels' => array( 'a','b','c','d','e' ) ) );
        $this->assertCount( 4, $out['tracker_labels'] );
    }

    public function test_sanitize_payment_icons_keyed_by_gateway_id(): void {
        $out = CCMCK_Settings::sanitize( array( 'payment_icons' => array(
            'wompi' => array( 'label' => 'Wompi', 'image' => 'https://x.co/w.png', 'bg' => '#00c389' ),
            ''      => array( 'label' => 'skip' ),
        ) ) );
        $this->assertArrayHasKey( 'wompi', $out['payment_icons'] );
        $this->assertArrayNotHasKey( '', $out['payment_icons'] );
        $this->assertSame( '#00c389', $out['payment_icons']['wompi']['bg'] );
    }

    public function test_sanitize_invalid_hex_becomes_empty_not_null(): void {
        $out = CCMCK_Settings::sanitize( array( 'accent_color' => 'nope' ) );
        $this->assertSame( '', $out['accent_color'] );
        $this->assertNotNull( $out['accent_color'] );
    }

    public function test_sanitize_checkout_payment_first_is_boolean(): void {
        $out = CCMCK_Settings::sanitize( array( 'checkout_payment_first' => '1' ) );
        $this->assertTrue( $out['checkout_payment_first'] );
        $out = CCMCK_Settings::sanitize( array() );
        $this->assertFalse( $out['checkout_payment_first'] );
    }

    public function test_defaults_include_checkout_payment_first_off(): void {
        $this->assertArrayHasKey( 'checkout_payment_first', CCMCK_Settings::defaults() );
        $this->assertFalse( CCMCK_Settings::defaults()['checkout_payment_first'] );
    }
}
