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
        // Limpieza final de campos: quita la "Cédula" duplicada, restaura el código postal
        // y pone placeholders. Prioridad tardía (9999) para correr después de cualquier
        // filtro que registre/reetiquete campos (plugin viejo, CheckoutWC, etc.).
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'finalize_fields' ), 9999 );
        // Blindaje en tiempo de render: un relabeler heredado (config de CO / snippet)
        // reetiqueta billing_postcode como "Cédula / NIT" y le gana a finalize_fields
        // (prio 9999 sobre woocommerce_checkout_fields). Este filtro corre al PINTAR
        // cada campo —después de toda la cadena de checkout_fields—, así que gana
        // siempre. Sin él, el campo aparece como una "Cédula" duplicada.
        add_filter( 'woocommerce_form_field_args', array( __CLASS__, 'force_postcode_label' ), PHP_INT_MAX, 2 );
        // CAUSA RAÍZ del rótulo "Cédula / NIT": un snippet heredado puso ese label en el
        // locale DEFAULT de WooCommerce (woocommerce_default_address_fields → postcode).
        // El locale CO solo sobrescribe required, no el label, así que el JS de WooCommerce
        // `address-i18n.js` cae al label del default y reescribe el campo en cliente
        // (tras el render y tras nuestro floating-label, en el evento country_to_state).
        // Corregir el default arregla la fuente: server + los params JS que lee address-i18n.
        add_filter( 'woocommerce_default_address_fields', array( __CLASS__, 'fix_default_postcode_field' ), PHP_INT_MAX );
        // Addi (buy-now-pay-later-addi) lee la cédula del campo billing_id, que
        // finalize_fields ELIMINA del formulario (era la "Cédula" duplicada). Sin él,
        // la validación de Addi falla: "ingrese su número de cédula... en caso de no
        // ver el campo, comuníquese con el administrador". Reflejamos el número de
        // documento en billing_id (en los datos posteados y en $_POST) para que Addi
        // lo encuentre, sin volver a mostrar el campo. La prioridad 1 en
        // checkout_process garantiza correr ANTES de la validación de Addi.
        add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'mirror_document_to_billing_id' ) );
        add_action( 'woocommerce_checkout_process', array( __CLASS__, 'mirror_document_to_post' ), 1 );
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
        // El mensaje de validación del código postal sale con el label heredado
        // "Cédula / NIT" (WooCommerce arma el texto con el label que está en
        // checkout_fields, donde el relabeler heredado gana incluso a prio 9999;
        // force_postcode_label solo corrige el RENDER, no este mensaje). Lo
        // reescribimos a un texto limpio. Prio 20 → corre tras la validación core.
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'fix_postcode_error_message' ), 20, 2 );
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

    /**
     * Limpieza final de los campos de facturación:
     *  1. Elimina el campo "Cédula" (billing_id) residual de una config previa
     *     (CheckoutWC/DB). El documento ya se captura con Tipo + Número de documento;
     *     ningún gateway lee billing_id (verificado), así que es seguro quitarlo.
     *  2. Restaura billing_postcode a su rol original (un plugin viejo lo reetiquetaba
     *     como "Cédula / NIT"): código postal opcional, fuera del bloque de documento.
     *  3. Garantiza placeholder en cada campo de texto que no lo trae (diseño
     *     solo-placeholder: los labels se ocultan por CSS).
     *
     * @param array $fields Campos del checkout de WooCommerce.
     * @return array
     */
    public static function finalize_fields( array $fields ): array {
        if ( empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            return $fields;
        }

        // (1) Cédula duplicada.
        unset( $fields['billing']['billing_id'] );

        // (2) Código postal original (opcional) — el plugin viejo lo reetiquetaba "Cédula/NIT".
        if ( isset( $fields['billing']['billing_postcode'] ) && is_array( $fields['billing']['billing_postcode'] ) ) {
            $fields['billing']['billing_postcode']['label']       = __( 'Código postal (opcional)', 'ccm-checkout' );
            $fields['billing']['billing_postcode']['placeholder'] = __( 'Código postal', 'ccm-checkout' );
            $fields['billing']['billing_postcode']['required']    = false;
        }

        // (3) Orden y columnas según el mockup (checkout-ccm.html): email arriba, país,
        //     nombre|apellidos, tipo|número de documento, dirección, casa/apto,
        //     departamento|ciudad (depto primero por la cascada de ciudades), c.postal|teléfono.
        //     Se preservan las clases funcionales (validate-required, address-field, etc.);
        //     solo se intercambia la clase de columna (form-row-first/last/wide).
        $layout = array(
            'billing_email'      => array( 5,   'form-row-wide' ),
            'billing_country'    => array( 10,  'form-row-wide' ),
            'billing_first_name' => array( 20,  'form-row-first' ),
            'billing_last_name'  => array( 30,  'form-row-last' ),
            self::TYPE_KEY       => array( 40,  'form-row-first' ),
            self::NUMBER_KEY     => array( 50,  'form-row-last' ),
            'billing_address_1'  => array( 60,  'form-row-wide' ),
            'billing_address_2'  => array( 70,  'form-row-wide' ),
            'billing_state'      => array( 80,  'form-row-first' ),
            'billing_city'       => array( 90,  'form-row-last' ),
            'billing_postcode'   => array( 100, 'form-row-first' ),
            'billing_phone'      => array( 110, 'form-row-last' ),
        );
        $row_classes = array( 'form-row-first', 'form-row-last', 'form-row-wide' );
        foreach ( $layout as $key => $cfg ) {
            if ( ! isset( $fields['billing'][ $key ] ) || ! is_array( $fields['billing'][ $key ] ) ) {
                continue;
            }
            $fields['billing'][ $key ]['priority'] = $cfg[0];
            $classes = isset( $fields['billing'][ $key ]['class'] ) ? (array) $fields['billing'][ $key ]['class'] : array();
            $classes = array_values( array_diff( $classes, $row_classes ) );
            $classes[] = $cfg[1];
            $fields['billing'][ $key ]['class'] = $classes;
        }

        // (4) Placeholders faltantes.
        $placeholders = array(
            self::NUMBER_KEY => __( 'Número de documento', 'ccm-checkout' ),
            'billing_phone'  => __( 'Teléfono', 'ccm-checkout' ),
            'billing_email'  => __( 'Correo electrónico', 'ccm-checkout' ),
        );
        foreach ( $placeholders as $key => $placeholder ) {
            if ( isset( $fields['billing'][ $key ] ) && is_array( $fields['billing'][ $key ] )
                && empty( $fields['billing'][ $key ]['placeholder'] ) ) {
                $fields['billing'][ $key ]['placeholder'] = $placeholder;
            }
        }

        return $fields;
    }

    /**
     * Fuerza el rótulo correcto de billing_postcode en tiempo de render.
     *
     * El documento ya se captura con Tipo + Número de documento; un relabeler
     * heredado deja billing_postcode como "Cédula / NIT", creando un campo de
     * cédula DUPLICADO. Aquí (filtro de WooCommerce aplicado dentro de
     * woocommerce_form_field(), después de toda la cadena de campos) devolvemos
     * el campo a su rol real: código postal opcional. Se pasa el label SIN el
     * sufijo "(opcional)" porque WooCommerce lo añade solo cuando required=false.
     *
     * @param array  $args Argumentos del campo para woocommerce_form_field().
     * @param string $key  Clave del campo que se está pintando.
     * @return array
     */
    public static function force_postcode_label( array $args, string $key ): array {
        if ( 'billing_postcode' === $key ) {
            $args['label']    = __( 'Código postal', 'ccm-checkout' );
            $args['required'] = false;
        }
        return $args;
    }

    /**
     * Corrige el campo postcode en el locale DEFAULT de direcciones.
     *
     * Un snippet heredado dejó `postcode.label = "Cédula / NIT"` (y required=true)
     * en el default global de WooCommerce. Ese default alimenta los params JS
     * (`wc_address_i18n_params.locale.default`) que usa `address-i18n.js`, el cual
     * reescribe el rótulo en cliente cuando el locale del país (CO) no define un
     * label propio para postcode. Devolvemos el campo a "Código postal" opcional,
     * cortando el problema en la fuente (server + JS). Las claves aquí van SIN el
     * prefijo `billing_`/`shipping_`.
     *
     * @param array $fields Campos de dirección por defecto de WooCommerce.
     * @return array
     */
    public static function fix_default_postcode_field( array $fields ): array {
        if ( isset( $fields['postcode'] ) && is_array( $fields['postcode'] ) ) {
            $fields['postcode']['label']       = __( 'Código postal', 'ccm-checkout' );
            $fields['postcode']['placeholder'] = __( 'Código postal', 'ccm-checkout' );
            $fields['postcode']['required']    = false;
        }
        return $fields;
    }

    /**
     * Refleja el número de documento en billing_id dentro de los datos posteados.
     * Addi valida la cédula leyendo billing_id (campo que finalize_fields quita
     * del formulario). NO sobrescribe un billing_id ya presente. PURO.
     *
     * @param array $data Datos posteados del checkout (woocommerce_checkout_posted_data).
     * @return array
     */
    public static function mirror_document_to_billing_id( array $data ): array {
        if ( empty( $data['billing_id'] ) && ! empty( $data[ self::NUMBER_KEY ] ) ) {
            $data['billing_id'] = $data[ self::NUMBER_KEY ];
        }
        return $data;
    }

    /**
     * Misma reflexión sobre $_POST, por si la validación de Addi lee $_POST
     * directo en vez de los datos parseados. Corre temprano (prio 1) en
     * woocommerce_checkout_process.
     */
    public static function mirror_document_to_post(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( empty( $_POST['billing_id'] ) && ! empty( $_POST[ self::NUMBER_KEY ] ) ) {
            $_POST['billing_id'] = sanitize_text_field( wp_unslash( $_POST[ self::NUMBER_KEY ] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
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

    /**
     * Reescribe el mensaje de validación del código postal cuando WooCommerce lo
     * arma con el label heredado "Cédula / NIT".
     *
     * El error de formato del postcode ("%s no es un código postal válido.") usa
     * como %s el label del campo en checkout_fields, que un relabeler heredado deja
     * como "Cédula / NIT" → el cliente ve un mensaje pidiendo la cédula. Detectamos
     * los mensajes de postcode que aún contienen ese rótulo y los sustituimos por un
     * texto claro. Mutamos WP_Error::$errors directamente (propiedad pública), que es
     * lo que WooCommerce convierte luego en avisos.
     *
     * @param array     $data   Datos posteados (sin usar).
     * @param WP_Error  $errors Errores de validación acumulados.
     */
    public static function fix_postcode_error_message( $data, $errors ): void {
        unset( $data );
        if ( ! is_wp_error( $errors ) || empty( $errors->errors ) ) {
            return;
        }
        foreach ( $errors->errors as $code => $messages ) {
            foreach ( (array) $messages as $i => $msg ) {
                // El validador heredado trata billing_postcode como cédula y emite
                // mensajes con el rótulo "Cédula / NIT" (p. ej. "La Cédula / NIT
                // parece demasiado corta." o "Cédula / NIT no es un código postal
                // válido."). Ese patrón —cédula + barra + NIT— es inequívoco del
                // postcode secuestrado y NO casa con el error de Addi ("número de
                // cédula"). Cualquier mensaje que lo contenga se reescribe limpio.
                $is_hijacked_postcode = (bool) preg_match( '/c[ée]dula\s*\/\s*nit/iu', $msg );
                // Respaldo: el mensaje de formato de WC con un label de cédula suelto.
                $is_wc_postcode_msg   = ( false !== mb_stripos( $msg, 'código postal' ) || false !== mb_stripos( $msg, 'postcode' ) )
                    && (bool) preg_match( '/c[ée]dula|\bnit\b/iu', $msg );
                if ( $is_hijacked_postcode || $is_wc_postcode_msg ) {
                    $errors->errors[ $code ][ $i ] = __( 'Ingresa un código postal válido.', 'ccm-checkout' );
                }
            }
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
