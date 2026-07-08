<?php
use PHPUnit\Framework\TestCase;

final class CoordinadoraTest extends TestCase {
    // --- dane_from_city ---
    public function test_dane_from_plain_code(): void {
        $this->assertSame( '05001000', CCMCK_Coordinadora::dane_from_city( '05001000' ) );
    }
    public function test_dane_from_labeled_city(): void {
        $this->assertSame( '05001000', CCMCK_Coordinadora::dane_from_city( 'MEDELLIN (ANT) (05001000)' ) );
    }
    public function test_dane_from_garbage_is_empty(): void {
        $this->assertSame( '', CCMCK_Coordinadora::dane_from_city( 'Bogota' ) );
    }

    // --- classify_item ---
    public function test_classify_rule_category_wins_over_weight(): void {
        $item = array( 'cat_ids' => array( 1253 ), 'weight' => 12.0 );
        $c = CCMCK_Coordinadora::classify_item( $item, 5.0, array( 1253 => 2 ) );
        $this->assertSame( 'rule', $c['kind'] );
        $this->assertSame( 2, $c['units_per_box'] );
    }
    public function test_classify_heavy_when_no_rule_and_over_threshold(): void {
        $item = array( 'cat_ids' => array( 99 ), 'weight' => 8.0 );
        $c = CCMCK_Coordinadora::classify_item( $item, 5.0, array( 1253 => 2 ) );
        $this->assertSame( 'heavy', $c['kind'] );
        $this->assertSame( 1, $c['units_per_box'] );
    }
    public function test_classify_small_when_under_threshold(): void {
        $item = array( 'cat_ids' => array(), 'weight' => 0.5 );
        $c = CCMCK_Coordinadora::classify_item( $item, 5.0, array() );
        $this->assertSame( 'small', $c['kind'] );
    }

