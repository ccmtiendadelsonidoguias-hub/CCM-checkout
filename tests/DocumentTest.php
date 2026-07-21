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
        $this->assertSame( 'CE', CCMCK_Document::label_for( '22' ) );
    }

    public function test_all_dian_types_offered(): void {
        // Los 11 códigos DIAN están disponibles (incluidos PP/41, DIE/42 y
        // NIT de otro país/50, re-integrados para Alegra).
        $expected = array( '11', '12', '13', '21', '22', '31', '41', '42', '47', '50', '91' );
        foreach ( $expected as $code ) {
            $this->assertTrue( CCMCK_Document::is_valid_type( $code ), "El código $code debe ofrecerse." );
        }
        $this->assertCount( 11, CCMCK_Document::document_types() );
        $this->assertSame( 'NIT de otro país', CCMCK_Document::label_for( '50' ) );
    }

    public function test_validate_requires_company_for_nit(): void {
        $errors = new WP_Error();
        CCMCK_Document::validate(
            array( 'billing_document_type' => '31', 'billing_document_number' => '900123456', 'billing_company' => '' ),
            $errors
        );
        $this->assertContains( 'Ingresa la razón social (obligatoria para NIT).', $errors->get_error_messages() );
    }

    public function test_validate_company_present_for_nit_passes(): void {
        $errors = new WP_Error();
        CCMCK_Document::validate(
            array( 'billing_document_type' => '31', 'billing_document_number' => '900123456', 'billing_company' => 'ACME SAS' ),
            $errors
        );
        $this->assertNotContains( 'Ingresa la razón social (obligatoria para NIT).', $errors->get_error_messages() );
    }

    public function test_validate_company_not_required_for_non_nit(): void {
        $errors = new WP_Error();
        CCMCK_Document::validate(
            array( 'billing_document_type' => '13', 'billing_document_number' => '1020304050', 'billing_company' => '' ),
            $errors
        );
        $this->assertNotContains( 'Ingresa la razón social (obligatoria para NIT).', $errors->get_error_messages() );
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

    public function test_fix_default_postcode_field_overrides_inherited_cedula_label(): void {
        // Simula el default heredado: postcode rotulado "Cédula / NIT" y obligatorio.
        $fields = CCMCK_Document::fix_default_postcode_field( array(
            'postcode' => array( 'label' => 'Cédula / NIT', 'required' => true, 'placeholder' => 'Ej: 123456789-1' ),
            'city'     => array( 'label' => 'Ciudad', 'required' => true ),
        ) );
        $this->assertSame( 'Código postal', $fields['postcode']['label'] );
        $this->assertFalse( $fields['postcode']['required'] );
        // No toca otros campos.
        $this->assertSame( 'Ciudad', $fields['city']['label'] );
        $this->assertTrue( $fields['city']['required'] );
    }

    public function test_fix_default_postcode_field_noop_without_postcode(): void {
        $fields = CCMCK_Document::fix_default_postcode_field( array(
            'city' => array( 'label' => 'Ciudad', 'required' => true ),
        ) );
        $this->assertArrayNotHasKey( 'postcode', $fields );
    }

    public function test_mirror_document_to_billing_id_copies_number(): void {
        // Addi lee la cédula de billing_id; finalize_fields lo quitó del form.
        $data = CCMCK_Document::mirror_document_to_billing_id( array(
            'billing_document_number' => '1098765432',
        ) );
        $this->assertSame( '1098765432', $data['billing_id'] );
    }

    public function test_mirror_document_to_billing_id_preserves_existing(): void {
        $data = CCMCK_Document::mirror_document_to_billing_id( array(
            'billing_id'              => '900123456',
            'billing_document_number' => '1098765432',
        ) );
        $this->assertSame( '900123456', $data['billing_id'] );
    }

    public function test_mirror_document_to_billing_id_noop_without_number(): void {
        $data = CCMCK_Document::mirror_document_to_billing_id( array( 'billing_email' => 'a@b.co' ) );
        $this->assertArrayNotHasKey( 'billing_id', $data );
    }

    public function test_strip_legacy_postcode_errors_removes_required_cedula_block(): void {
        // El validador heredado encola el postcode-cédula bajo un código genérico.
        $errors = new WP_Error();
        $errors->add( 'validation', 'Por favor ingresa tu Cédula / NIT.' );
        $errors->add( 'validation', 'El correo electrónico no es válido.' );
        CCMCK_Document::strip_legacy_postcode_errors( array(), $errors );
        $msgs = $errors->get_error_messages();
        // Se eliminó el del postcode-cédula y se conservó el legítimo.
        $this->assertNotContains( 'Por favor ingresa tu Cédula / NIT.', $msgs );
        $this->assertContains( 'El correo electrónico no es válido.', $msgs );
    }

    public function test_strip_legacy_postcode_errors_removes_too_short_message(): void {
        $errors = new WP_Error();
        $errors->add( 'validation', 'La Cédula / NIT parece demasiado corta.' );
        CCMCK_Document::strip_legacy_postcode_errors( array(), $errors );
        $this->assertEmpty( $errors->get_error_messages() );
    }

    public function test_strip_legacy_postcode_errors_removes_postcode_coded_error(): void {
        // Error con código propio de WC para el postcode.
        $errors = new WP_Error();
        $errors->add( 'billing_postcode_validation', 'Cualquier mensaje de postcode.' );
        CCMCK_Document::strip_legacy_postcode_errors( array(), $errors );
        $this->assertEmpty( $errors->get_error_messages() );
    }

    public function test_strip_legacy_postcode_errors_keeps_addi_cedula_error(): void {
        // El error de Addi ("número de cédula", sin barra ni NIT) NO debe eliminarse.
        $errors = new WP_Error();
        $errors->add( 'validation', 'Por favor ingrese su número de cédula para continuar.' );
        CCMCK_Document::strip_legacy_postcode_errors( array(), $errors );
        $this->assertContains( 'Por favor ingrese su número de cédula para continuar.', $errors->get_error_messages() );
    }

    public function test_strip_legacy_postcode_errors_keeps_document_errors(): void {
        // Nuestros propios errores de documento deben sobrevivir.
        $errors = new WP_Error();
        $errors->add( 'billing_document_number_required', 'Ingresa tu número de documento.' );
        CCMCK_Document::strip_legacy_postcode_errors( array(), $errors );
        $this->assertContains( 'Ingresa tu número de documento.', $errors->get_error_messages() );
    }

    // --- backfill de documento desde pasarelas (caso #33300 Sistecrédito) ---

    public function test_type_code_from_gateway_maps_labels(): void {
        $this->assertSame( '13', CCMCK_Document::type_code_from_gateway( 'CC' ) );
        $this->assertSame( '22', CCMCK_Document::type_code_from_gateway( 'ce' ) );
        $this->assertSame( '31', CCMCK_Document::type_code_from_gateway( 'NIT' ) );
        $this->assertSame( '41', CCMCK_Document::type_code_from_gateway( 'PP' ) );
    }

    public function test_type_code_from_gateway_accepts_numeric_and_defaults_cc(): void {
        $this->assertSame( '22', CCMCK_Document::type_code_from_gateway( '22' ) );
        $this->assertSame( '13', CCMCK_Document::type_code_from_gateway( '' ) );
        $this->assertSame( '13', CCMCK_Document::type_code_from_gateway( 'XX' ) );
    }

    public function test_doc_from_post_extracts_sistecredito_fields(): void {
        $r = CCMCK_Document::doc_from_post( array(
            'wcsistecredito-document-type' => 'CC',
            'wcsistecredito-document-id'   => ' 1.043.123-456 ',
        ) );
        $this->assertSame( '1043123456', $r['number'] );
        $this->assertSame( '13', $r['type'] );
    }

    public function test_doc_from_post_empty_without_gateway_fields(): void {
        $r = CCMCK_Document::doc_from_post( array( 'billing_email' => 'x@y.z' ) );
        $this->assertSame( '', $r['number'] );
    }

    public function test_find_document_in_meta_candidates(): void {
        // Addi guarda la cédula como campo billing (WC la persiste como meta).
        $r = CCMCK_Document::find_document_in_meta( array( '_billing_cedula' => '1043123456', '_otro' => 'x' ) );
        $this->assertSame( '1043123456', $r['number'] );
        $this->assertSame( '_billing_cedula', $r['source'] );

        $r2 = CCMCK_Document::find_document_in_meta( array( 'billing_id' => '79456123' ) );
        $this->assertSame( '79456123', $r2['number'] );

        $r3 = CCMCK_Document::find_document_in_meta( array( '_customer_document' => '900123456' ) );
        $this->assertSame( '900123456', $r3['number'] );
    }

    public function test_find_document_in_meta_ignores_type_label_and_short_values(): void {
        // Keys de tipo/etiqueta no son números de documento; valores cortos tampoco.
        $r = CCMCK_Document::find_document_in_meta( array(
            '_billing_document_type'       => '13',
            '_billing_document_type_label' => 'CC',
            'documento_tipo'               => 'CC',
            '_billing_cedula'              => '123',
        ) );
        $this->assertSame( '', $r['number'] );
    }

    public function test_find_document_in_meta_empty(): void {
        $this->assertSame( '', CCMCK_Document::find_document_in_meta( array() )['number'] );
        $this->assertSame( '', CCMCK_Document::find_document_in_meta( array( '_ccm_origen' => 'chatwoot_venta' ) )['number'] );
    }
}
