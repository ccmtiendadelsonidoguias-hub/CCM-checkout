<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Document {
    public const TYPE_KEY   = 'billing_document_type';
    public const NUMBER_KEY = 'billing_document_number';

    private const TYPE_META   = '_billing_document_type';
    private const LABEL_META  = '_billing_document_type_label';
    private const NUMBER_META = '_billing_document_number';

    public static function init(): void {
        // Prioridad alta (100) para ganarle a cualquier filtro previo que reetiquete
        // billing_postcode (p. ej. una config heredada de CheckoutWC o un snippet).
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'register_fields' ), 100 );
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_meta' ), 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin' ) );
        add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'email_fields' ), 10, 3 );
    }

    public static function document_types(): array {
        return array(
            '31' => 'NIT',
            '13' => 'CC',
            '50' => 'NIT de otro país',
            '41' => 'PP',
            '47' => 'PEP',
            '42' => 'DIE',
            '22' => 'CE',
            '21' => 'TE',
            '12' => 'TI',
            '11' => 'RC',
            '91' => 'NUIP',
        );
    }

    public static function is_valid_type( string $code ): bool {
        return array_key_exists( $code, self::document_types() );
    }

    public static function label_for( string $code ): string {
        return self::document_types()[ $code ] ?? '';
    }

    public static function normalize_number( string $raw ): string {
        return preg_replace( '/[^A-Za-z0-9]/', '', $raw );
    }

    public static function register_fields( array $fields ): array {
        if ( empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            return $fields;
        }

        $options = array( '' => __( 'Tipo de documento', 'ccm-checkout' ) );
        foreach ( self::document_types() as $code => $label ) {
            $options[ $code ] = $label;
        }

        $fields['billing'][ self::TYPE_KEY ] = array(
            'type'     => 'select',
            'label'    => __( 'Tipo de documento', 'ccm-checkout' ),
            'required' => true,
            'class'    => array( 'form-row-first' ),
            'priority' => 35,
            'options'  => $options,
            'default'  => '',
        );

        $fields['billing'][ self::NUMBER_KEY ] = array(
            'type'         => 'text',
            'label'        => __( 'Número de documento', 'ccm-checkout' ),
            'required'     => true,
            'class'        => array( 'form-row-last' ),
            'priority'     => 36,
            'autocomplete' => 'off',
        );

        // El código postal había sido secuestrado/reetiquetado ("Cédula") por la
        // config previa. Lo devolvemos a su rol propio: opcional, en su fila, después
        // de los campos de documento. Así desaparece el tercer campo confuso.
        if ( isset( $fields['billing']['billing_postcode'] ) && is_array( $fields['billing']['billing_postcode'] ) ) {
            $fields['billing']['billing_postcode']['label']    = __( 'Código postal (opcional)', 'ccm-checkout' );
            $fields['billing']['billing_postcode']['required'] = false;
            $fields['billing']['billing_postcode']['priority'] = 90;
            $fields['billing']['billing_postcode']['class']    = array( 'form-row-wide' );
        }

        return $fields;
    }

    public static function validate( array $data, $errors ): void {
        $type   = sanitize_text_field( (string) ( $data[ self::TYPE_KEY ] ?? '' ) );
        $number = self::normalize_number( (string) ( $data[ self::NUMBER_KEY ] ?? '' ) );

        if ( ! self::is_valid_type( $type ) ) {
            $errors->add( 'billing_document_type_required', __( 'Selecciona un tipo de documento válido.', 'ccm-checkout' ) );
        }
        if ( '' === $number ) {
            $errors->add( 'billing_document_number_required', __( 'Ingresa tu número de documento.', 'ccm-checkout' ) );
        }
    }

    public static function save_meta( $order, array $data ): void {
        $type   = sanitize_text_field( (string) ( $data[ self::TYPE_KEY ] ?? '' ) );
        $number = self::normalize_number( (string) ( $data[ self::NUMBER_KEY ] ?? '' ) );
        if ( ! self::is_valid_type( $type ) ) {
            return;
        }
        $order->update_meta_data( self::TYPE_META, $type );
        $order->update_meta_data( self::LABEL_META, self::label_for( $type ) );
        $order->update_meta_data( self::NUMBER_META, $number );
    }

    public static function render_admin( $order ): void {
        $label  = (string) $order->get_meta( self::LABEL_META, true );
        $number = (string) $order->get_meta( self::NUMBER_META, true );
        if ( '' === $label && '' === $number ) {
            return;
        }
        echo '<p><strong>' . esc_html__( 'Documento', 'ccm-checkout' ) . ':</strong><br>'
            . esc_html( trim( $label . ' ' . $number ) ) . '</p>';
    }

    public static function email_fields( array $fields, $sent_to_admin, $order ): array {
        $label  = (string) $order->get_meta( self::LABEL_META, true );
        $number = (string) $order->get_meta( self::NUMBER_META, true );
        if ( '' !== $label || '' !== $number ) {
            $fields['billing_document'] = array(
                'label' => __( 'Documento', 'ccm-checkout' ),
                'value' => sanitize_text_field( trim( $label . ' ' . $number ) ),
            );
        }
        return $fields;
    }
}
