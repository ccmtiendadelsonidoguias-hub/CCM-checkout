<?php
use PHPUnit\Framework\TestCase;

final class GuiasTest extends TestCase {
    private function args(): array {
        return array(
            'usuario'      => 'ccmtienda.ws',
            'clave_sha256' => str_repeat( 'a', 64 ),
            'id_cliente'   => 49444,
            'remitente'    => array( 'nombre' => 'CCM Tienda del Sonido', 'direccion' => 'Calle 45 30-50', 'telefono' => '3178119077', 'ciudad' => '08001000' ),
            'destinatario' => array( 'nombre' => 'Cliente Prueba', 'direccion' => 'Cra 1J 45-31 apto 2', 'ciudad_dane' => '05001000', 'telefono' => '3014373975', 'documento' => '1002157685' ),
            'valor_declarado' => 675000,
            'contenido'    => 'Equipos de sonido',
            'referencia'   => '1234',
            'observaciones'=> '',
            'detalle'      => array( array( 'ubl' => 0, 'alto' => 50.0, 'ancho' => 50.0, 'largo' => 50.0, 'peso' => 20.0, 'unidades' => 1 ) ),
        );
    }

    // --- build_guia_params: observaciones obligatorias de Coordinadora ---
    public function test_params_meet_coordinadora_observations(): void {
        $p = CCMCK_Guias::build_guia_params( $this->args() );
        $this->assertSame( '', $p['fecha'] );                    // obs 1
        $this->assertSame( '', $p['nit_remitente'] );            // obs 2
        $this->assertSame( 'CCM Tienda del Sonido', $p['nombre_remitente'] ); // obs 3
        $this->assertSame( '08001000', $p['ciudad_remitente'] ); // obs 4
        $this->assertSame( 'IMPRESO', $p['estado'] );
        $this->assertSame( 0, $p['id_remitente'] );
        $this->assertSame( array(), $p['recaudos'] );
        $this->assertSame( 2, $p['codigo_cuenta'] );
        $this->assertSame( 0, $p['codigo_producto'] );
        $this->assertSame( 1, $p['nivel_servicio'] );
        $this->assertSame( 49444, $p['id_cliente'] );
    }

    public function test_params_map_destinatario_and_detail(): void {
        $p = CCMCK_Guias::build_guia_params( $this->args() );
        $this->assertSame( 'Cliente Prueba', $p['nombre_destinatario'] );
        $this->assertSame( '05001000', $p['ciudad_destinatario'] );
        $this->assertSame( '1002157685', $p['nit_destinatario'] );
        $this->assertSame( '', $p['div_destinatario'] );
        $this->assertSame( 675000, $p['valor_declarado'] );
        $this->assertSame( '1234', $p['referencia'] );
        $this->assertCount( 1, $p['detalle'] );
        $this->assertSame( 'Caja', $p['detalle'][0]['nombre_empaque'] );
        $this->assertSame( '', $p['detalle'][0]['referencia'] );
        $this->assertSame( str_repeat( 'a', 64 ), $p['clave'] );
    }

    // --- should_generate ---
    private function ctx( array $over = array() ): array {
        return array_merge( array(
            'enabled'       => true,
            'usuario'       => 'ccmtienda.ws',
            'clave'         => 'x',
            'shipping_ids'  => array( 'ccmck_coordinadora' ),
            'existing_guia' => '',
            'has_lock'      => false,
        ), $over );
    }

    public function test_should_generate_ok(): void {
        $this->assertTrue( CCMCK_Guias::should_generate( $this->ctx() )['ok'] );
    }
    public function test_should_generate_blocks_disabled(): void {
        $r = CCMCK_Guias::should_generate( $this->ctx( array( 'enabled' => false ) ) );
        $this->assertFalse( $r['ok'] );
    }
    public function test_should_generate_blocks_pickup(): void {
        $r = CCMCK_Guias::should_generate( $this->ctx( array( 'shipping_ids' => array( 'local_pickup' ) ) ) );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'recogida', strtolower( $r['reason'] ) );

