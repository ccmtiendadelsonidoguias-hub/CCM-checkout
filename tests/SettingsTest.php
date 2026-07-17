<?php
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
    public function test_sanitize_keeps_valid_hex_and_drops_invalid(): void {
        $out = CCMCK_Settings::sanitize( array( 'accent_color' => '#e63946', 'sidebar_color' => 'rojo' ) );
        $this->assertSame( '#e63946', $out['accent_color'] );
        $this->assertSame( '', $out['sidebar_color'] );
    }

    public function test_sanitize_button_color_hex_or_empty(): void {
        $this->assertSame( '#0a84ff', CCMCK_Settings::sanitize( array( 'button_color' => '#0a84ff' ) )['button_color'] );
        $this->assertSame( '', CCMCK_Settings::sanitize( array( 'button_color' => 'azul' ) )['button_color'] );
        $this->assertSame( '', CCMCK_Settings::sanitize( array() )['button_color'] );
    }

    public function test_defaults_button_color_empty(): void {
        $this->assertArrayHasKey( 'button_color', CCMCK_Settings::defaults() );
        $this->assertSame( '', CCMCK_Settings::defaults()['button_color'] );
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

    public function test_sanitize_surcharge_rate_accepts_comma_and_clamps(): void {
        $this->assertSame( 12.5, CCMCK_Settings::sanitize( array( 'surcharge_rate' => '12,5' ) )['surcharge_rate'] );
        $this->assertSame( 0.0, CCMCK_Settings::sanitize( array( 'surcharge_rate' => '-3' ) )['surcharge_rate'] );
        $this->assertSame( 100.0, CCMCK_Settings::sanitize( array( 'surcharge_rate' => '250' ) )['surcharge_rate'] );
    }

    public function test_sanitize_surcharge_rate_defaults_to_10_48(): void {
        $this->assertSame( 10.48, CCMCK_Settings::sanitize( array() )['surcharge_rate'] );
    }

    public function test_sanitize_surcharge_brands_ints_unique_no_zero(): void {
        $out = CCMCK_Settings::sanitize( array( 'surcharge_brands' => array( '1253', '0', '1253', 'abc', 1638 ) ) );
        $this->assertSame( array( 1253, 1638 ), $out['surcharge_brands'] );
    }

    public function test_sanitize_surcharge_brands_empty_by_default(): void {
        $this->assertSame( array(), CCMCK_Settings::sanitize( array() )['surcharge_brands'] );
    }

    public function test_defaults_include_pickup_notice(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertArrayHasKey( 'pickup_notice', $d );
        $this->assertStringContainsString( 'Barranquilla', $d['pickup_notice'] );
    }

    public function test_sanitize_pickup_notice_strips_tags(): void {
        $out = CCMCK_Settings::sanitize( array( 'pickup_notice' => '<b>Recoge</b> en <script>x</script>tienda' ) );
        $this->assertStringNotContainsString( '<b>', $out['pickup_notice'] );
        $this->assertStringNotContainsString( '<script>', $out['pickup_notice'] );
        $this->assertStringContainsString( 'Recoge', $out['pickup_notice'] );
    }

    public function test_sanitize_pickup_notice_missing_becomes_empty(): void {
        $this->assertSame( '', CCMCK_Settings::sanitize( array() )['pickup_notice'] );
    }

    public function test_sanitize_pickup_notice_is_idempotent(): void {
        $once  = CCMCK_Settings::sanitize( array( 'pickup_notice' => "Recoge\nen tienda <b>x</b>" ) )['pickup_notice'];
        $twice = CCMCK_Settings::sanitize( array( 'pickup_notice' => $once ) )['pickup_notice'];
        $this->assertSame( $once, $twice );
    }

    public function test_defaults_include_coordinadora_keys(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertFalse( $d['coordinadora_enabled'] );
        $this->assertSame( '901677789', $d['coordinadora_nit'] );
        $this->assertSame( '08001000', $d['coordinadora_origin'] );
        $this->assertSame( 5.0, $d['coordinadora_weight_threshold'] );
        $this->assertSame( array(), $d['coordinadora_box_rules'] );
    }

    public function test_sanitize_coordinadora_enabled_boolean(): void {
        $this->assertTrue( CCMCK_Settings::sanitize( array( 'coordinadora_enabled' => '1' ) )['coordinadora_enabled'] );
        $this->assertFalse( CCMCK_Settings::sanitize( array() )['coordinadora_enabled'] );
    }

    public function test_sanitize_nit_and_origin_keep_only_digits(): void {
        $out = CCMCK_Settings::sanitize( array( 'coordinadora_nit' => '901.677.789-0', 'coordinadora_origin' => 'DANE 08001000' ) );
        $this->assertSame( '9016777890', $out['coordinadora_nit'] );
        $this->assertSame( '08001000', $out['coordinadora_origin'] );
    }

    public function test_sanitize_weight_threshold_comma_and_floor(): void {
        $this->assertSame( 7.5, CCMCK_Settings::sanitize( array( 'coordinadora_weight_threshold' => '7,5' ) )['coordinadora_weight_threshold'] );
        $this->assertSame( 0.0, CCMCK_Settings::sanitize( array( 'coordinadora_weight_threshold' => '-2' ) )['coordinadora_weight_threshold'] );
        $this->assertSame( 5.0, CCMCK_Settings::sanitize( array() )['coordinadora_weight_threshold'] );
    }

    public function test_sanitize_box_rules_drops_invalid_and_dedupes_category(): void {
        $out = CCMCK_Settings::sanitize( array( 'coordinadora_box_rules' => array(
            array( 'cat' => '1253', 'n' => '2' ),
            array( 'cat' => '0',    'n' => '3' ),   // cat inválida
            array( 'cat' => '1400', 'n' => '0' ),   // n inválido
            array( 'cat' => '1253', 'n' => '9' ),   // duplicada -> se ignora
        ) ) );
        $this->assertSame( array( array( 'cat' => 1253, 'n' => 2 ) ), $out['coordinadora_box_rules'] );
    }

    public function test_defaults_include_guias_keys(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertFalse( $d['guias_enabled'] );
        $this->assertSame( 'sandbox', $d['guias_env'] );
        $this->assertSame( 'ccmtienda.ws', $d['guias_usuario'] );
        $this->assertSame( '', $d['guias_clave'] );
        $this->assertSame( 49444, $d['guias_id_cliente'] );
        $this->assertSame( 'CCM Tienda del Sonido', $d['guias_remitente_nombre'] );
        $this->assertSame( '', $d['guias_webhook_url'] );
    }

    public function test_sanitize_guias_env_whitelist(): void {
        $this->assertSame( 'production', CCMCK_Settings::sanitize( array( 'guias_env' => 'production' ) )['guias_env'] );
        $this->assertSame( 'sandbox', CCMCK_Settings::sanitize( array( 'guias_env' => 'otro' ) )['guias_env'] );
        $this->assertSame( 'sandbox', CCMCK_Settings::sanitize( array() )['guias_env'] );
    }

    public function test_sanitize_guias_id_cliente_and_phone(): void {
        $out = CCMCK_Settings::sanitize( array( 'guias_id_cliente' => '49444x', 'guias_remitente_telefono' => '+57 317-811' ) );
        $this->assertSame( 49444, $out['guias_id_cliente'] );
        $this->assertSame( '57317811', $out['guias_remitente_telefono'] );
    }

    public function test_sanitize_guias_webhook_url(): void {
        $this->assertSame( 'https://n8n.x.co/webhook/abc', CCMCK_Settings::sanitize( array( 'guias_webhook_url' => 'https://n8n.x.co/webhook/abc' ) )['guias_webhook_url'] );
        $this->assertSame( '', CCMCK_Settings::sanitize( array( 'guias_webhook_url' => 'javascript:x' ) )['guias_webhook_url'] );
    }

    public function test_defaults_and_sanitize_guias_contra_entrega(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertSame( 49445, $d['guias_id_cliente_ce'] );
        $this->assertSame( 3, $d['guias_cuenta_ce'] );
        $out = CCMCK_Settings::sanitize( array( 'guias_id_cliente_ce' => '49445x', 'guias_cuenta_ce' => '6' ) );
        $this->assertSame( 49445, $out['guias_id_cliente_ce'] );
        $this->assertSame( 6, $out['guias_cuenta_ce'] );
        $this->assertSame( 3, CCMCK_Settings::sanitize( array() )['guias_cuenta_ce'] );
    }

    public function test_defaults_and_sanitize_pickup_ask_and_api_secret(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertSame( '', $d['guias_pickup_ask_url'] );
        $this->assertSame( '', $d['guias_api_secret'] );
        $out = CCMCK_Settings::sanitize( array(
            'guias_pickup_ask_url' => 'https://n8n.x.co/webhook/ask?tok=abc',
            'guias_api_secret'     => '  s3creto ',
        ) );
        $this->assertSame( 'https://n8n.x.co/webhook/ask?tok=abc', $out['guias_pickup_ask_url'] );
        $this->assertSame( 's3creto', $out['guias_api_secret'] );
        $this->assertSame( '', CCMCK_Settings::sanitize( array( 'guias_pickup_ask_url' => 'javascript:x' ) )['guias_pickup_ask_url'] );
    }
}
