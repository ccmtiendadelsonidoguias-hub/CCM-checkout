<?php
defined( 'ABSPATH' ) || exit;

/**
 * Generación automática de guías de Coordinadora: al pasar un pedido a
 * "Procesando" arma las mismas cajas del cotizador (CCMCK_Coordinadora::pack),
 * llama Guias.generarGuia y guarda nº de guía + rastreo en el pedido. Rótulo
 * PDF bajo demanda y webhook a n8n para avisar al cliente por WhatsApp.
 * Cumple las observaciones de go-live de Coordinadora (fecha y nit_remitente
 * vacíos, razón social como remitente, DANE real de recogida).
 */
final class CCMCK_Guias {
    const META_GUIA = '_coordinadora_tracking_number'; // compatible con el plugin de terceros
    const META_URL  = '_coordinadora_tracking_url';
    const META_ID   = '_ccmck_guia_id_remision';
    // DANE del destino capturado en el checkout ANTES de que el plugin de
    // ciudades (wc-departamentos-y-ciudades-colombia, main.php:48-53) recorte
    // los últimos 11 caracteres de la ciudad al guardar el pedido.
    const META_DANE = '_ccmck_city_dane';

    const ENDPOINT_PROD    = 'https://guias.coordinadora.com/ws/guias/1.6/server.php';
    const ENDPOINT_SANDBOX = 'https://sandbox.coordinadora.com/agw/ws/guias/1.6/server.php';

    /**
     * ¿Se debe generar guía para este pedido? PURO.
     *
     * @param array $ctx {enabled, usuario, clave, shipping_ids, existing_guia, has_lock}
     * @return array{ok:bool, reason:string}
     */
    public static function should_generate( array $ctx ): array {
        $manual = ! empty( $ctx['manual'] ); // botón del admin: salta toggle y exclusión de pickup
        if ( ! $manual && empty( $ctx['enabled'] ) ) {
            return array( 'ok' => false, 'reason' => 'generación de guías desactivada' );
        }
        if ( '' === (string) ( $ctx['usuario'] ?? '' ) || '' === (string) ( $ctx['clave'] ?? '' ) ) {
            return array( 'ok' => false, 'reason' => 'faltan credenciales del WS de guías' );
        }
        if ( ! $manual ) {
            foreach ( (array) ( $ctx['shipping_ids'] ?? array() ) as $id ) {
                if ( false !== strpos( (string) $id, 'local_pickup' ) ) {
                    return array( 'ok' => false, 'reason' => 'pedido con recogida local' );
                }
            }
        }
        if ( '' !== (string) ( $ctx['existing_guia'] ?? '' ) ) {
            return array( 'ok' => false, 'reason' => 'el pedido ya tiene guía' );
        }
        if ( ! empty( $ctx['has_lock'] ) ) {
            return array( 'ok' => false, 'reason' => 'generación en curso (lock)' );
        }
        return array( 'ok' => true, 'reason' => '' );
    }

    /**
     * Params completos de Guias.generarGuia. Incorpora las observaciones de
     * Coordinadora: fecha vacía, nit_remitente vacío, razón social y DANE real
     * como remitente. PURO.
     */
    public static function build_guia_params( array $args ): array {
        $rem  = (array) ( $args['remitente'] ?? array() );
        $dest = (array) ( $args['destinatario'] ?? array() );

        $detalle = array();
        foreach ( (array) ( $args['detalle'] ?? array() ) as $d ) {
            $d['referencia']     = '';
            $d['nombre_empaque'] = 'Caja';
            $detalle[]           = $d;
        }

        return array(
            'codigo_remision'        => '',
            'fecha'                  => '',            // obs 1: vacía = fecha del día
            'id_cliente'             => (int) ( $args['id_cliente'] ?? 0 ),
            'estado'                 => 'IMPRESO',
            'id_remitente'           => 0,
            'nit_remitente'          => '',            // obs 2: debe ir vacío
            'nombre_remitente'       => (string) ( $rem['nombre'] ?? '' ),    // obs 3
            'direccion_remitente'    => (string) ( $rem['direccion'] ?? '' ),
            'telefono_remitente'     => (string) ( $rem['telefono'] ?? '' ),
            'ciudad_remitente'       => (string) ( $rem['ciudad'] ?? '' ),    // obs 4
            'nit_destinatario'       => (string) ( $dest['documento'] ?? '' ),
            'div_destinatario'       => '',
            'nombre_destinatario'    => (string) ( $dest['nombre'] ?? '' ),
            'direccion_destinatario' => (string) ( $dest['direccion'] ?? '' ),
            'ciudad_destinatario'    => (string) ( $dest['ciudad_dane'] ?? '' ),
            'telefono_destinatario'  => (string) ( $dest['telefono'] ?? '' ),
            'valor_declarado'        => (int) ( $args['valor_declarado'] ?? 0 ),
            'codigo_cuenta'          => 2,
            'codigo_producto'        => 0,
            'nivel_servicio'         => 1,
            'linea'                  => '',
            'contenido'              => (string) ( $args['contenido'] ?? 'Equipos de sonido' ),
            'referencia'             => (string) ( $args['referencia'] ?? '' ),
            'observaciones'          => (string) ( $args['observaciones'] ?? '' ),
            'detalle'                => $detalle,
            'recaudos'               => array(),
            'margen_izquierdo'       => 0,
            'margen_superior'        => 0,
            'formato_impresion'      => '',
            'usuario'                => (string) ( $args['usuario'] ?? '' ),
            'clave'                  => (string) ( $args['clave_sha256'] ?? '' ),
        );
    }

