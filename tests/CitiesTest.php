<?php
use PHPUnit\Framework\TestCase;

final class CitiesTest extends TestCase {

    private function catalog(): array {
        return array(
            'Atlantico'    => array( '08001000' => 'BARRANQUILLA (ATL)', '08758000' => 'SOLEDAD (ATL)' ),
            'Cundinamarca' => array( '11001000' => 'BOGOTA D.C.' ),
        );
    }

    public function test_valid_destination(): void {
        $this->assertSame( '', CCMCK_Cities::validate_destination( $this->catalog(), 'Atlantico', '08001000' ) );
    }

    public function test_state_case_insensitive(): void {
        $this->assertSame( '', CCMCK_Cities::validate_destination( $this->catalog(), 'ATLANTICO', '08758000' ) );
    }

    public function test_empty_state_fails(): void {
        $this->assertSame( 'state', CCMCK_Cities::validate_destination( $this->catalog(), '', '08001000' ) );
    }

    public function test_unknown_state_fails(): void {
        $this->assertSame( 'state', CCMCK_Cities::validate_destination( $this->catalog(), 'Narnia', '08001000' ) );
    }

    public function test_empty_city_fails(): void {
        $this->assertSame( 'city', CCMCK_Cities::validate_destination( $this->catalog(), 'Atlantico', '' ) );
    }

    public function test_city_not_in_state_fails(): void {
        // Caso William: "Bogotá" escrita a mano (no código DANE del dropdown).
        $this->assertSame( 'city', CCMCK_Cities::validate_destination( $this->catalog(), 'Cundinamarca', 'Bogotá' ) );
    }

    public function test_city_code_from_other_state_fails(): void {
        $this->assertSame( 'city', CCMCK_Cities::validate_destination( $this->catalog(), 'Atlantico', '11001000' ) );
    }

    public function test_posted_is_pickup(): void {
        $this->assertTrue( CCMCK_Cities::posted_is_pickup( array( 'ccmck_local_pickup' ) ) );
        $this->assertTrue( CCMCK_Cities::posted_is_pickup( 'ccmck_local_pickup' ) );
        $this->assertFalse( CCMCK_Cities::posted_is_pickup( array( 'flat_rate:1' ) ) );
        $this->assertFalse( CCMCK_Cities::posted_is_pickup( null ) );
    }
}
