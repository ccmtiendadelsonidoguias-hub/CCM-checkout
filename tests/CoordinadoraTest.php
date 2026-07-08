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
}
