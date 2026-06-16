<?php
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase {
    public function test_valid_type_accepts_known_dian_code(): void {
        $this->assertTrue( CCMCK_Document::is_valid_type( '13' ) ); // CC
        $this->assertTrue( CCMCK_Document::is_valid_type( '31' ) ); // NIT
    }

    public function test_valid_type_rejects_unknown_code(): void {
        $this->assertFalse( CCMCK_Document::is_valid_type( '99' ) );
        $this->assertFalse( CCMCK_Document::is_valid_type( '' ) );
    }

    public function test_label_for_known_code(): void {
        $this->assertSame( 'CC', CCMCK_Document::label_for( '13' ) );
        $this->assertSame( '', CCMCK_Document::label_for( '99' ) );
        $this->assertSame( 'NIT de otro país', CCMCK_Document::label_for( '50' ) );
    }

    public function test_normalize_number_strips_non_alphanumerics(): void {
        $this->assertSame( '1098765432', CCMCK_Document::normalize_number( ' 1.098.765.432 ' ) );
        $this->assertSame( '9001234567', CCMCK_Document::normalize_number( '900.123.456-7' ) );
        $this->assertSame( '', CCMCK_Document::normalize_number( '...' ) );
        $this->assertSame( '', CCMCK_Document::normalize_number( '' ) );
    }

    public function test_force_postcode_label_renames_and_makes_optional(): void {
        // Simula el relabeler heredado que deja "Cédula / NIT" obligatorio.
        $args = CCMCK_Document::force_postcode_label(
            array( 'label' => 'Cédula / NIT', 'required' => true ),
            'billing_postcode'
        );
        $this->assertSame( 'Código postal', $args['label'] );
        $this->assertFalse( $args['required'] );
    }

    public function test_force_postcode_label_leaves_other_fields_untouched(): void {
        $args = CCMCK_Document::force_postcode_label(
            array( 'label' => 'Teléfono', 'required' => true ),
            'billing_phone'
        );
        $this->assertSame( 'Teléfono', $args['label'] );
        $this->assertTrue( $args['required'] );
    }
}
