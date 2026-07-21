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
        // BLOQUEO HEREDADO DEL CÓDIGO POSTAL: un validador heredado server-side trata
        // billing_postcode como una "Cédula / NIT" OBLIGATORIA y le valida longitud,
        // bloqueando el checkout ("Por favor ingresa tu Cédula / NIT." / "...parece
        // demasiado corta."). En nuestro diseño el código postal es OPCIONAL y no se usa
        // para envío (Coordinadora cotiza por peso; pickup no requiere dirección). Por eso
        // ELIMINAMOS (no reescribimos) cualquier error de postcode de la validación.
        // WooCommerce corta el checkout por wc_notice_count('error'); vuelca $errors a
        // avisos justo después de woocommerce_after_checkout_validation. Prio PHP_INT_MAX
        // para correr tras el core y el snippet. Red de seguridad en checkout_process por
        // si el snippet usó wc_add_notice() directo (no $errors).
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'strip_legacy_postcode_errors' ), PHP_INT_MAX, 2 );
        add_action( 'woocommerce_checkout_process', array( __CLASS__, 'strip_legacy_postcode_notices' ), PHP_INT_MAX );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_meta' ), 10, 2 );
        // Backfill de documento para flujos de pasarela que se saltan los campos
        // del checkout (Sistecrédito, caso #33300): del POST al crear, y de las
        // metas del pedido al pasar a processing (prio 1: antes de guía/factura).
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'backfill_from_gateway_post' ), 20, 2 );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'backfill_on_processing' ), 1 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin' ) );
        add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'email_fields' ), 10, 3 );
    }

    /**
     * Tipos de documento ofrecidos en el checkout. Son los códigos DIAN; el
     * código numérico se guarda en `_billing_document_type` y la automatización
     * de facturación (n8n → Alegra) lo mapea al tipo de identificación de Alegra.
     * Mantener estos códigos sincronizados con el typeMap del workflow.
     */
    public static function document_types(): array {
        return array(
            '13' => 'CC',
            '22' => 'CE',
            '31' => 'NIT',
            '12' => 'TI',
            '21' => 'TE',
            '47' => 'PEP',
            '11' => 'RC',
            '91' => 'NUIP',
            '41' => 'PP',
            '42' => 'DIE',
            '50' => 'NIT de otro país',
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

        // Razón social (billing_company): se captura SOLO cuando el tipo de
        // documento es NIT (lo muestra/oculta ccmck-checkout.js; el "required"
        // condicional se valida en validate()). Su valor viaja como billing.company,
        // que la automatización de Alegra usa para crear el contacto como persona
        // jurídica. Se registra explícito porque el checkout no lo trae.
        $fields['billing']['billing_company'] = array(
            'type'         => 'text',
            'label'        => __( 'Razón social', 'ccm-checkout' ),
            'placeholder'  => __( 'Razón social', 'ccm-checkout' ),
            'required'     => false,
            'class'        => array( 'form-row-wide' ),
            'priority'     => 55,
            'autocomplete' => 'organization',
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
            'billing_company'    => array( 55,  'form-row-wide' ),
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
        // Razón social obligatoria SOLO para NIT (código 31): el contacto en Alegra
        // se crea como persona jurídica y necesita la razón social.
        if ( '31' === $type && '' === trim( (string) ( $data['billing_company'] ?? '' ) ) ) {
            $errors->add( 'billing_company_required', __( 'Ingresa la razón social (obligatoria para NIT).', 'ccm-checkout' ) );
        }
    }

    /**
     * ¿El mensaje pertenece al código postal secuestrado por el validador heredado?
     *
     * El validador trata billing_postcode como "Cédula / NIT" y emite mensajes como
     * "Por favor ingresa tu Cédula / NIT." o "La Cédula / NIT parece demasiado corta.".
     * El patrón "cédula + barra + NIT" es inequívoco del postcode secuestrado y NO casa
     * con el error de Addi ("número de cédula", sin barra ni NIT). También cubrimos el
     * mensaje de formato nativo de WC cuando trae un label de cédula suelto.
     *
     * @param string $msg
     * @return bool
     */
    private static function is_hijacked_postcode_message( string $msg ): bool {
        if ( (bool) preg_match( '/c[ée]dula\s*\/\s*nit/iu', $msg ) ) {
            return true;
        }
        return ( false !== mb_stripos( $msg, 'código postal' ) || false !== mb_stripos( $msg, 'postcode' ) )
            && (bool) preg_match( '/c[ée]dula|\bnit\b/iu', $msg );
    }

    /**
     * Elimina del WP_Error de validación los errores de billing_postcode (incluido el
     * bloqueo heredado que lo trata como cédula obligatoria), para que NO bloqueen el
     * checkout. En este diseño el código postal es opcional y no decide el envío.
     *
     * Flujo WC (WC_Checkout::process_checkout → validate_checkout): el core dispara
     * woocommerce_after_checkout_validation($data,$errors), luego vuelca $errors a
     * avisos con wc_add_notice() y corta si wc_notice_count('error') > 0. Al remover el
     * error aquí (prio PHP_INT_MAX, tras el core y el snippet) deja de bloquear. $errors
     * llega por referencia, así que mutarlo es efectivo. Se preservan los demás errores.
     *
     * @param array    $data   Datos posteados (sin usar).
     * @param WP_Error $errors Errores de validación acumulados (por referencia).
     */
    public static function strip_legacy_postcode_errors( $data, $errors ): void {
        unset( $data );
        if ( ! is_wp_error( $errors ) || empty( $errors->errors ) ) {
            return;
        }
        foreach ( $errors->errors as $code => $messages ) {
            // (a) Códigos propios del postcode (WC usa '{$key}' / '{$key}_validation').
            if ( false !== strpos( (string) $code, 'postcode' ) ) {
                $errors->remove( $code );
                continue;
            }
            // (b) Código genérico (p. ej. 'validation' o uno propio del snippet):
            //     quitamos solo los mensajes del postcode-cédula, preservando el resto.
            $changed = false;
            foreach ( (array) $messages as $i => $msg ) {
                if ( self::is_hijacked_postcode_message( (string) $msg ) ) {
                    unset( $errors->errors[ $code ][ $i ] );
                    $changed = true;
                }
            }
            if ( $changed ) {
                if ( empty( $errors->errors[ $code ] ) ) {
                    $errors->remove( $code );
                } else {
                    $errors->errors[ $code ] = array_values( $errors->errors[ $code ] );
                }
            }
        }
    }

    /**
     * Red de seguridad: si el validador heredado encoló el error con wc_add_notice()
     * directamente (en woocommerce_checkout_process, no vía $errors), strip_legacy_postcode_errors
     * no lo ve. Aquí filtramos la cola de avisos de error quitando los del postcode-cédula
     * ANTES de que process_checkout evalúe wc_notice_count('error'). Corre a PHP_INT_MAX
     * (tras el snippet); como WC vuelca $errors a avisos DESPUÉS de este hook, no choca
     * con strip_legacy_postcode_errors (cubos distintos).
     */
    public static function strip_legacy_postcode_notices(): void {
        if ( ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_set_notices' ) ) {
            return;
        }
        $notices = wc_get_notices();
        if ( empty( $notices['error'] ) ) {
            return;
        }
        foreach ( $notices['error'] as $i => $notice ) {
            // WC 3.9+: cada aviso es array ['notice'=>..., 'data'=>...]; antes era string.
            $text = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;
            if ( self::is_hijacked_postcode_message( $text ) ) {
                unset( $notices['error'][ $i ] );
            }
        }
        $notices['error'] = array_values( $notices['error'] );
        wc_set_notices( $notices );
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

    /*
     * ==== Backfill de documento desde pasarelas (caso #33300) ====
     *
     * Sistecrédito (gateway wcsistecredito) pinta SUS propios campos de
     * documento y guarda el valor en un transient GLOBAL (no por pedido); si el
     * flujo no postea billing_document_*, save_meta no guarda nada y la factura
     * de Alegra (n8n) queda sin cédula. Dos redes de seguridad:
     *   1) Al crear el pedido: copiar del POST los campos del gateway.
     *   2) Al pasar a processing (antes de guía/factura): buscar la cédula en
     *      las metas ya guardadas del pedido (Addi guarda _billing_cedula /
     *      billing_id como campos de checkout).
     * NUNCA leer el transient global de Sistecrédito: sin order_id, dos clientes
     * concurrentes se cruzarían la cédula.
     */

    /**
     * Mapea el tipo del gateway ('CC', 'CE', 'NIT'…) al código DIAN de
     * document_types(). Un código numérico válido pasa tal cual; desconocido o
     * vacío cae a '13' (CC, el caso real de estas pasarelas). PURO.
     */
    public static function type_code_from_gateway( string $type ): string {
        $type = strtoupper( trim( $type ) );
        if ( self::is_valid_type( $type ) ) {
            return $type;
        }
        $code = array_search( $type, self::document_types(), true );
        return false !== $code ? (string) $code : '13';
    }

    /**
     * Extrae número y tipo de documento de los campos propios de Sistecrédito
     * en el POST del checkout. number '' si no vienen. PURO.
     *
     * @param array $post $_POST ya unslashed.
     * @return array{number:string, type:string}
     */
    public static function doc_from_post( array $post ): array {
        $number = self::normalize_number( (string) ( $post['wcsistecredito-document-id'] ?? '' ) );
        $type   = self::type_code_from_gateway( (string) ( $post['wcsistecredito-document-type'] ?? '' ) );
        return array( 'number' => $number, 'type' => $type );
    }

    /**
     * Busca un número de documento entre las metas del pedido. Candidatas: keys
     * con cedula|document|dni|identificac o billing_id; se excluyen las de
     * tipo/etiqueta. Válido = normalizado con al menos 5 dígitos. PURO.
     *
     * @param array<string,mixed> $meta key => value plano.
     * @return array{number:string, source:string}
     */
    public static function find_document_in_meta( array $meta ): array {
        foreach ( $meta as $key => $value ) {
            $k = strtolower( (string) $key );
            if ( preg_match( '/type|label|tipo/', $k ) ) {
                continue;
            }
            if ( ! preg_match( '/cedula|document|dni|identificac/', $k ) && ! preg_match( '/^_?billing_id$/', $k ) ) {
                continue;
            }
            if ( ! is_scalar( $value ) ) {
                continue;
            }
            $number = self::normalize_number( (string) $value );
            if ( strlen( preg_replace( '/\D/', '', $number ) ) >= 5 ) {
                return array( 'number' => $number, 'source' => (string) $key );
            }
        }
        return array( 'number' => '', 'source' => '' );
    }

    /** Escribe las metas de documento (formato del checkout + espejo sin guion para n8n). */
    private static function write_document_meta( $order, string $number, string $type ): void {
        $order->update_meta_data( self::TYPE_META, $type );
        $order->update_meta_data( self::LABEL_META, self::label_for( $type ) );
        $order->update_meta_data( self::NUMBER_META, $number );
        // Espejo sin guion: es lo que leen los workflows de factura (mismo patrón
        // que los pedidos del botón Venta de Chatwoot).
        $order->update_meta_data( self::TYPE_KEY, $type );
        $order->update_meta_data( self::NUMBER_KEY, $number );
    }

    /**
     * Red 1 — woocommerce_checkout_create_order prio 20 (tras save_meta): si el
     * pedido quedó sin documento pero el POST trae los campos del gateway de
     * Sistecrédito, copiarlos.
     */
    public static function backfill_from_gateway_post( $order, array $data ): void {
        if ( '' !== (string) $order->get_meta( self::NUMBER_META ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- mismo POST del checkout ya verificado por WC.
        $doc = self::doc_from_post( wp_unslash( $_POST ) );
        if ( '' === $doc['number'] ) {
            return;
        }
        self::write_document_meta( $order, $doc['number'], $doc['type'] );
    }

    /**
     * Red 2 — woocommerce_order_status_processing prio 1 (antes de la guía prio
     * 10 y del webhook de factura): si el pedido no tiene documento, buscarlo en
     * sus propias metas (Addi u otra pasarela pudo guardarlo bajo otra key).
     */
    public static function backfill_on_processing( $order_id ): void {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        if ( ! $order || '' !== (string) $order->get_meta( self::NUMBER_META ) ) {
            return;
        }
        $meta = array();
        foreach ( $order->get_meta_data() as $m ) {
            $d = $m->get_data();
            $meta[ (string) $d['key'] ] = $d['value'];
        }
        $found = self::find_document_in_meta( $meta );
        if ( '' === $found['number'] ) {
            return;
        }
        self::write_document_meta( $order, $found['number'], '13' );
        $order->save();
        $order->add_order_note( 'Documento tomado de la meta "' . $found['source'] . '" de la pasarela (backfill; verificar tipo).' );
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
