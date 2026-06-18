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
        );
    }

    public static function sanitize( array $input ): array {
        $d   = self::defaults();
        $out = array();

        $out['accent_color']  = sanitize_hex_color( $input['accent_color']  ?? '' ) ?? '';
        $out['sidebar_color'] = sanitize_hex_color( $input['sidebar_color'] ?? '' ) ?? '';
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

        $out['tracker_enabled'] = ! empty( $input['tracker_enabled'] );
        $out['tracker_labels']  = array_map( 'sanitize_text_field', array_slice( (array) ( $input['tracker_labels'] ?? $d['tracker_labels'] ), 0, 4 ) );
        $out['thankyou_message']= sanitize_text_field( $input['thankyou_message'] ?? '' );

        $out['newsletter_enabled'] = ! empty( $input['newsletter_enabled'] );
        $out['newsletter_text']    = sanitize_text_field( $input['newsletter_text'] ?? '' );

        $out['payment_order']  = array_map( 'sanitize_text_field', (array) ( $input['payment_order'] ?? array() ) );
        $out['payment_hidden'] = array_map( 'sanitize_text_field', (array) ( $input['payment_hidden'] ?? array() ) );
        $out['payment_icons']  = self::sanitize_icons( $input['payment_icons'] ?? array() );

        $out['checkout_payment_first'] = ! empty( $input['checkout_payment_first'] );

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
                $clean[] = array( 'q' => $q, 'a' => $a );
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