    // --- stack_box ---
    public function test_stack_box_max_footprint_sum_height_and_weight(): void {
        $units = array(
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 50, 'peso' => 15 ),
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 50, 'peso' => 15 ),
        );
        $box = CCMCK_Coordinadora::stack_box( $units );
        $this->assertSame( 60.0, $box['largo'] );
        $this->assertSame( 40.0, $box['ancho'] );
        $this->assertSame( 100.0, $box['alto'] );
        $this->assertSame( 30.0, $box['peso'] );
    }

    // --- build_detalle ---
    public function test_build_detalle_groups_identical_boxes(): void {
        $boxes = array(
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 100, 'peso' => 30 ),
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 100, 'peso' => 30 ),
        );
        $det = CCMCK_Coordinadora::build_detalle( $boxes );
        $this->assertCount( 1, $det );
        $this->assertSame( 2, $det[0]['unidades'] );
        $this->assertSame( 0, $det[0]['ubl'] );
        $this->assertSame( 100.0, $det[0]['alto'] );
    }

    public function test_build_detalle_separates_different_boxes(): void {
        $boxes = array(
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 100, 'peso' => 30 ),
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 50,  'peso' => 15 ),
        );
        $det = CCMCK_Coordinadora::build_detalle( $boxes );
        $this->assertCount( 2, $det );
        $this->assertSame( 1, $det[0]['unidades'] );
        $this->assertSame( 1, $det[1]['unidades'] );
    }

    // --- pack ---
    private function speaker( int $qty ): array {
        return array( 'qty' => $qty, 'weight' => 10.0, 'largo' => 40, 'ancho' => 40, 'alto' => 60, 'cat_ids' => array( 1253 ) );
    }

    public function test_pack_speakers_two_per_box(): void {
        // 4 parlantes, N=2 -> 2 cajas.
        $boxes = CCMCK_Coordinadora::pack( array( $this->speaker( 4 ) ), 5.0, array( 1253 => 2 ) );
        $this->assertCount( 2, $boxes );
        $this->assertSame( 20.0, $boxes[0]['peso'] ); // 2 x 10 kg
    }

    public function test_pack_speakers_odd_leaves_half_box(): void {
        // 5 parlantes, N=2 -> cajas 2,2,1.
        $boxes = CCMCK_Coordinadora::pack( array( $this->speaker( 5 ) ), 5.0, array( 1253 => 2 ) );
        $this->assertCount( 3, $boxes );
        $det = CCMCK_Coordinadora::build_detalle( $boxes );
        $this->assertCount( 2, $det );                 // caja llena + media
        $this->assertSame( 2, $det[0]['unidades'] );
        $this->assertSame( 1, $det[1]['unidades'] );
    }

    public function test_pack_heavy_non_rule_one_per_box(): void {
        $heavy = array( 'qty' => 3, 'weight' => 8.0, 'largo' => 30, 'ancho' => 30, 'alto' => 30, 'cat_ids' => array( 99 ) );
        $boxes = CCMCK_Coordinadora::pack( array( $heavy ), 5.0, array() );
        $this->assertCount( 3, $boxes );
    }

    public function test_pack_small_items_consolidate_into_one_box(): void {
        $acc = array( 'qty' => 6, 'weight' => 0.5, 'largo' => 10, 'ancho' => 10, 'alto' => 5, 'cat_ids' => array() );
        $boxes = CCMCK_Coordinadora::pack( array( $acc ), 5.0, array() );
        $this->assertCount( 1, $boxes );
        $this->assertSame( 3.0, $boxes[0]['peso'] );   // 6 x 0.5 kg
        $this->assertSame( 30.0, $boxes[0]['alto'] );  // 6 x 5 cm (apilado)
    }

    public function test_pack_mixed_cart(): void {
        // 2 parlantes (1 caja) + 1 pesado (1 caja) + 3 accesorios (1 caja) = 3 cajas.
        $heavy = array( 'qty' => 1, 'weight' => 8.0, 'largo' => 30, 'ancho' => 30, 'alto' => 30, 'cat_ids' => array( 99 ) );
        $acc   = array( 'qty' => 3, 'weight' => 0.5, 'largo' => 10, 'ancho' => 10, 'alto' => 5, 'cat_ids' => array() );
        $boxes = CCMCK_Coordinadora::pack( array( $this->speaker( 2 ), $heavy, $acc ), 5.0, array( 1253 => 2 ) );
        $this->assertCount( 3, $boxes );
    }

    // --- build_request ---
    public function test_build_request_shape(): void {
        $req = CCMCK_Coordinadora::build_request( array(
            'nit' => '901677789', 'origen' => '08001000', 'destino' => '11001000',
            'valoracion' => 50000, 'apikey' => 'K', 'clave' => 'C',
            'detalle' => array( array( 'ubl' => 0, 'alto' => 10, 'ancho' => 10, 'largo' => 10, 'peso' => 2, 'unidades' => 1 ) ),
        ) );
        $this->assertSame( '2.0', $req['jsonrpc'] );
        $this->assertSame( 'Cotizador.cotizar', $req['method'] );
        $this->assertSame( '08001000', $req['params']['origen'] );
        $this->assertSame( 2, $req['params']['cuenta'] );
        $this->assertSame( 0, $req['params']['producto'] );
        $this->assertSame( array( array( 'item' => 1 ) ), $req['params']['nivel_servicio'] );
        $this->assertSame( 50000, $req['params']['valoracion'] );
        $this->assertCount( 1, $req['params']['detalle'] );
    }

    // --- parse_response ---
    public function test_parse_response_success(): void {
        $body = '{"jsonrpc":"2.0","id":0,"error":null,"result":{"flete_total":15700,"dias_entrega":"2"}}';
        $r = CCMCK_Coordinadora::parse_response( $body, 200 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 15700, $r['flete_total'] );
        $this->assertSame( 2, $r['dias'] );
    }

    public function test_parse_response_business_error(): void {
        $body = '{"jsonrpc":"2.0","id":0,"error":{"code":0,"message":"Error, apikey no valido"}}';
        $r = CCMCK_Coordinadora::parse_response( $body, 200 );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'apikey', $r['error'] );
    }

    public function test_parse_response_non_json_html(): void {
        $r = CCMCK_Coordinadora::parse_response( '<b>Fatal error</b>', 200 );
        $this->assertFalse( $r['ok'] );
    }

    public function test_parse_response_missing_flete(): void {
        $r = CCMCK_Coordinadora::parse_response( '{"jsonrpc":"2.0","result":{}}', 200 );
        $this->assertFalse( $r['ok'] );
    }

    // --- apply_quote ---
    public function test_apply_quote_replaces_coordinadora_rate(): void {
        $rates = array(
            'coordinadora:3'     => new WC_Shipping_Rate( 'coordinadora:3', 'Coordinadora', 99000 ),
            'ccmck_local_pickup' => new WC_Shipping_Rate( 'ccmck_local_pickup', 'Recogida local', 0 ),
        );
        $out = CCMCK_Coordinadora::apply_quote( $rates, array( 'ok' => true, 'flete_total' => 15700, 'dias' => 2, 'error' => '' ) );
        $this->assertArrayNotHasKey( 'coordinadora:3', $out );
        $this->assertArrayHasKey( 'ccmck_coordinadora', $out );
        $this->assertArrayHasKey( 'ccmck_local_pickup', $out );
        $this->assertSame( 15700.0, $out['ccmck_coordinadora']->get_cost() );
        $this->assertSame( 2, $out['ccmck_coordinadora']->get_meta_data()['dias_entrega'] );
    }

    public function test_apply_quote_failure_keeps_rates_intact(): void {
        $rates = array( 'coordinadora:3' => new WC_Shipping_Rate( 'coordinadora:3', 'Coordinadora', 99000 ) );
        $out = CCMCK_Coordinadora::apply_quote( $rates, array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => 'x' ) );
        $this->assertArrayHasKey( 'coordinadora:3', $out );
        $this->assertArrayNotHasKey( 'ccmck_coordinadora', $out );
    }
}
