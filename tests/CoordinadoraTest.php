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
}
