<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Payments {
    public static function init(): void {
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'apply_settings' ) );
        // Addi exige una dirección con ciudad para crear el crédito: su API rechaza
        // con 400 "021-024 The city of the address of the client is invalid." cuando
        // billing_city va vacío (caso típico al elegir Recogida local, que relaja la
        // dirección). Si el método es Addi, exigimos departamento + ciudad aunque haya
        // pickup, para bloquear con un mensaje claro ANTES de llamar a Addi (en vez de
        // que Addi falle con un error genérico). El JS replica la obligatoriedad inline.
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'require_address_for_addi' ), 10, 2 );
        // Aviso de cuotas bajo el boton, solo para las pasarelas de financiacion.
        add_action( 'woocommerce_review_order_after_submit', array( __CLASS__, 'render_aviso_cuotas' ) );
    }

    /**
     * Pasarelas de financiacion: fragmento del id => nombre de la marca.
     *
     * Se busca por SUBCADENA del id (`addi`, `wcsistecredito`) igual que hace
     * `require_address_for_addi`, para que un cambio de slug del plugin de la
     * pasarela no deje el aviso mudo en silencio.
     *
     * El nombre NO sale de `$gateway->get_title()` a proposito: medido en el
     * checkout el 4-sep-2026, Addi se titula «Paga a cuotas» y Sistecredito
     * «Paga con» —el resto de su marca es un logo—, asi que la frase quedaria
     * «...pagar con Paga con.». La marca se escribe aqui.
     */
    private const FINANCIACION = array(
        'addi'         => 'Addi',
        'sistecredito' => 'Sistecrédito',
    );

    /**
     * Aviso bajo el boton de pagar: dice que el boton NO cierra la compra
     * todavia, que despues se eligen las cuotas.
     *
     * Va colgado de `woocommerce_review_order_after_submit`, que la plantilla
     * dispara justo despues del boton. NO necesita JavaScript: verificado en
     * dev el 4-sep-2026 plantando tres marcadores dentro de `#payment` y
     * cambiando de metodo de pago —los tres desaparecieron, o sea que
     * WooCommerce re-renderiza el bloque entero en el servidor en cada cambio—.
     * Por eso el aviso no puede desincronizarse del radio marcado, y funciona
     * igual en la primera carga que tras un refresco AJAX.
     */
    public static function render_aviso_cuotas(): void {
        $marca = self::marca_financiacion( self::pasarela_elegida() );
        if ( '' === $marca ) {
            return;
        }
        printf(
            '<p class="ccmck-cuotas-aviso">%s</p>',
            esc_html(
                sprintf(
                    /* translators: %s: nombre de la financiera (Addi, Sistecrédito). */
                    __( 'Continúa para elegir en cuántas cuotas quieres pagar con %s.', 'ccm-checkout' ),
                    $marca
                )
            )
        );
    }

    /**
     * Id de la pasarela marcada, leido de la MISMA fuente que usa la plantilla
     * para pintar el `checked` del radio (`$gateway->chosen`). Cualquier otra
     * fuente —la sesion, el POST— podria discrepar del radio que el cliente ve.
     */
    private static function pasarela_elegida(): string {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
            return '';
        }
        foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway ) {
            if ( ! empty( $gateway->chosen ) ) {
                return (string) $gateway->id;
            }
        }
        return '';
    }

    /**
     * Nombre de marca para un id de pasarela, o '' si no es de financiacion.
     * PURO.
     */
    public static function marca_financiacion( string $id ): string {
        if ( '' === $id ) {
            return '';
        }
        foreach ( self::FINANCIACION as $fragmento => $marca ) {
            if ( false !== stripos( $id, $fragmento ) ) {
                return $marca;
            }
        }
        return '';
    }

    /**
     * Cuando el método de pago es Addi, departamento (billing_state) y ciudad
     * (billing_city) son obligatorios (Addi los necesita para originar el crédito).
     *
     * @param array    $data   Datos posteados del checkout.
     * @param WP_Error $errors Errores de validación (por referencia).
     */
    public static function require_address_for_addi( $data, $errors ): void {
        if ( ! is_wp_error( $errors ) ) {
            return;
        }
        $method = isset( $data['payment_method'] ) ? (string) $data['payment_method'] : '';
        if ( false === stripos( $method, 'addi' ) ) {
            return;
        }
        $state = isset( $data['billing_state'] ) ? trim( (string) $data['billing_state'] ) : '';
        $city  = isset( $data['billing_city'] ) ? trim( (string) $data['billing_city'] ) : '';
        if ( '' === $state ) {
            $errors->add( 'billing_state_required', __( 'Selecciona tu departamento para pagar con Addi.', 'ccm-checkout' ) );
        }
        if ( '' === $city ) {
            $errors->add( 'billing_city_required', __( 'Selecciona tu ciudad para pagar con Addi.', 'ccm-checkout' ) );
        }
    }

    /**
     * Renderiza los campos de pago de un gateway. Para Addi —que imprime su
     * descripción con <div> dentro de <b> (HTML inválido), lo que hace que el
     * parser del navegador esparza <b> sueltos por la página y termine
     * envolviendo el <aside> del resumen, rompiendo la grilla— bufferiza la
     * salida y convierte los <b> en <span> (mismo render, pero <span> NO es
     * "elemento de formato": no dispara la corrupción). El resto de gateways
     * se imprimen sin cambios. Se llama desde templates/checkout/payment.php.
     *
     * NOTA: Addi pinta su HTML directo en payment_fields(), NO vía
     * get_description()/woocommerce_gateway_description, por eso hay que
     * bufferizar aquí en lugar de filtrar la descripción.
     */
    public static function render_payment_fields( $gateway ): void {
        $id = is_object( $gateway ) && isset( $gateway->id ) ? (string) $gateway->id : '';
        if ( false === stripos( $id, 'addi' ) ) {
            $gateway->payment_fields();
            return;
        }
        ob_start();
        $gateway->payment_fields();
        echo self::fix_b_tags( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Convierte <b …>…</b> en <span …>…</span> conservando atributos. PURO.
     * Neutraliza el <div> dentro de <b> que corrompe el DOM (un <span> sí puede
     * contener bloques sin disparar el "adoption agency" del parser).
     */
    public static function fix_b_tags( string $html ): string {
        if ( false === stripos( $html, '<b' ) ) {
            return $html;
        }
        $html = preg_replace( '/<b(\s[^>]*)?>/i', '<span$1>', $html );
        return str_ireplace( '</b>', '</span>', $html );
    }

    public static function apply_settings( array $gateways ): array {
        if ( ! is_checkout() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return $gateways;
        }
        return self::sort_and_filter( $gateways, CCMCK_Settings::all() );
    }

    public static function sort_and_filter( array $gateways, array $settings ): array {
        $hidden = array_flip( (array) ( $settings['payment_hidden'] ?? array() ) );
        $order  = (array) ( $settings['payment_order'] ?? array() );

        $visible = array();
        foreach ( $gateways as $id => $gw ) {
            if ( ! isset( $hidden[ $id ] ) ) {
                $visible[ $id ] = $gw;
            }
        }

        $sorted = array();
        foreach ( $order as $id ) {
            if ( isset( $visible[ $id ] ) ) {
                $sorted[ $id ] = $visible[ $id ];
                unset( $visible[ $id ] );
            }
        }
        foreach ( $visible as $id => $gw ) {
            $sorted[ $id ] = $gw;
        }
        return $sorted;
    }

    public static function icon_for( string $gateway_id ): array {
        $icons = CCMCK_Settings::get( 'payment_icons', array() );
        return $icons[ $gateway_id ] ?? array();
    }
}
