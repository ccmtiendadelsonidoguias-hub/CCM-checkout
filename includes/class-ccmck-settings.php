<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Settings {
    public const OPTION = 'ccmck_settings';

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
    }

    public static function defaults(): array {
        return array(
            'accent_color'      => '#e63946',
            'sidebar_color'     => '#1a1a1a',
            // Color del botón "Realizar el pedido". Vacío = hereda el de acento.
            'button_color'      => '',
            'logo_text_1'       => 'CCM',
            'logo_text_2'       => 'Tienda Del Sonido',
            'logo_image'        => '',
            'header_links'      => array(),
            'footer_links'      => array(),
            'whatsapp_enabled'  => true,
            'whatsapp_number'   => '573178119077',
            'whatsapp_title'    => '¿Necesitas ayuda con tu pedido?',
            'whatsapp_subtitle' => 'Escríbenos por WhatsApp',
            'faq_enabled'       => true,
            'faq_items'         => array(),
            'shipping_cards'    => array(),
            'secure_badge'      => 'Pago seguro con encriptación SSL',
            // Aviso que se muestra al elegir "Recogida local" (vacío = no se muestra).
            'pickup_notice'     => '📍 Recogida en tienda: al elegir esta opción tu pedido no se envía a domicilio. Deberás recogerlo personalmente en nuestro local en Barranquilla. Te escribiremos por WhatsApp cuando esté listo para recoger.',
            'tracker_enabled'   => true,
            'tracker_labels'    => array( 'Pedido recibido', 'Pago confirmado', 'En preparación', 'Enviado' ),
            'thankyou_message'  => 'Hemos recibido tu pedido y te enviaremos un correo de confirmación.',
            'newsletter_enabled'=> true,
            'newsletter_text'   => 'Enviarme novedades y ofertas por correo electrónico.',
            'payment_order'     => array(),
            'payment_hidden'    => array(),
            'payment_icons'     => array(),
            // Experimental: sube los métodos de pago al inicio de la columna del
            // checkout (el botón "Realizar el pedido" se mantiene al final).
            'checkout_payment_first' => false,
            // Recargo por financiación (%) aplicado al precio del producto cuando se
            // paga con Addi / Sistecrédito. 0 = sin recargo.
            'surcharge_rate'         => 10.48,
            // IDs de términos de marca (taxonomía product_brand) a los que aplica el
            // recargo. Vacío = aplica a TODOS los productos.
            'surcharge_brands'       => array(),
            // Coordinadora — cotización directa del flete (ver spec 2026-07-08).
            'coordinadora_enabled'          => false,
            // Kill switch del puente contra la cotizacion duplicada del plugin
            // oficial. Apagado por defecto: encenderlo es un acto deliberado, y
            // apagarlo devuelve el comportamiento anterior sin desplegar nada.
            'coordinadora_puente'           => false,
            'coordinadora_apikey'           => '',
            'coordinadora_clave'            => '',
            'coordinadora_nit'              => '901677789',
            'coordinadora_origin'           => '08001000',
            'coordinadora_weight_threshold' => 5.0,
            'coordinadora_box_rules'        => array(),
            // Generación de guías (ver spec 2026-07-15). Clave se guarda plana; se
            // hashea SHA-256 al llamar el WS.
            'guias_enabled'             => false,
            'guias_env'                 => 'sandbox',
            'guias_usuario'             => 'ccmtienda.ws',
            'guias_clave'               => '',
            'guias_id_cliente'          => 49444,
            'guias_remitente_nombre'    => 'CCM Tienda del Sonido',
            'guias_remitente_direccion' => '',
            'guias_remitente_telefono'  => '',
            'guias_webhook_url'         => '',
            // Rescate de recogidas: acuerdo de flete contra entrega (el cliente
            // paga el flete al recibir). Cuenta 6 = FCE, CONFIRMADA por
            // Coordinadora 2026-07-22 y verificada en el PDF (Tipo Flete FCE,
            // guía 33042500382). Ojo: 3 = "Flete Pago" (FP, lo paga CCM) — con
            // 3 el mensajero NO cobra.
            'guias_id_cliente_ce'       => 49445,
            'guias_cuenta_ce'           => 6,
            // Integración n8n pickup→envío: webhook que pregunta al cliente por
            // WhatsApp, y secreto del endpoint REST /ccmck/v1/generar-guia.
            'guias_pickup_ask_url'      => '',
            'guias_api_secret'          => '',
        );
    }

    public static function sanitize( array $input ): array {
        $d   = self::defaults();
        $out = array();

        $out['accent_color']  = sanitize_hex_color( $input['accent_color']  ?? '' ) ?? '';
        $out['sidebar_color'] = sanitize_hex_color( $input['sidebar_color'] ?? '' ) ?? '';
        $out['button_color']  = sanitize_hex_color( $input['button_color'] ?? '' ) ?? '';
        $out['logo_text_1']   = sanitize_text_field( $input['logo_text_1'] ?? $d['logo_text_1'] );
        $out['logo_text_2']   = sanitize_text_field( $input['logo_text_2'] ?? $d['logo_text_2'] );
        $out['logo_image']    = esc_url_raw( $input['logo_image'] ?? '' );

        $out['header_links']  = self::sanitize_links( $input['header_links'] ?? array() );
        $out['footer_links']  = self::sanitize_links( $input['footer_links'] ?? array() );

        $out['whatsapp_enabled']  = ! empty( $input['whatsapp_enabled'] );
        $out['whatsapp_number']   = preg_replace( '/[^0-9]/', '', (string) ( $input['whatsapp_number'] ?? '' ) );
        $out['whatsapp_title']    = sanitize_text_field( $input['whatsapp_title'] ?? '' );
        $out['whatsapp_subtitle'] = sanitize_text_field( $input['whatsapp_subtitle'] ?? '' );

        $out['faq_enabled'] = ! empty( $input['faq_enabled'] );
        $out['faq_items']   = self::sanitize_faq( $input['faq_items'] ?? array() );

        $out['shipping_cards'] = self::sanitize_cards( $input['shipping_cards'] ?? array() );
        $out['secure_badge']   = sanitize_text_field( $input['secure_badge'] ?? '' );
        $out['pickup_notice']  = sanitize_textarea_field( $input['pickup_notice'] ?? '' );

        $out['tracker_enabled'] = ! empty( $input['tracker_enabled'] );
        $out['tracker_labels']  = array_map( 'sanitize_text_field', array_slice( (array) ( $input['tracker_labels'] ?? $d['tracker_labels'] ), 0, 4 ) );
        $out['thankyou_message']= sanitize_text_field( $input['thankyou_message'] ?? '' );

        $out['newsletter_enabled'] = ! empty( $input['newsletter_enabled'] );
        $out['newsletter_text']    = sanitize_text_field( $input['newsletter_text'] ?? '' );

        $out['payment_order']  = array_map( 'sanitize_text_field', (array) ( $input['payment_order'] ?? array() ) );
        $out['payment_hidden'] = array_map( 'sanitize_text_field', (array) ( $input['payment_hidden'] ?? array() ) );
        $out['payment_icons']  = self::sanitize_icons( $input['payment_icons'] ?? array() );

        $out['checkout_payment_first'] = ! empty( $input['checkout_payment_first'] );

        // Recargo (%): acepta coma o punto decimal; se acota a 0–100.
        $rate_in = isset( $input['surcharge_rate'] ) ? str_replace( ',', '.', (string) $input['surcharge_rate'] ) : (string) $d['surcharge_rate'];
        $rate    = (float) $rate_in;
        $out['surcharge_rate'] = max( 0.0, min( 100.0, round( $rate, 2 ) ) );

        // Marcas con recargo: IDs de término (>0), sin duplicados.
        $brands = array_map( 'absint', (array) ( $input['surcharge_brands'] ?? array() ) );
        $out['surcharge_brands'] = array_values( array_unique( array_filter( $brands ) ) );

        $out['coordinadora_enabled'] = ! empty( $input['coordinadora_enabled'] );
        $out['coordinadora_puente']  = ! empty( $input['coordinadora_puente'] );
        $out['coordinadora_apikey']  = sanitize_text_field( $input['coordinadora_apikey'] ?? '' );
        $out['coordinadora_clave']   = sanitize_text_field( $input['coordinadora_clave'] ?? '' );
        $out['coordinadora_nit']     = preg_replace( '/[^0-9]/', '', (string) ( $input['coordinadora_nit'] ?? '' ) );
        $out['coordinadora_origin']  = preg_replace( '/[^0-9]/', '', (string) ( $input['coordinadora_origin'] ?? '' ) );

        $thr = isset( $input['coordinadora_weight_threshold'] )
            ? str_replace( ',', '.', (string) $input['coordinadora_weight_threshold'] )
            : (string) $d['coordinadora_weight_threshold'];
        $out['coordinadora_weight_threshold'] = max( 0.0, round( (float) $thr, 2 ) );

        $out['coordinadora_box_rules'] = self::sanitize_box_rules( $input['coordinadora_box_rules'] ?? array() );

        $out['guias_enabled'] = ! empty( $input['guias_enabled'] );
        $env                  = (string) ( $input['guias_env'] ?? 'sandbox' );
        $out['guias_env']     = in_array( $env, array( 'sandbox', 'production' ), true ) ? $env : 'sandbox';
        $out['guias_usuario'] = sanitize_text_field( $input['guias_usuario'] ?? $d['guias_usuario'] );
        $out['guias_clave']   = sanitize_text_field( $input['guias_clave'] ?? '' );
        $out['guias_id_cliente'] = absint( $input['guias_id_cliente'] ?? $d['guias_id_cliente'] );
        $out['guias_remitente_nombre']    = sanitize_text_field( $input['guias_remitente_nombre'] ?? $d['guias_remitente_nombre'] );
        $out['guias_remitente_direccion'] = sanitize_text_field( $input['guias_remitente_direccion'] ?? '' );
        $out['guias_remitente_telefono']  = preg_replace( '/[^0-9]/', '', (string) ( $input['guias_remitente_telefono'] ?? '' ) );
        $out['guias_webhook_url']         = esc_url_raw( $input['guias_webhook_url'] ?? '' );
        $out['guias_id_cliente_ce']       = absint( $input['guias_id_cliente_ce'] ?? $d['guias_id_cliente_ce'] );
        $out['guias_cuenta_ce']           = absint( $input['guias_cuenta_ce'] ?? $d['guias_cuenta_ce'] );
        $out['guias_pickup_ask_url']      = esc_url_raw( $input['guias_pickup_ask_url'] ?? '' );
        $out['guias_api_secret']          = sanitize_text_field( $input['guias_api_secret'] ?? '' );

        return $out;
    }

    private static function sanitize_links( $rows ): array {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            $url   = esc_url_raw( $row['url'] ?? '' );
            $label = sanitize_text_field( $row['label'] ?? '' );
            if ( '' !== $url && '' !== $label ) {
                $clean[] = array( 'label' => $label, 'url' => $url );
            }
        }
        return $clean;
    }

    private static function sanitize_faq( $rows ): array {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            $q = sanitize_text_field( $row['q'] ?? '' );
            $a = wp_kses_post( $row['a'] ?? '' );
            if ( '' !== $q ) {
                $clean[] = array(
                    'q'          => $q,
                    'a'          => $a,
                    'icon_image' => esc_url_raw( $row['icon_image'] ?? '' ),
                );
            }
        }
        return $clean;
    }

    /**
     * Reglas de empaque: filas {cat, n}. Descarta cat<=0 o n<1 y deduplica por
     * categoría (la primera fila de cada categoría gana). PURO.
     */
    private static function sanitize_box_rules( $rows ): array {
        $clean = array();
        $seen  = array();
        foreach ( (array) $rows as $row ) {
            $cat = absint( $row['cat'] ?? 0 );
            $n   = absint( $row['n'] ?? 0 );
            if ( $cat > 0 && $n > 0 && ! isset( $seen[ $cat ] ) ) {
                $seen[ $cat ] = true;
                $clean[]      = array( 'cat' => $cat, 'n' => $n );
            }
        }
        return $clean;
    }

    private static function sanitize_cards( $rows ): array {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            $title = sanitize_text_field( $row['title'] ?? '' );
            if ( '' !== $title ) {
                $clean[] = array(
                    'icon'  => sanitize_text_field( $row['icon'] ?? '' ),
                    'title' => $title,
                    'text'  => sanitize_text_field( $row['text'] ?? '' ),
                    'image' => esc_url_raw( $row['image'] ?? '' ),
                );
            }
        }
        return $clean;
    }

    private static function sanitize_icons( $rows ): array {
        $clean = array();
        foreach ( (array) $rows as $id => $row ) {
            $id = sanitize_text_field( (string) $id );
            if ( '' === $id ) {
                continue;
            }
            $clean[ $id ] = array(
                'label' => sanitize_text_field( $row['label'] ?? '' ),
                'image' => esc_url_raw( $row['image'] ?? '' ),
                'bg'    => sanitize_hex_color( $row['bg'] ?? '' ) ?? '',
            );
        }
        return $clean;
    }

    public static function all(): array {
        $saved = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
    }

    public static function get( string $key, $default = null ) {
        $all = self::all();
        return $all[ $key ] ?? $default;
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Checkout CCM', 'ccm-checkout' ),
            __( 'Checkout CCM', 'ccm-checkout' ),
            'manage_woocommerce',
            'ccmck-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register(): void {
        register_setting( 'ccmck_settings_group', self::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array( __CLASS__, 'sanitize' ),
            'default'           => self::defaults(),
        ) );
    }

    public static function admin_assets( $hook ): void {
        if ( 'woocommerce_page_ccmck-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_media();
        wp_enqueue_script( 'ccmck-admin', CCMCK_URL . 'assets/ccmck-admin.js', array( 'jquery', 'wp-color-picker' ), CCMCK_VERSION, true );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $s = self::all();
        echo '<div class="wrap"><h1>' . esc_html__( 'Checkout CCM', 'ccm-checkout' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'ccmck_settings_group' );
        require CCMCK_DIR . 'includes/views/settings-page.php';
        submit_button();
        echo '</form></div>';
    }
}