        $r2 = CCMCK_Guias::should_generate( $this->ctx( array( 'shipping_ids' => array( 'ccmck_local_pickup' ) ) ) );
        $this->assertFalse( $r2['ok'] );
    }
    public function test_should_generate_blocks_existing_guia(): void {
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'existing_guia' => '33042000009' ) ) )['ok'] );
    }
    public function test_should_generate_blocks_lock_and_missing_creds(): void {
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'has_lock' => true ) ) )['ok'] );
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'clave' => '' ) ) )['ok'] );
    }

    // --- parse_guia_response ---
    public function test_parse_guia_success(): void {
        $body = '{"jsonrpc":2,"id":0,"error":null,"result":{"id_remision":48454758,"codigo_remision":"33042000009","pdf_guia":"","url_terceros":"http://x.co/vmi/?guia=330","referencia":"1234"}}';
        $r = CCMCK_Guias::parse_guia_response( $body, 200 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( '33042000009', $r['codigo_remision'] );
        $this->assertSame( 48454758, $r['id_remision'] );
        $this->assertSame( 'http://x.co/vmi/?guia=330', $r['tracking_url'] );
    }

    public function test_parse_guia_business_error(): void {
        $r = CCMCK_Guias::parse_guia_response( '{"jsonrpc":2,"id":0,"error":{"code":"-1","message":"Exception: Usuario o clave invalido"}}', 200 );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'invalido', $r['error'] );
    }

    public function test_parse_guia_non_json_and_missing_code(): void {
        $this->assertFalse( CCMCK_Guias::parse_guia_response( '<b>Fatal</b>', 200 )['ok'] );
        $this->assertFalse( CCMCK_Guias::parse_guia_response( '{"jsonrpc":2,"result":{"codigo_remision":""}}', 200 )['ok'] );
    }

    // --- build_webhook_payload (contrato del endpoint cwGuiaWa01 en n8n) ---
    public function test_webhook_payload_matches_n8n_contract(): void {
        $p = CCMCK_Guias::build_webhook_payload( array(
            'order_id' => '1234', 'guia' => '33042000009',
            'tracking_url' => 'http://x.co/t', 'name' => 'Cliente Prueba',
            'phone' => '3014373975',
        ) );
        // Forma EXACTA que espera el webhook: campos planos, sin extras.
        $this->assertSame(
            array( 'order_id', 'phone', 'guia', 'tracking_url', 'customer_name' ),
            array_keys( $p )
        );
        $this->assertSame( '1234', $p['order_id'] );
        $this->assertSame( '3014373975', $p['phone'] );
        $this->assertSame( '33042000009', $p['guia'] );
        $this->assertSame( 'http://x.co/t', $p['tracking_url'] );
        $this->assertSame( 'Cliente Prueba', $p['customer_name'] );
    }

    // --- guia_box_markup ---
    public function test_guia_box_markup_renders_links(): void {
        $html = CCMCK_Guias::guia_box_markup( '33042000009', 'http://x.co/t', 'http://admin.x/label' );
        $this->assertStringContainsString( '33042000009', $html );
        $this->assertStringContainsString( 'http://x.co/t', $html );
        $this->assertStringContainsString( 'http://admin.x/label', $html );
        $this->assertStringContainsString( 'Descargar rótulo', $html );
    }

    public function test_guia_box_markup_empty_when_no_guia(): void {
        $this->assertSame( '', CCMCK_Guias::guia_box_markup( '', 'x', 'y' ) );
    }

    // --- resolve_destino (meta DANE del checkout > ciudad de envío > facturación) ---
    public function test_resolve_destino_prefers_checkout_meta(): void {
        $this->assertSame( '08001000', CCMCK_Guias::resolve_destino( '08001000', 'MEDELLIN (ANT) (05001000)', 'CALI (VAC) (76001000)' ) );
    }

    public function test_resolve_destino_falls_back_to_shipping_city(): void {
        $this->assertSame( '05001000', CCMCK_Guias::resolve_destino( '', 'MEDELLIN (ANT) (05001000)', 'CALI (VAC) (76001000)' ) );
    }

    public function test_resolve_destino_falls_back_to_billing_city(): void {
        $this->assertSame( '76001000', CCMCK_Guias::resolve_destino( '', '', '76001000' ) );
    }

    public function test_resolve_destino_invalid_meta_falls_through(): void {
        // Meta corrupto (no son 8 dígitos) no debe ganar.
        $this->assertSame( '05001000', CCMCK_Guias::resolve_destino( 'basura', 'MEDELLIN (ANT) (05001000)', '' ) );
    }

    public function test_resolve_destino_trimmed_cities_yield_empty(): void {
        // Caso real: el plugin de ciudades recorta el DANE al guardar el pedido.
        $this->assertSame( '', CCMCK_Guias::resolve_destino( '', 'BARRANQUILLA (ATL)', 'BARRANQUILLA (ATL)' ) );
    }

    // --- generación manual (botón en el pedido) ---
    public function test_manual_skips_pickup_and_enabled_guards(): void {
        // El botón es acción deliberada del admin: ignora el toggle de
        // generación automática y la exclusión de recogida local.
        $r = CCMCK_Guias::should_generate( $this->ctx( array(
            'manual'       => true,
            'enabled'      => false,
            'shipping_ids' => array( 'local_pickup' ),
        ) ) );
        $this->assertTrue( $r['ok'] );
    }

    public function test_manual_still_blocks_existing_guia_and_lock(): void {
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'manual' => true, 'existing_guia' => '33042000011' ) ) )['ok'] );
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'manual' => true, 'has_lock' => true ) ) )['ok'] );
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'manual' => true, 'clave' => '' ) ) )['ok'] );
    }

    // --- generate_button_markup ---
    public function test_generate_button_markup_renders_link_and_confirm(): void {
        $html = CCMCK_Guias::generate_button_markup( 'http://admin.x/generate' );
        $this->assertStringContainsString( 'http://admin.x/generate', $html );
        $this->assertStringContainsString( 'Generar guía Coordinadora', $html );
        $this->assertStringContainsString( 'confirm(', $html );
    }

    public function test_generate_button_markup_empty_url(): void {
        $this->assertSame( '', CCMCK_Guias::generate_button_markup( '' ) );
    }
}