    /**
     * Parsea la respuesta de Guias.generarGuia. HTTP siempre 200: falló si el
     * body no es JSON, error !== null o no llega codigo_remision. PURO.
     *
     * @return array{ok:bool, codigo_remision:string, id_remision:int, tracking_url:string, error:string}
     */
    public static function parse_guia_response( string $body, $http_code ): array {
        $fail = array( 'ok' => false, 'codigo_remision' => '', 'id_remision' => 0, 'tracking_url' => '', 'error' => '' );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            $fail['error'] = 'Respuesta no-JSON del WS de guías (HTTP ' . (int) $http_code . ')';
            return $fail;
        }
        if ( isset( $data['error'] ) && null !== $data['error'] ) {
            $msg = is_array( $data['error'] ) ? ( $data['error']['message'] ?? 'error' ) : (string) $data['error'];
            $fail['error'] = (string) $msg;
            return $fail;
        }
        $result = $data['result'] ?? null;
        $codigo = is_array( $result ) ? (string) ( $result['codigo_remision'] ?? '' ) : '';
        if ( '' === $codigo ) {
            $fail['error'] = 'Respuesta sin codigo_remision';
            return $fail;
        }
        return array(
            'ok'              => true,
            'codigo_remision' => $codigo,
            'id_remision'     => (int) ( $result['id_remision'] ?? 0 ),
            'tracking_url'    => (string) ( $result['url_terceros'] ?? '' ),
            'error'           => '',
        );
    }

    /**
     * Resuelve el DANE del destino: meta capturado en el checkout (fuente más
     * fiable, inmune al recorte del plugin de ciudades) > ciudad de envío >
     * ciudad de facturación. Devuelve '' si ninguna fuente trae un DANE. PURO.
     */
    public static function resolve_destino( string $meta_dane, string $shipping_city, string $billing_city ): string {
        if ( preg_match( '/^\d{8}$/', $meta_dane ) ) {
            return $meta_dane;
        }
        $destino = CCMCK_Coordinadora::dane_from_city( $shipping_city );
        if ( '' !== $destino ) {
            return $destino;
        }
        return CCMCK_Coordinadora::dane_from_city( $billing_city );
    }

    /**
     * Payload del webhook a n8n (aviso WhatsApp). Contrato EXACTO del workflow
     * cwGuiaWa01 (n8n de Chatwoot): campos planos {order_id, phone, guia,
     * tracking_url, customer_name}; order_id y guia obligatorios; el endpoint
     * normaliza el teléfono (10 dígitos CO, 57… o +57…). Llamar UNA sola vez
     * por guía (el endpoint no deduplica; nuestro guard de idempotencia lo
     * garantiza). PURO.
     */
    public static function build_webhook_payload( array $args ): array {
        return array(
            'order_id'      => (string) ( $args['order_id'] ?? '' ),
            'phone'         => (string) ( $args['phone'] ?? '' ),
            'guia'          => (string) ( $args['guia'] ?? '' ),
            'tracking_url'  => (string) ( $args['tracking_url'] ?? '' ),
            'customer_name' => (string) ( $args['name'] ?? '' ),
        );
    }

    /** Log al canal de WooCommerce. */
    private static function log( string $msg ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning( $msg, array( 'source' => 'ccmck-coordinadora' ) );
        }
    }

    /** Endpoint según el ambiente configurado. */
    private static function endpoint(): string {
        return 'production' === CCMCK_Settings::get( 'guias_env', 'sandbox' )
            ? self::ENDPOINT_PROD
            : self::ENDPOINT_SANDBOX;
    }

    /** Llama un método del WS de guías. Devuelve el body crudo o WP_Error. */
    private static function rpc( string $method, array $params, int $timeout = 15 ) {
        return wp_remote_post( self::endpoint(), array(
            'timeout' => $timeout,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 0, 'method' => $method, 'params' => $params ) ),
        ) );
    }

    /** Mapa cat_id => N de las reglas de caja (mismo formato que el cotizador). */
    private static function rules_map(): array {
        $map = array();
        foreach ( (array) CCMCK_Settings::get( 'coordinadora_box_rules', array() ) as $row ) {
            $cat = (int) ( $row['cat'] ?? 0 );
            $n   = (int) ( $row['n'] ?? 0 );
            if ( $cat > 0 && $n > 0 ) {
                $map[ $cat ] = $n;
            }
        }
        return $map;
    }

    /**
     * Normaliza los items del pedido a la forma de CCMCK_Coordinadora::pack().
     * Devuelve array{items:array, missing:string} (missing = primer SKU/nombre
     * sin peso o dimensiones, '' si todo bien).
     */
    private static function items_from_order( $order ): array {
        $items   = array();
        $missing = '';
        foreach ( $order->get_items() as $line ) {
            $product = is_callable( array( $line, 'get_product' ) ) ? $line->get_product() : null;
            if ( ! $product ) {
                if ( '' === $missing ) {
                    $missing = is_callable( array( $line, 'get_name' ) ) ? (string) $line->get_name() : 'ítem sin producto';
                }
                continue;
            }
            $it = array(
                'qty'     => (int) $line->get_quantity(),
                'weight'  => (float) $product->get_weight(),
                'largo'   => (float) $product->get_length(),
                'ancho'   => (float) $product->get_width(),
                'alto'    => (float) $product->get_height(),
                'cat_ids' => array_map( 'intval', (array) ( function_exists( 'wc_get_product_cat_ids' ) ? wc_get_product_cat_ids( $product->get_id() ) : array() ) ),
            );
            if ( '' === $missing && ( $it['weight'] <= 0 || $it['largo'] <= 0 || $it['ancho'] <= 0 || $it['alto'] <= 0 ) ) {
                $missing = $product->get_sku() ? $product->get_sku() : $product->get_name();
            }
            $items[] = $it;
        }
        return array( 'items' => $items, 'missing' => $missing );
    }

    /** Hook woocommerce_order_status_processing: genera la guía. */
    public static function on_processing( $order_id ): void {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            return;
        }

        $shipping_ids = array();
        foreach ( $order->get_shipping_methods() as $sm ) {
            $shipping_ids[] = (string) $sm->get_method_id();
        }

        $check = self::should_generate( array(
            'enabled'       => (bool) CCMCK_Settings::get( 'guias_enabled', false ),
            'usuario'       => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'         => (string) CCMCK_Settings::get( 'guias_clave', '' ),
            'shipping_ids'  => $shipping_ids,
            'existing_guia' => (string) $order->get_meta( self::META_GUIA ),
            'has_lock'      => false !== get_transient( 'ccmck_guia_lock_' . $order_id ),
        ) );
        if ( ! $check['ok'] ) {
            // Silencioso para off/pickup/duplicado; son casos normales.
            return;
        }
        set_transient( 'ccmck_guia_lock_' . $order_id, 1, 60 );

        $result = self::generate_for_order( $order );
        if ( ! $result['ok'] ) {
            $order->add_order_note( 'Guía Coordinadora NO generada: ' . $result['error'] . '. Generar manualmente.' );
            delete_transient( 'ccmck_guia_lock_' . $order_id );
        }
    }

    /**
     * Núcleo compartido de generación (lo usan el hook automático y el botón
     * manual del pedido). Asume que los guards del llamador ya pasaron. En
     * éxito guarda metas + notas y dispara el aviso de WhatsApp; en fallo
     * loguea y devuelve el motivo (el llamador decide cómo presentarlo).
     *
     * @return array{ok:bool, error:string}
     */
    private static function generate_for_order( $order ): array {
        $order_id = (int) $order->get_id();

        $extracted = self::items_from_order( $order );
        if ( ! $extracted['items'] || '' !== $extracted['missing'] ) {
            $descriptor = '' !== $extracted['missing'] ? $extracted['missing'] : 'sin ítems';
            self::log( 'Guía pedido #' . $order_id . ': producto sin peso/medidas (' . $descriptor . ')' );
            return array( 'ok' => false, 'error' => 'producto sin peso/medidas (' . $descriptor . ')' );
        }

        $destino = self::resolve_destino(
            (string) $order->get_meta( self::META_DANE ),
            (string) $order->get_shipping_city(),
            (string) $order->get_billing_city()
        );
        if ( '' === $destino ) {
            self::log( 'Guía pedido #' . $order_id . ': ciudad sin DANE' );
            return array( 'ok' => false, 'error' => 'no se pudo extraer el código DANE de la ciudad' );
        }

        $threshold = (float) CCMCK_Settings::get( 'coordinadora_weight_threshold', 5.0 );
        $boxes     = CCMCK_Coordinadora::pack( $extracted['items'], $threshold, self::rules_map() );
        $detalle   = CCMCK_Coordinadora::build_detalle( $boxes );

        $total_lineas = 0.0;
        foreach ( $order->get_items() as $line ) {
            $total_lineas += (float) $line->get_total();
        }

        $direccion = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );

        $params = self::build_guia_params( array(
            'usuario'      => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave_sha256' => hash( 'sha256', (string) CCMCK_Settings::get( 'guias_clave', '' ) ),
            'id_cliente'   => (int) CCMCK_Settings::get( 'guias_id_cliente', 49444 ),
            'remitente'    => array(
                'nombre'    => (string) CCMCK_Settings::get( 'guias_remitente_nombre', '' ),
                'direccion' => (string) CCMCK_Settings::get( 'guias_remitente_direccion', '' ),
                'telefono'  => (string) CCMCK_Settings::get( 'guias_remitente_telefono', '' ),
                'ciudad'    => (string) CCMCK_Settings::get( 'coordinadora_origin', '08001000' ),
            ),
            'destinatario' => array(
                'nombre'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'direccion'   => $direccion,
                'ciudad_dane' => $destino,
                'telefono'    => (string) $order->get_billing_phone(),
                'documento'   => (string) $order->get_meta( '_billing_document_number' ),
            ),
            'valor_declarado' => (int) round( $total_lineas ),
            'contenido'    => 'Equipos de sonido',
            'referencia'   => (string) $order->get_order_number(),
            'observaciones'=> (string) $order->get_customer_note(),
            'detalle'      => $detalle,
        ) );

        $response = self::rpc( 'Guias.generarGuia', $params );
        if ( is_wp_error( $response ) ) {
            self::log( 'Guía pedido #' . $order_id . ' HTTP: ' . $response->get_error_message() );
            return array( 'ok' => false, 'error' => 'error de conexión con el WS de guías (' . $response->get_error_message() . ')' );
        }
        $parsed = self::parse_guia_response( (string) wp_remote_retrieve_body( $response ), wp_remote_retrieve_response_code( $response ) );
        if ( ! $parsed['ok'] ) {
            self::log( 'Guía pedido #' . $order_id . ' API: ' . $parsed['error'] );
            return array( 'ok' => false, 'error' => $parsed['error'] );
        }

        $order->update_meta_data( self::META_GUIA, $parsed['codigo_remision'] );
        $order->update_meta_data( self::META_URL, $parsed['tracking_url'] );
        $order->update_meta_data( self::META_ID, (string) $parsed['id_remision'] );
        $order->save();
        $order->add_order_note( 'Guía Coordinadora generada: ' . $parsed['codigo_remision'] );

        $wa = self::send_webhook( self::build_webhook_payload( array(
            'order_id'     => (string) $order->get_order_number(),
            'guia'         => $parsed['codigo_remision'],
            'tracking_url' => $parsed['tracking_url'],
            'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'phone'        => (string) $order->get_billing_phone(),
        ) ) );
        if ( is_array( $wa ) && ! empty( $wa['ok'] ) ) {
            $modo = (string) ( $wa['mode_used'] ?? '' );
            $order->add_order_note( 'Aviso de guía enviado al cliente por WhatsApp' . ( '' !== $modo ? ' (' . $modo . ')' : '' ) . '.' );
        }

        return array( 'ok' => true, 'error' => '' );
    }

    /**
     * Captura el DANE del destino en el checkout, ANTES de que el plugin de
     * ciudades recorte la ciudad al guardar (woocommerce_checkout_update_order_meta).
     * Hook woocommerce_checkout_create_order: lee la ciudad cruda del POST del
     * checkout y guarda el DANE en meta propio. El save lo hace WooCommerce.
     *
     * @param WC_Order $order Pedido en construcción.
     * @param array    $data  Datos posteados del checkout.
     */
    public static function capture_checkout_dane( $order, $data ): void {
        $city = (string) ( $data['billing_city'] ?? '' );
        if ( '' === $city && isset( $data['billing']['city'] ) ) {
            $city = (string) $data['billing']['city'];
        }
        $dane = CCMCK_Coordinadora::dane_from_city( $city );
        if ( '' !== $dane && is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
            $order->update_meta_data( self::META_DANE, $dane );
        }
    }

    /**
     * Webhook a n8n (aviso WhatsApp). Fire-and-forget: el fallo solo se loguea,
     * nunca bloquea la guía. Devuelve la respuesta decodificada del endpoint
     * ({ok, mode_used, ...}) o null si no hay URL configurada / falló.
     */
    private static function send_webhook( array $payload ): ?array {
        $url = (string) CCMCK_Settings::get( 'guias_webhook_url', '' );
        if ( '' === $url ) {
            return null;
        }
        // 15 s: el workflow n8n envía el WhatsApp (Chatwoot + WABA) ANTES de
        // responder y puede superar los 5 s (visto en prod: cURL error 28).
        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $response ) ) {
            self::log( 'Webhook n8n falló (pedido #' . $payload['order_id'] . '): ' . $response->get_error_message() );
            return null;
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
            self::log( 'Webhook n8n sin ok (pedido #' . $payload['order_id'] . '): ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ) );
            return null;
        }
        return $body;
    }

    /** Markup de la caja de guía en el pedido del admin. PURO. */
    public static function guia_box_markup( string $guia, string $tracking_url, string $label_url ): string {
        if ( '' === trim( $guia ) ) {
            return '';
        }
        $html  = '<div class="ccmck-guia-box" style="margin-top:12px;padding:10px;border:1px solid #c3c4c7;border-radius:4px;">';
        $html .= '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Guía Coordinadora:', 'ccm-checkout' ) . '</strong> ' . esc_html( $guia ) . '</p>';
        if ( '' !== $tracking_url ) {
            $html .= '<p style="margin:0 0 6px;"><a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver rastreo', 'ccm-checkout' ) . '</a></p>';
        }
        $html .= '<a class="button" href="' . esc_url( $label_url ) . '">' . esc_html__( 'Descargar rótulo', 'ccm-checkout' ) . '</a>';
        $html .= '</div>';
        return $html;
    }

    /** Render en el pedido del admin (hook woocommerce_admin_order_data_after_billing_address). */
    public static function render_admin( $order ): void {
        $guia = (string) $order->get_meta( self::META_GUIA );
        if ( '' === $guia ) {
            // Sin guía: botón de generación manual (recogidas marcadas por
            // error, fallos de la automática ya corregidos, etc.).
            $generate_url = wp_nonce_url(
                admin_url( 'admin-ajax.php?action=ccmck_guia_generate&order_id=' . (int) $order->get_id() ),
                'ccmck_guia_generate'
            );
            echo self::generate_button_markup( $generate_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro del método.
            return;
        }
        $label_url = wp_nonce_url(
            admin_url( 'admin-ajax.php?action=ccmck_guia_label&order_id=' . (int) $order->get_id() ),
            'ccmck_guia_label'
        );
        echo self::guia_box_markup( $guia, (string) $order->get_meta( self::META_URL ), $label_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro del método.
    }

    /**
     * Botón "Generar guía Coordinadora" para pedidos sin guía. La generación
     * manual salta la exclusión de recogida local (caso de rescate) pero
     * mantiene idempotencia, lock y validaciones. PURO.
     */
    public static function generate_button_markup( string $generate_url ): string {
        if ( '' === trim( $generate_url ) ) {
            return '';
        }
        $html  = '<div class="ccmck-guia-box" style="margin-top:12px;">';
        $html .= '<a class="button" href="' . esc_url( $generate_url ) . '" onclick="return confirm(\'' . esc_js( __( '¿Generar la guía de Coordinadora para este pedido? Se enviará el aviso por WhatsApp al cliente.', 'ccm-checkout' ) ) . '\');">';
        $html .= esc_html__( 'Generar guía Coordinadora', 'ccm-checkout' );
        $html .= '</a>';
        $html .= '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Para recogidas marcadas por error u otros casos manuales. Requiere ciudad con código DANE.', 'ccm-checkout' ) . '</p>';
        $html .= '</div>';
        return $html;
    }

    /** AJAX: generación manual de la guía desde el botón del pedido. */
    public static function ajax_generate(): void {
        check_ajax_referer( 'ccmck_guia_generate' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'ccm-checkout' ) );
        }
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_die( esc_html__( 'Pedido no encontrado.', 'ccm-checkout' ) );
        }

        $check = self::should_generate( array(
            'manual'        => true,
            'usuario'       => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'         => (string) CCMCK_Settings::get( 'guias_clave', '' ),
            'existing_guia' => (string) $order->get_meta( self::META_GUIA ),
            'has_lock'      => false !== get_transient( 'ccmck_guia_lock_' . $order_id ),
        ) );
        if ( ! $check['ok'] ) {
            wp_die( esc_html( 'No se puede generar la guía: ' . $check['reason'] . '.' ), '', array( 'back_link' => true ) );
        }
        set_transient( 'ccmck_guia_lock_' . $order_id, 1, 60 );

        $result = self::generate_for_order( $order );
        if ( ! $result['ok'] ) {
            delete_transient( 'ccmck_guia_lock_' . $order_id );
            wp_die(
                esc_html( 'No se pudo generar la guía: ' . $result['error'] . '. Corrige el dato en el pedido (ej. Población con su código DANE: MEDELLIN (ANT) (05001000)), guarda y vuelve a intentar.' ),
                '',
                array( 'back_link' => true )
            );
        }

        wp_safe_redirect( $order->get_edit_order_url() );
        exit;
    }

    /** AJAX: descarga el rótulo PDF al vuelo vía Guias.reimprimirGuia. */
    public static function ajax_label(): void {
        check_ajax_referer( 'ccmck_guia_label' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'ccm-checkout' ) );
        }
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        $guia     = $order ? (string) $order->get_meta( self::META_GUIA ) : '';
        if ( '' === $guia ) {
            wp_die( esc_html__( 'El pedido no tiene guía.', 'ccm-checkout' ) );
        }
        $response = self::rpc( 'Guias.reimprimirGuia', array(
            'usuario'          => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'            => hash( 'sha256', (string) CCMCK_Settings::get( 'guias_clave', '' ) ),
            'codigo_remision'  => $guia,
            'formato_impresion' => '1',
        ) );
        if ( is_wp_error( $response ) ) {
            wp_die( esc_html( $response->get_error_message() ) );
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $pdf  = is_array( $data ) && isset( $data['result']['pdf'] ) ? base64_decode( (string) $data['result']['pdf'] ) : '';
        if ( '' === $pdf || '%PDF' !== substr( $pdf, 0, 4 ) ) {
            $msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Rótulo no disponible.';
            wp_die( esc_html( $msg ) );
        }
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="guia-' . rawurlencode( $guia ) . '.pdf"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario PDF.
        exit;
    }

    public static function init(): void {
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_processing' ), 20 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin' ) );
        add_action( 'wp_ajax_ccmck_guia_label', array( __CLASS__, 'ajax_label' ) );
        add_action( 'wp_ajax_ccmck_guia_generate', array( __CLASS__, 'ajax_generate' ) );
        // Prio 10: corre antes de woocommerce_checkout_update_order_meta, donde
        // el plugin de ciudades recorta el DANE de la ciudad.
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'capture_checkout_dane' ), 10, 2 );
    }
}
