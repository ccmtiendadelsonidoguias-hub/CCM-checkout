<?php
use PHPUnit\Framework\TestCase;

final class CotizarTest extends TestCase {

    private function catalog(): array {
        return array(
            'Atlantico'    => array( '08001000' => 'BARRANQUILLA (ATL)', '08758000' => 'SOLEDAD (ATL)' ),
            'Cundinamarca' => array( '11001000' => 'BOGOTA D.C.' ),
        );
    }

    // --- parse_request ---

    public function test_parse_request_valid(): void {
        $r = CCMCK_Cotizar::parse_request( array(
            'items'      => array( array( 'product_id' => 123, 'qty' => 2 ), array( 'product_id' => '456', 'qty' => '1' ) ),
            'city'       => 'BARRANQUILLA (ATL) (08001000)',
            'valoracion' => 2350000,
        ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( array( array( 'product_id' => 123, 'qty' => 2 ), array( 'product_id' => 456, 'qty' => 1 ) ), $r['items'] );
        $this->assertSame( 'BARRANQUILLA (ATL) (08001000)', $r['city'] );
        $this->assertSame( 2350000, $r['valoracion'] );
    }

    public function test_parse_request_missing_items(): void {
        $r = CCMCK_Cotizar::parse_request( array( 'city' => 'x' ) );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'items', $r['error'] );
    }

    public function test_parse_request_items_not_array(): void {
        $r = CCMCK_Cotizar::parse_request( array( 'items' => 'nope' ) );
        $this->assertFalse( $r['ok'] );
    }

    public function test_parse_request_bad_qty_or_id(): void {
        $this->assertFalse( CCMCK_Cotizar::parse_request( array( 'items' => array( array( 'product_id' => 1, 'qty' => 0 ) ) ) )['ok'] );
        $this->assertFalse( CCMCK_Cotizar::parse_request( array( 'items' => array( array( 'product_id' => 0, 'qty' => 1 ) ) ) )['ok'] );
        $this->assertFalse( CCMCK_Cotizar::parse_request( array( 'items' => array( array( 'qty' => 1 ) ) ) )['ok'] );
    }

    public function test_parse_request_defaults(): void {
        $r = CCMCK_Cotizar::parse_request( array( 'items' => array( array( 'product_id' => 9, 'qty' => 1 ) ) ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( '', $r['city'] );
        $this->assertSame( '', $r['state'] );
        $this->assertSame( '', $r['dane'] );
        $this->assertSame( 0, $r['valoracion'] );
    }

    public function test_parse_request_negative_valoracion_clamped(): void {
        $r = CCMCK_Cotizar::parse_request( array( 'items' => array( array( 'product_id' => 9, 'qty' => 1 ) ), 'valoracion' => -5 ) );
        $this->assertSame( 0, $r['valoracion'] );
    }

    // --- resolve_dane ---

    public function test_resolve_dane_explicit_wins(): void {
        $this->assertSame( '08758000', CCMCK_Cotizar::resolve_dane( $this->catalog(), '08758000', 'BOGOTA D.C. (11001000)', 'Cundinamarca' ) );
    }

    public function test_resolve_dane_from_city_value(): void {
        // Formato real del dropdown del checkout: "NOMBRE (ABREV) (DANE)".
        $this->assertSame( '08001000', CCMCK_Cotizar::resolve_dane( $this->catalog(), '', 'BARRANQUILLA (ATL) (08001000)', '' ) );
    }

    public function test_resolve_dane_from_name_and_state(): void {
        // El popup de Venta manda solo el nombre: lookup por nombre dentro del depto.
        $this->assertSame( '08001000', CCMCK_Cotizar::resolve_dane( $this->catalog(), '', 'Barranquilla', 'Atlantico' ) );
        $this->assertSame( '08001000', CCMCK_Cotizar::resolve_dane( $this->catalog(), '', 'barranquilla (atl)', 'ATLANTICO' ) );
        $this->assertSame( '11001000', CCMCK_Cotizar::resolve_dane( $this->catalog(), '', 'Bogota D.C.', 'Cundinamarca' ) );
    }

    /** Catálogo de Sucre: los dos "Tolú" del incidente del pedido #33418. */
    private function catalog_sucre(): array {
        return array(
            'SUCRE' => array(
                '70820000' => 'TOLU (SUCRE)',
                '70823000' => 'TOLU VIEJO (SUCRE)',
                '70001000' => 'SINCELEJO (SUCRE)',
                '70717000' => 'SAN PEDRO (SUCRE)',
            ),
        );
    }

    public function test_resolve_dane_acepta_nombre_oficial_largo(): void {
        // #33418: el asesor escribió el nombre oficial y no hubo match -> sin DANE,
        // sin cotización, flete $0 y sin guía. El catálogo guarda sólo "TOLU".
        $cat = $this->catalog_sucre();
        $this->assertSame( '70820000', CCMCK_Cotizar::resolve_dane( $cat, '', 'Santiago de Tolú', 'SUCRE' ) );
        $this->assertSame( '70820000', CCMCK_Cotizar::resolve_dane( $cat, '', 'santiago de tolu', 'SUCRE' ) );
        $this->assertSame( '70820000', CCMCK_Cotizar::resolve_dane( $cat, '', 'Tolú', 'SUCRE' ) );
    }

    public function test_resolve_dane_no_confunde_tolu_con_tolu_viejo(): void {
        // Municipios distintos: jamás comparar por prefijo ("TOLU VIEJO" empieza por "TOLU").
        $cat = $this->catalog_sucre();
        $this->assertSame( '70823000', CCMCK_Cotizar::resolve_dane( $cat, '', 'Tolú Viejo', 'SUCRE' ) );
        $this->assertSame( '70823000', CCMCK_Cotizar::resolve_dane( $cat, '', 'TOLU VIEJO', 'SUCRE' ) );
        // Sin equivalente en el catálogo: preferimos '' a adivinar un destino.
        $this->assertSame( '', CCMCK_Cotizar::resolve_dane( $cat, '', 'Toluviejo', 'SUCRE' ) );
        // Un prefijo "SAN PEDRO DE" no debe canibalizar al municipio "SAN PEDRO".
        $this->assertSame( '70717000', CCMCK_Cotizar::resolve_dane( $cat, '', 'San Pedro', 'SUCRE' ) );
    }

    public function test_normalize_city_y_candidatos(): void {
        $this->assertSame( 'TOLU', CCMCK_Cotizar::normalize_city( 'Tolú' ) );
        $this->assertSame( 'TOLU', CCMCK_Cotizar::normalize_city( 'TOLU (SUCRE)' ) );
        $this->assertSame( 'BOGOTA D C', CCMCK_Cotizar::normalize_city( 'Bogotá D.C.' ) );
        $this->assertSame( array( 'SANTIAGO DE TOLU', 'TOLU' ), CCMCK_Cotizar::city_candidates( 'Santiago de Tolú' ) );
        $this->assertSame( array( 'TOLU VIEJO' ), CCMCK_Cotizar::city_candidates( 'Tolú Viejo' ) );
        $this->assertSame( array(), CCMCK_Cotizar::city_candidates( '  ' ) );
    }

    public function test_resolve_dane_name_without_state_fails(): void {
        // Sin depto no hay lookup por nombre (nombres se repiten entre deptos).
        $this->assertSame( '', CCMCK_Cotizar::resolve_dane( $this->catalog(), '', 'Barranquilla', '' ) );
    }

    public function test_resolve_dane_unknown(): void {
        $this->assertSame( '', CCMCK_Cotizar::resolve_dane( $this->catalog(), '', 'Narnia', 'Atlantico' ) );
        $this->assertSame( '', CCMCK_Cotizar::resolve_dane( $this->catalog(), 'abc', '', '' ) );
    }

    // --- build_result ---

    public function test_build_result_ok(): void {
        $r = CCMCK_Cotizar::build_result( array( 'ok' => true, 'flete_total' => 8040, 'dias' => 1, 'error' => '' ), '08001000' );
        $this->assertSame( array( 'ok' => true, 'flete' => 8040, 'dias' => 1, 'dane' => '08001000' ), $r );
    }

    public function test_build_result_error(): void {
        $r = CCMCK_Cotizar::build_result( array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => 'boom' ), '08001000' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'boom', $r['error'] );
    }

    // --- validate_products (datos ya extraídos de WC; puro) ---

    public function test_validate_products_all_ok(): void {
        $r = CCMCK_Cotizar::validate_products( array(
            array( 'qty' => 1, 'weight' => 20.0, 'largo' => 50.0, 'ancho' => 50.0, 'alto' => 50.0, 'cat_ids' => array(), 'sku' => 'A' ),
        ) );
        $this->assertSame( '', $r );
    }

    public function test_validate_products_missing_dims(): void {
        $r = CCMCK_Cotizar::validate_products( array(
            array( 'qty' => 1, 'weight' => 20.0, 'largo' => 50.0, 'ancho' => 50.0, 'alto' => 50.0, 'cat_ids' => array(), 'sku' => 'A' ),
            array( 'qty' => 1, 'weight' => 0.0, 'largo' => 10.0, 'ancho' => 10.0, 'alto' => 10.0, 'cat_ids' => array(), 'sku' => 'B' ),
            array( 'qty' => 1, 'weight' => 5.0, 'largo' => 0.0, 'ancho' => 10.0, 'alto' => 10.0, 'cat_ids' => array(), 'sku' => 'C' ),
        ) );
        $this->assertStringContainsString( 'B', $r );
        $this->assertStringContainsString( 'C', $r );
        $this->assertStringNotContainsString( 'A', $r );
    }
}
