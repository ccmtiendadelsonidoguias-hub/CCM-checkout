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
    // Marca que ya se le preguntó al cliente (vía n8n/WhatsApp) si prefiere
    // envío en vez de recogida — se pregunta UNA sola vez por pedido.
    const META_PICKUP_ASK = '_ccmck_pickup_ask_sent';
    /** Meta escrita al crear el pedido (botón Venta n8n): 'contra_entrega' → la guía sale CE. */
    const META_MODALIDAD  = '_ccm_flete_modalidad';
    /** Conversación de Chatwoot que originó la venta (la pone el botón Venta). */
    const META_CONVERSACION = '_ccm_conversation';
    /** Intentos que lleva el barredor sobre este pedido. */
    const META_INTENTOS = '_ccmck_guia_intentos';

    /**
     * Minutos de gracia antes de que el barredor toque un pedido.
     *
     * El camino normal (`on_processing`) debe tener su oportunidad primero: sin
     * gracia, el barredor competiría con él y ambos llamarían a Coordinadora.
     */
    const SWEEP_GRACIA_MIN = 10;

    /**
     * Cuántas veces reintenta el barredor antes de rendirse.
     *
     * Un pedido con defecto permanente —producto sin peso, dirección incompleta—
     * no se arregla reintentando: sin tope martillearía el WS cada 15 minutos y
     * llenaría el pedido de notas.
     */
    const SWEEP_MAX_INTENTOS = 3;

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
        if ( ! $manual && self::has_pickup( (array) ( $ctx['shipping_ids'] ?? array() ) ) ) {
            return array( 'ok' => false, 'reason' => 'pedido con recogida local' );
        }
        // El envío debe ser Coordinadora. El botón "Venta" del bot permite elegir otra
        // transportadora (method_id 'other_carrier'), y sin este guard el hook automático
        // igual sacaba rótulo de Coordinadora por un envío que no despachamos nosotros.
        // Sólo aplica al automático: el botón manual y /generar-guia siguen pudiendo
        // convertir un pedido a Coordinadora (el cliente cambia de opinión por WhatsApp).
        if ( ! $manual && ! self::is_coordinadora( (array) ( $ctx['shipping_ids'] ?? array() ) ) ) {
            return array( 'ok' => false, 'reason' => 'envío con otra transportadora' );
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
     * ¿Debe el barredor generar la guía de este pedido? PURA.
     *
     * Deliberadamente NO acepta `manual`: a diferencia de `/generar-guia`, el
     * barredor jamás debe saltarse el guard de transportadora. Un pedido enviado
     * por otra empresa no puede acabar con un rótulo de Coordinadora.
     *
     * @param array $ctx {status, shipping_ids, existing_guia, minutos, intentos}
     * @return array{ok:bool, reason:string}
     */
    public static function sweep_decision( array $ctx ): array {
        if ( 'processing' !== (string) ( $ctx['status'] ?? '' ) ) {
            return array( 'ok' => false, 'reason' => 'no está en processing' );
        }
        if ( ! self::is_coordinadora( (array) ( $ctx['shipping_ids'] ?? array() ) ) ) {
            return array( 'ok' => false, 'reason' => 'envío con otra transportadora' );
        }
        if ( '' !== trim( (string) ( $ctx['existing_guia'] ?? '' ) ) ) {
            return array( 'ok' => false, 'reason' => 'el pedido ya tiene guía' );
        }
        if ( (int) ( $ctx['minutos'] ?? 0 ) < self::SWEEP_GRACIA_MIN ) {
            return array( 'ok' => false, 'reason' => 'demasiado reciente' );
        }
        if ( (int) ( $ctx['intentos'] ?? 0 ) >= self::SWEEP_MAX_INTENTOS ) {
            return array( 'ok' => false, 'reason' => 'agotados los reintentos' );
        }
        return array( 'ok' => true, 'reason' => '' );
    }

    /**
     * Minutos transcurridos desde que el pedido quedó listo para generar guía. PURA.
     *
     * Usa la fecha de pago; si no hay (get_date_paid() puede devolver null —
     * pagos que no disparan ese hook, o el dato aún no propagado), cae a la de
     * creación. Sin esta caída, un pedido sin fecha de pago se quedaba en 0
     * minutos en cada pasada del barredor: nunca superaba la gracia, nunca subía
     * el contador de intentos y nunca llegaba a 'agotados los reintentos' —la
     * única cadena que dispara la alerta por correo—, así que quedaba barrido en
     * silencio para siempre. Si tampoco hay fecha de creación, 0 (no hay nada de
     * qué medir). Nunca negativo: un reloj desincronizado no debe dar minutos
     * negativos, que colarían por debajo de cualquier umbral.
     *
     * Sin objetos de WooCommerce a propósito: el llamador convierte
     * get_date_paid()/get_date_created() a timestamp (o null) antes de llamar,
     * para poder probar esto sin cargar WordPress.
     *
     * @param int|null $ts_pagado Timestamp Unix de get_date_paid(), o null.
     * @param int|null $ts_creado Timestamp Unix de get_date_created(), o null.
     * @param int      $ahora     Timestamp Unix "ahora" (inyectado para poder probar).
     */
    public static function sweep_minutos( $ts_pagado, $ts_creado, $ahora ): int {
        $ts = ( null !== $ts_pagado && $ts_pagado > 0 ) ? $ts_pagado : $ts_creado;
        if ( null === $ts || $ts <= 0 ) {
            return 0;
        }
        return max( 0, (int) floor( ( (int) $ahora - (int) $ts ) / 60 ) );
    }

    /**
     * ¿El envío es con Coordinadora? PURO. Cubre las dos variantes que existen en
     * pedidos reales ('ccmck_coordinadora' y el legado 'coordinadora'). Un pedido sin
     * línea de envío devuelve false: sin método no hay despacho que rotular, y el
     * botón manual queda para el caso excepcional.
     */
    public static function is_coordinadora( array $shipping_ids ): bool {
        foreach ( $shipping_ids as $id ) {
            if ( false !== stripos( (string) $id, 'coordinadora' ) ) {
                return true;
            }
        }
        return false;
    }

    /** ¿Alguno de los métodos de envío es recogida local? PURO. */
    public static function has_pickup( array $shipping_ids ): bool {
        foreach ( $shipping_ids as $id ) {
            if ( false !== strpos( (string) $id, 'local_pickup' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * ¿Puede este cliente bajar este rótulo? PURA.
     *
     * Misma forma que `should_generate()`, que ya está en esta clase.
     *
     * @param array $ctx {logged_in, user_id, order_found, order_customer_id, guia}
     * @return array{ok:bool, reason:string}
     */
    public static function customer_label_check( array $ctx ): array {
        if ( empty( $ctx['logged_in'] ) ) {
            return array( 'ok' => false, 'reason' => 'sesion requerida' );
        }

        $usuario = (int) ( $ctx['user_id'] ?? 0 );
        $dueno   = (int) ( $ctx['order_customer_id'] ?? 0 );

        // Un mismo motivo para "no existe" y "no es tuyo": si difirieran, un
        // cliente podría averiguar qué pedidos existen probando números.
        // El 0 (pedido de invitado) nunca coincide con nadie: un usuario con
        // sesión tiene id > 0.
        if ( empty( $ctx['order_found'] ) || $usuario < 1 || $dueno !== $usuario ) {
            return array( 'ok' => false, 'reason' => 'pedido no disponible' );
        }

        if ( ! self::has_guide( (string) ( $ctx['guia'] ?? '' ) ) ) {
            return array( 'ok' => false, 'reason' => 'el pedido no tiene guia' );
        }

        return array( 'ok' => true, 'reason' => '' );
    }

    /** ¿Este pedido tiene guía y por tanto envío que enseñar? PURO. */
    public static function has_guide( string $guia ): bool {
        return '' !== trim( $guia );
    }

    /**
     * Envíos de un cliente: un pedido suyo por cada guía.
     *
     * Se filtra en PHP y no con una consulta por meta a propósito. Una
     * `meta_query` funciona con el almacenamiento clásico pero no con HPOS, y
     * esta tienda usa el clásico **hoy**; filtrar aquí sirve para los dos y no
     * hay que acordarse de nada el día que migre.
     *
     * @return array Lista ordenada de la más reciente a la más antigua.
     */
    public static function customer_shipments( int $user_id ): array {
        if ( $user_id < 1 ) {
            return array();
        }

        $pedidos = wc_get_orders( array(
            'customer' => $user_id,
            'limit'    => -1,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ) );

        $envios = array();

        foreach ( $pedidos as $order ) {
            $guia = (string) $order->get_meta( self::META_GUIA );

            if ( ! self::has_guide( $guia ) ) {
                continue;
            }

            $envios[] = array(
                'order_id'     => $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'date'         => $order->get_date_created(),
                'status'       => (string) $order->get_status(),
                'guia'         => $guia,
                'tracking_url' => (string) $order->get_meta( self::META_URL ),
                'label_url'    => self::customer_label_url( $order->get_id() ),
            );
        }

        return $envios;
    }

    /** URL firmada para que el cliente baje el rótulo de un pedido suyo. */
    public static function customer_label_url( int $order_id ): string {
        $url = add_query_arg(
            array(
                'action'   => 'ccmck_rotulo_cliente',
                'order_id' => $order_id,
            ),
            admin_url( 'admin-ajax.php' )
        );

        return wp_nonce_url( $url, 'ccmck_rotulo_cliente_' . $order_id );
    }

    /**
     * Nombre del archivo del rótulo. PURO.
     *
     * Va dentro de `Content-Disposition`, que es una cabecera HTTP: un salto de
     * línea o unas comillas ahí no son un nombre feo, son inyección de
     * cabeceras. Por eso se filtra a alfanuméricos y guiones en vez de escapar.
     */
    public static function label_filename( string $guia ): string {
        $limpio = preg_replace( '/[^A-Za-z0-9\-]/', '', $guia );

        return '' === $limpio ? 'rotulo.pdf' : 'guia-' . $limpio . '.pdf';
    }

    /**
     * Clave del transient donde se guarda el rótulo. PURA.
     *
     * Por md5 y no por el número en crudo: una clave de transient con
     * expiración no puede pasar de 172 caracteres, porque WordPress le antepone
     * `_transient_timeout_` y `option_name` tiene 191. Un número de guía raro no
     * puede reventar la caché.
     */
    public static function label_cache_key( string $guia ): string {
        return 'ccmck_rotulo_' . md5( $guia );
    }

    // Segundos literales y no `HOUR_IN_SECONDS`: una constante de clase se
    // evalúa al incluir el archivo, y en el banco de pruebas eso pasa antes de
    // que el bootstrap defina las constantes de WordPress. Con la constante de
    // WP aquí, las pruebas revientan con "undefined constant".

    /** Cuánto se guarda un rótulo que sí llegó: 12 horas. */
    const LABEL_TTL = 43200;

    /** Cuánto se guarda un fallo, para no martillear a Coordinadora: 5 minutos. */
    const LABEL_FAIL_TTL = 300;

    /** Marca de "esto falló". No es base64 válido a propósito. */
    const LABEL_FAIL = '!fallo';

    /**
     * Rótulo desde la caché, y si no está, del `$fetcher`.
     *
     * El `$fetcher` se inyecta para poder probar la caché sin llamar a
     * Coordinadora. En producción es siempre `fetch_label_b64()`.
     *
     * Se cachea también el fallo: sin eso, un cliente que pulse "Descargar"
     * veinte veces son veinte llamadas SOAP.
     *
     * @return string base64 del PDF, o '' si no hay rótulo.
     */
    public static function label_from_cache_or( string $guia, callable $fetcher ): string {
        $clave    = self::label_cache_key( $guia );
        $guardado = get_transient( $clave );

        if ( self::LABEL_FAIL === $guardado ) {
            return '';
        }

        if ( is_string( $guardado ) && '' !== $guardado ) {
            return $guardado;
        }

        $b64 = (string) $fetcher( $guia );

        if ( '' === $b64 ) {
            set_transient( $clave, self::LABEL_FAIL, self::LABEL_FAIL_TTL );
            return '';
        }

        set_transient( $clave, $b64, self::LABEL_TTL );

        return $b64;
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
            'codigo_cuenta'          => (int) ( $args['codigo_cuenta'] ?? 2 ),
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
        $fail = array( 'ok' => false, 'codigo_remision' => '', 'id_remision' => 0, 'tracking_url' => '', 'pdf_b64' => '', 'error' => '' );
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
            'pdf_b64'         => (string) ( $result['pdf_guia'] ?? '' ),
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
     * tracking_url, customer_name}; opcionales email, rotulo_b64 y conversation;
     * order_id y guia obligatorios; el endpoint
     * normaliza el teléfono (10 dígitos CO, 57… o +57…). Llamar UNA sola vez
     * por guía (el endpoint no deduplica; nuestro guard de idempotencia lo
     * garantiza). PURO.
     */
    public static function build_webhook_payload( array $args ): array {
        $payload = array(
            'order_id'      => (string) ( $args['order_id'] ?? '' ),
            'phone'         => (string) ( $args['phone'] ?? '' ),
            'guia'          => (string) ( $args['guia'] ?? '' ),
            'tracking_url'  => (string) ( $args['tracking_url'] ?? '' ),
            'customer_name' => (string) ( $args['name'] ?? '' ),
        );
        // v2: opcionales — correo de despacho al cliente y rótulo PDF (base64)
        // que el workflow reenvía SIEMPRE al correo de la tienda para imprimir.
        if ( '' !== (string) ( $args['email'] ?? '' ) ) {
            $payload['email'] = (string) $args['email'];
        }
        if ( '' !== (string) ( $args['rotulo_b64'] ?? '' ) ) {
            $payload['rotulo_b64'] = (string) $args['rotulo_b64'];
        }
        // v3: conversación de Chatwoot donde nació la venta. Si viene, n8n responde
        // EN ESE hilo en vez de resolver el destino buscando el teléfono: los
        // contactos de Instagram/Facebook no traen número, y en el botón Venta el
        // comprador suele dictar el teléfono de quien recibe — en ambos casos el
        // aviso caía en un hilo paralelo que el asesor nunca veía.
        if ( '' !== (string) ( $args['conversation'] ?? '' ) ) {
            $payload['conversation'] = (string) $args['conversation'];
        }
        return $payload;
    }

    /**
     * Payload del aviso pickup-ask (n8n pregunta por WhatsApp si el cliente
     * prefiere envío con Coordinadora). Contrato: campos planos, total como
     * string en COP sin decimales. PURO.
     */
    public static function build_pickup_ask_payload( array $args ): array {
        return array(
            'order_id'      => (string) ( $args['order_id'] ?? '' ),
            'phone'         => (string) ( $args['phone'] ?? '' ),
            'customer_name' => (string) ( $args['name'] ?? '' ),
            'email'         => (string) ( $args['email'] ?? '' ),
            'total'         => (string) (int) round( (float) ( $args['total'] ?? 0 ) ),
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

    /** IDs de método de envío del pedido (method_id de cada shipping item). */
    private static function order_shipping_ids( $order ): array {
        $ids = array();
        foreach ( $order->get_shipping_methods() as $sm ) {
            $ids[] = (string) $sm->get_method_id();
        }
        return $ids;
    }

    /** Hook woocommerce_order_status_processing: genera la guía. */
    public static function on_processing( $order_id ): void {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            return;
        }

        $shipping_ids = self::order_shipping_ids( $order );

        $check = self::should_generate( array(
            'enabled'       => (bool) CCMCK_Settings::get( 'guias_enabled', false ),
            'usuario'       => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'         => (string) CCMCK_Settings::get( 'guias_clave', '' ),
            'shipping_ids'  => $shipping_ids,
            'existing_guia' => (string) $order->get_meta( self::META_GUIA ),
            'has_lock'      => false !== get_transient( 'ccmck_guia_lock_' . $order_id ),
        ) );
        if ( ! $check['ok'] ) {
            if ( 'pedido con recogida local' === $check['reason'] ) {
                self::maybe_pickup_ask( $order );
            }
            // Silencioso para off/duplicado/etc.; son casos normales.
            return;
        }
        set_transient( 'ccmck_guia_lock_' . $order_id, 1, 60 );

        $result = self::generate_for_order( $order, array(
            'contra_entrega' => self::ce_requested( '', $shipping_ids, (string) $order->get_meta( self::META_MODALIDAD ) ),
        ) );
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
    private static function generate_for_order( $order, array $opts = array() ): array {
        $order_id = (int) $order->get_id();
        $ce       = ! empty( $opts['contra_entrega'] );

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
            'id_cliente'   => $ce
                ? (int) CCMCK_Settings::get( 'guias_id_cliente_ce', 49445 )
                : (int) CCMCK_Settings::get( 'guias_id_cliente', 49444 ),
            'codigo_cuenta' => $ce ? (int) CCMCK_Settings::get( 'guias_cuenta_ce', 6 ) : 2,
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
        $order->add_order_note( 'Guía Coordinadora generada' . ( $ce ? ' (FLETE CONTRA ENTREGA: el cliente paga el flete al recibir)' : '' ) . ': ' . $parsed['codigo_remision'] );

        // v2: rótulo en base64 — generarGuia suele devolverlo vacío; si es así,
        // se pide con reimprimirGuia. El workflow lo manda por correo a la
        // tienda para imprimir (y al cliente si hay email).
        $rotulo = '' !== $parsed['pdf_b64'] ? $parsed['pdf_b64'] : self::fetch_label_b64( $parsed['codigo_remision'] );

        $wa = self::send_webhook( self::build_webhook_payload( array(
            'order_id'     => (string) $order->get_order_number(),
            'guia'         => $parsed['codigo_remision'],
            'tracking_url' => $parsed['tracking_url'],
            'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'phone'        => (string) $order->get_billing_phone(),
            'email'        => (string) $order->get_billing_email(),
            'rotulo_b64'   => $rotulo,
            'conversation' => (string) $order->get_meta( self::META_CONVERSACION ),
        ) ) );
        if ( is_array( $wa ) && ! empty( $wa['ok'] ) ) {
            $modo = (string) ( $wa['mode_used'] ?? '' );
            $order->add_order_note( 'Aviso de guía enviado al cliente por WhatsApp' . ( '' !== $modo ? ' (' . $modo . ')' : '' ) . '.' );
        }

        // Guía CE: cotizar (best-effort) lo que Coordinadora le cobrará al
        // destinatario, con la MISMA cuenta del acuerdo CE (6 = FCE). Verificado
        // 2026-07-22: cuentas 2 y 6 tarifan igual (sin recargo), pero se cotiza
        // con la del acuerdo por si las tarifas divergen a futuro. Un fallo aquí
        // no bloquea nada: la guía ya existe; flete_ce va null.
        $flete_ce = null;
        if ( $ce ) {
            $q = CCMCK_Coordinadora::quote( array(
                'nit'        => (string) CCMCK_Settings::get( 'coordinadora_nit', '' ),
                'cuenta'     => (int) CCMCK_Settings::get( 'guias_cuenta_ce', 6 ),
                'origen'     => (string) CCMCK_Settings::get( 'coordinadora_origin', '08001000' ),
                'destino'    => $destino,
                'valoracion' => (int) round( $total_lineas ),
                'detalle'    => $detalle,
                'apikey'     => (string) CCMCK_Settings::get( 'coordinadora_apikey', '' ),
                'clave'      => (string) CCMCK_Settings::get( 'coordinadora_clave', '' ),
            ) );
            if ( ! empty( $q['ok'] ) ) {
                $flete_ce = (int) $q['flete_total'];
                $order->add_order_note( 'Flete contra entrega estimado (lo cobra Coordinadora al recibir): $' . number_format( $flete_ce, 0, ',', '.' ) . '.' );
            }
        }

        return array( 'ok' => true, 'error' => '', 'flete_ce' => $flete_ce );
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
            echo self::generate_button_markup( $generate_url, self::has_pickup( self::order_shipping_ids( $order ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro del método.
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
    public static function generate_button_markup( string $generate_url, bool $contra_entrega = false ): string {
        if ( '' === trim( $generate_url ) ) {
            return '';
        }
        $html = '<div class="ccmck-guia-box" style="margin-top:12px;">';
        if ( $contra_entrega ) {
            $html .= '<p style="margin:0 0 6px;color:#996800;"><strong>' . esc_html__( 'Pedido de recogida local: la guía saldrá con flete CONTRA ENTREGA (el cliente paga el flete al recibir).', 'ccm-checkout' ) . '</strong></p>';
        }
        $confirm = $contra_entrega
            ? __( '¿Generar la guía CONTRA ENTREGA para este pedido? El cliente pagará el flete al recibir. Se enviará el aviso por WhatsApp.', 'ccm-checkout' )
            : __( '¿Generar la guía de Coordinadora para este pedido? Se enviará el aviso por WhatsApp al cliente.', 'ccm-checkout' );
        $html .= '<a class="button" href="' . esc_url( $generate_url ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">';
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

        $result = self::generate_for_order( $order, array(
            'contra_entrega' => self::ce_requested( '', self::order_shipping_ids( $order ), (string) $order->get_meta( self::META_MODALIDAD ) ),
        ) );
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

    /**
     * Rótulo de una guía en base64 vía Guias.reimprimirGuia (formato "1").
     * Devuelve '' si falla o el contenido no es un PDF válido.
     */
    private static function fetch_label_b64( string $guia ): string {
        $response = self::rpc( 'Guias.reimprimirGuia', array(
            'usuario'           => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'             => hash( 'sha256', (string) CCMCK_Settings::get( 'guias_clave', '' ) ),
            'codigo_remision'   => $guia,
            'formato_impresion' => '1',
        ) );
        if ( is_wp_error( $response ) ) {
            self::log( 'Rótulo guía ' . $guia . ' HTTP: ' . $response->get_error_message() );
            return '';
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $b64  = is_array( $data ) && isset( $data['result']['pdf'] ) ? (string) $data['result']['pdf'] : '';
        if ( '' === $b64 || '%PDF' !== substr( (string) base64_decode( $b64 ), 0, 4 ) ) {
            $msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'sin PDF';
            self::log( 'Rótulo guía ' . $guia . ': ' . $msg );
            return '';
        }
        return $b64;
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
        $b64 = self::fetch_label_b64( $guia );
        $pdf = '' !== $b64 ? base64_decode( $b64 ) : '';
        if ( '' === $pdf || '%PDF' !== substr( $pdf, 0, 4 ) ) {
            wp_die( esc_html__( 'Rótulo no disponible.', 'ccm-checkout' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="guia-' . rawurlencode( $guia ) . '.pdf"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario PDF.
        exit;
    }

    /**
     * Sirve el rótulo al cliente dueño del pedido.
     *
     * Registrada solo en `wp_ajax_` y nunca en `wp_ajax_nopriv_`: un anónimo
     * no llega ni al handler.
     */
    public static function ajax_customer_label(): void {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

        // `false, false`: sin morir. Con el corte por defecto, un nonce caducado
        // —cosa que pasa a las 24-48 h, al reabrir un enlace viejo— acaba en el
        // `wp_die(-1)` de WordPress: una pantalla en blanco con un "-1". Aquí se
        // trata como cualquier otro fallo y el cliente vuelve a Descargas con un
        // aviso.
        if ( ! check_ajax_referer( 'ccmck_rotulo_cliente_' . $order_id, false, false ) ) {
            self::back_to_downloads( __( 'Ese envío no está disponible.', 'ccm-checkout' ) );
        }

        $order = $order_id ? wc_get_order( $order_id ) : null;

        $check = self::customer_label_check( array(
            'logged_in'         => is_user_logged_in(),
            'user_id'           => get_current_user_id(),
            'order_found'       => (bool) $order,
            'order_customer_id' => $order ? (int) $order->get_customer_id() : 0,
            'guia'              => $order ? (string) $order->get_meta( self::META_GUIA ) : '',
        ) );

        if ( ! $check['ok'] ) {
            self::back_to_downloads( __( 'Ese envío no está disponible.', 'ccm-checkout' ) );
        }

        $guia = (string) $order->get_meta( self::META_GUIA );
        $b64  = self::label_from_cache_or( $guia, static function ( $g ) {
            return self::fetch_label_b64( (string) $g );
        } );

        $pdf = '' !== $b64 ? base64_decode( $b64 ) : '';

        if ( '' === $pdf || '%PDF' !== substr( $pdf, 0, 4 ) ) {
            self::back_to_downloads( __( 'El rótulo no está disponible ahora mismo. Inténtalo más tarde.', 'ccm-checkout' ) );
        }

        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . self::label_filename( $guia ) . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario PDF.
        exit;
    }

    /**
     * Devuelve al cliente a Descargas con un aviso, en vez de dejarlo en una
     * pantalla en blanco de admin-ajax.
     *
     * El permalink va explícito: `wc_get_endpoint_url()` llama por dentro a
     * `get_permalink()`, que necesita una página consultada, y en
     * `admin-ajax.php` no hay ninguna — sin esto la URL sale sin el `/account/`.
     */
    private static function back_to_downloads( string $mensaje ): void {
        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( $mensaje, 'error' );
        }

        wp_safe_redirect( wc_get_endpoint_url( 'downloads', '', wc_get_page_permalink( 'myaccount' ) ) );
        exit;
    }

    /**
     * Pregunta al cliente (vía n8n → WhatsApp) si prefiere envío en vez de
     * recogida. Fire-and-forget, UNA sola vez por pedido.
     */
    private static function maybe_pickup_ask( $order ): void {
        $url = (string) CCMCK_Settings::get( 'guias_pickup_ask_url', '' );
        if ( '' === $url || '' !== (string) $order->get_meta( self::META_PICKUP_ASK ) ) {
            return;
        }
        $payload  = self::build_pickup_ask_payload( array(
            'order_id' => (string) $order->get_order_number(),
            'phone'    => (string) $order->get_billing_phone(),
            'name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'email'    => (string) $order->get_billing_email(),
            'total'    => (float) $order->get_total(),
        ) );
        // AT-MOST-ONCE: se marca ANTES de enviar. Un timeout con el mensaje ya
        // entregado duplicaría la pregunta al cliente (lección del pedido
        // #33243 en n8n) — mejor fallar y avisar que preguntar dos veces.
        $order->update_meta_data( self::META_PICKUP_ASK, '1' );
        $order->save();
        $response = wp_remote_post( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $response ) ) {
            self::log( 'Pickup-ask falló (pedido #' . $payload['order_id'] . '): ' . $response->get_error_message() );
            $order->add_order_note( 'Recogida local: NO se pudo preguntar al cliente por WhatsApp (fallo de conexión con n8n). Preguntarle manualmente si prefiere envío.' );
            return;
        }
        $order->add_order_note( 'Recogida local: se le preguntó al cliente por WhatsApp si prefiere envío con Coordinadora.' );
    }

    /** Registra la ruta REST para que n8n genere la guía cuando el cliente acepta. */
    /**
     * ¿La guía debe salir con flete contra entrega? PURO.
     * CE si el body lo pide explícito (modalidad "flete_contra_entrega"), si el
     * pedido es de recogida local (comportamiento histórico del rescate de
     * pickups), o si el pedido nació marcado con la meta _ccm_flete_modalidad =
     * "contra_entrega" (botón Venta de Chatwoot: envío Coordinadora sin flete
     * cobrado — el cliente paga el flete al mensajero).
     *
     * @param string $modalidad      Valor de "modalidad" del request ('' si no vino).
     * @param array  $shipping_ids   Métodos de envío del pedido.
     * @param string $meta_modalidad Valor de la meta _ccm_flete_modalidad ('' si no existe).
     */
    public static function ce_requested( string $modalidad, array $shipping_ids, string $meta_modalidad = '' ): bool {
        return 'flete_contra_entrega' === $modalidad
            || 'contra_entrega' === $meta_modalidad
            || self::has_pickup( $shipping_ids );
    }

    /**
     * ¿La URL de entrega de un webhook de WC apunta a nuestro n8n? PURO.
     * Match estricto por host (n8n.*.hstgr.cloud) para no volver síncronos
     * webhooks de terceros.
     */
    public static function is_n8n_delivery_url( string $url ): bool {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        return (bool) preg_match( '/^n8n\.[a-z0-9-]+\.hstgr\.cloud$/i', $host );
    }

    /**
     * Entrega SÍNCRONA para los webhooks de WC hacia n8n (woo-processing).
     *
     * Por defecto WC encola la entrega en Action Scheduler, pero la cola de la
     * tienda está saturada (720K acciones fallidas históricas, runner sin cron
     * frecuente): las entregas salían con ~80-90 min de retraso, o al instante
     * solo si un admin navegaba el panel (evidencia: logs de Scheduled Actions,
     * 2026-07-21). Síncrono = sale en el mismo request del callback del pago.
     * n8n responde de inmediato (responseMode onReceived), y el timeout corto
     * de abajo evita colgar el callback del gateway si n8n no está.
     */
    public static function webhook_deliver_sync( $async, $webhook, $arg ) {
        if ( is_object( $webhook ) && method_exists( $webhook, 'get_delivery_url' )
            && self::is_n8n_delivery_url( (string) $webhook->get_delivery_url() ) ) {
            return false;
        }
        return $async;
    }

    /** Timeout corto (8 s) solo para las entregas síncronas hacia n8n. */
    public static function webhook_http_args( $args, $arg, $webhook_id ) {
        $webhook = class_exists( 'WC_Webhook' ) ? new WC_Webhook( (int) $webhook_id ) : null;
        if ( $webhook && self::is_n8n_delivery_url( (string) $webhook->get_delivery_url() ) ) {
            $args['timeout'] = 8;
        }
        return $args;
    }

    public static function register_rest_routes(): void {
        register_rest_route( 'ccmck/v1', '/generar-guia', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_generate' ),
            'permission_callback' => array( __CLASS__, 'rest_permission' ),
        ) );
        register_rest_route( 'ccmck/v1', '/barrer-guia', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_sweep' ),
            'permission_callback' => array( __CLASS__, 'rest_permission' ),
        ) );
        register_rest_route( 'ccmck/v1', '/rotulo', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_rotulo' ),
            'permission_callback' => array( __CLASS__, 'rest_permission' ),
        ) );
        register_rest_route( 'ccmck/v1', '/factura-claim', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_factura_claim' ),
            'permission_callback' => array( __CLASS__, 'rest_permission' ),
        ) );
    }

    /**
     * GET /wp-json/ccmck/v1/rotulo?order_id=N — rótulo en base64 para reenvíos
     * de notificaciones desde n8n (mismo secreto). 404 sin pedido/guía; 422 si
     * Coordinadora no entrega el PDF.
     */
    public static function rest_rotulo( $request ) {
        $order_id = absint( $request['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            return new WP_Error( 'ccmck_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
        }
        $guia = (string) $order->get_meta( self::META_GUIA );
        if ( '' === $guia ) {
            return new WP_Error( 'ccmck_no_guia', 'El pedido no tiene guía.', array( 'status' => 404 ) );
        }
        $b64 = self::fetch_label_b64( $guia );
        if ( '' === $b64 ) {
            return new WP_Error( 'ccmck_no_rotulo', 'Rótulo no disponible en Coordinadora.', array( 'status' => 422 ) );
        }
        return rest_ensure_response( array(
            'ok'         => true,
            'guia'       => $guia,
            'rotulo_b64' => $b64,
        ) );
    }

    /** Auth del endpoint: header X-CCMCK-Secret vs ajuste (comparación de tiempo constante). */
    public static function rest_permission( $request ) {
        $secret = (string) CCMCK_Settings::get( 'guias_api_secret', '' );
        $given  = (string) $request->get_header( 'x-ccmck-secret' );
        if ( '' === $secret || '' === $given || ! hash_equals( $secret, $given ) ) {
            return new WP_Error( 'ccmck_forbidden', 'Secreto inválido.', array( 'status' => 403 ) );
        }
        return true;
    }

    /**
     * POST /wp-json/ccmck/v1/factura-claim {order_id} — candado atómico anti-duplicado
     * de facturación. El webhook de WooCommerce dispara varias veces por pedido y las
     * ventas del botón de Chatwoot añaden un segundo disparo, así que "factura copy"
     * corre en paralelo en n8n. Su dedup por staticData NO es atómico entre ejecuciones
     * concurrentes (carga el snapshot al iniciar, lo guarda al terminar), de modo que
     * dos corridas solapadas crean dos facturas. Aquí el índice UNIQUE de option_name
     * garantiza que sólo el primer INSERT gana el claim; el resto recibe claimed:false
     * y salta. El claim caduca a los 3 min para permitir reintentos si la facturación
     * falló a mitad de camino (la idempotencia durable la da el meta _ccm_alegra_invoice_id).
     *
     * Respuesta: { ok:true, claimed:bool }. claimed:true → esta corrida factura; false → otra la tomó.
     * Nota: acumula una fila (autoload 'no') por pedido facturado; purgar _ccmck_fclaim_* viejos si crece.
     */
    public static function rest_factura_claim( $request ) {
        $order_id = absint( $request['order_id'] ?? 0 );
        if ( ! $order_id ) {
            return new WP_Error( 'ccmck_bad_request', 'order_id requerido.', array( 'status' => 400 ) );
        }
        global $wpdb;
        $name = '_ccmck_fclaim_' . $order_id;
        $now  = time();
        // INSERT IGNORE: el UNIQUE de option_name hace atómico el claim entre peticiones
        // concurrentes. Devuelve 1 si insertó (ganó el claim), 0 si la fila ya existía.
        $ins = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            (string) $now
        ) );
        if ( 1 === (int) $ins ) {
            return rest_ensure_response( array( 'ok' => true, 'claimed' => true ) );
        }
        // Ya existía: sólo tomar el relevo si el claim está vencido (>3 min). El UPDATE
        // condicionado a option_value = $ts (compare-and-swap) mantiene la atomicidad:
        // si dos reintentos concurrentes leen el mismo $ts, sólo uno cambia la fila.
        $ts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $name
        ) );
        if ( $now - $ts > 180 ) {
            $swapped = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) $now,
                $name,
                (string) $ts
            ) );
            if ( 1 === (int) $swapped ) {
                return rest_ensure_response( array( 'ok' => true, 'claimed' => true, 'stale_reclaim' => true ) );
            }
        }
        return rest_ensure_response( array( 'ok' => true, 'claimed' => false ) );
    }

    /**
     * POST /wp-json/ccmck/v1/generar-guia {order_id} — genera la guía con las
     * mismas reglas del botón manual (pickup → flete contra entrega).
     */
    public static function rest_generate( $request ) {
        $order_id = absint( $request['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            return new WP_Error( 'ccmck_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
        }

        $check = self::should_generate( array(
            'manual'        => true,
            'usuario'       => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'         => (string) CCMCK_Settings::get( 'guias_clave', '' ),
            'existing_guia' => (string) $order->get_meta( self::META_GUIA ),
            'has_lock'      => false !== get_transient( 'ccmck_guia_lock_' . $order_id ),
        ) );
        if ( ! $check['ok'] ) {
            $conflict = ( 'el pedido ya tiene guía' === $check['reason'] || 'generación en curso (lock)' === $check['reason'] );
            return new WP_Error( 'ccmck_blocked', $check['reason'], array( 'status' => $conflict ? 409 : 422 ) );
        }
        set_transient( 'ccmck_guia_lock_' . $order_id, 1, 60 );

        $ce = self::ce_requested(
            sanitize_text_field( (string) ( $request['modalidad'] ?? '' ) ),
            self::order_shipping_ids( $order ),
            (string) $order->get_meta( self::META_MODALIDAD )
        );
        $order->add_order_note( $ce && ! self::has_pickup( self::order_shipping_ids( $order ) )
            ? 'Guía solicitada vía API con flete contra entrega (modalidad explícita).'
            : 'Cliente pidió cambio a envío con Coordinadora vía WhatsApp.' );

        $result = self::generate_for_order( $order, array( 'contra_entrega' => $ce ) );
        if ( ! $result['ok'] ) {
            delete_transient( 'ccmck_guia_lock_' . $order_id );
            return new WP_Error( 'ccmck_failed', $result['error'], array( 'status' => 422 ) );
        }

        return rest_ensure_response( array(
            'ok'           => true,
            'guia'         => (string) $order->get_meta( self::META_GUIA ),
            'tracking_url' => (string) $order->get_meta( self::META_URL ),
            'modalidad'    => $ce ? 'flete_contra_entrega' : 'prepago',
            'flete_ce'     => $result['flete_ce'] ?? null,
        ) );
    }

    /**
     * POST /wp-json/ccmck/v1/barrer-guia {order_id} — reintento del barredor.
     *
     * Separado de `/generar-guia` a propósito: aquel pasa `manual:true` y se
     * salta el guard de transportadora, cosa que un proceso automático no debe
     * hacer nunca. Aquí el guard se respeta.
     *
     * Idempotente: si el pedido ya tiene guía responde `skipped` sin tocar nada.
     */
    public static function rest_sweep( $request ) {
        $order_id = absint( $request['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            return new WP_Error( 'ccmck_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
        }

        $pagado   = $order->get_date_paid();
        $creado   = $order->get_date_created();
        $minutos  = self::sweep_minutos(
            $pagado ? $pagado->getTimestamp() : null,
            $creado ? $creado->getTimestamp() : null,
            time()
        );
        $intentos = (int) $order->get_meta( self::META_INTENTOS );

        $check = self::sweep_decision( array(
            'status'        => (string) $order->get_status(),
            'shipping_ids'  => self::order_shipping_ids( $order ),
            'existing_guia' => (string) $order->get_meta( self::META_GUIA ),
            'minutos'       => $minutos,
            'intentos'      => $intentos,
        ) );

        if ( ! $check['ok'] ) {
            return rest_ensure_response( array(
                'ok'       => false,
                'skipped'  => true,
                'reason'   => $check['reason'],
                'intentos' => $intentos,
            ) );
        }

        // Mismo candado que on_processing()/ajax_generate()/rest_generate(): si otra
        // vía está generando la guía de este pedido ahora mismo (típico: el barredor
        // lo alcanza justo porque algo falló, y a la vez un asesor pulsa "generar
        // guía" en el admin), el barredor se aparta en vez de competir por el mismo
        // envío — dos llamadas simultáneas ya produjeron dos guías para el mismo
        // pedido, que hubo que anular a mano en el portal de Coordinadora. Se
        // consulta DESPUÉS de sweep_decision() y ANTES de subir el contador: un
        // candado ajeno no es un intento fallido de este pedido, es que ya lo está
        // atendiendo otra vía; el pedido vuelve en la siguiente pasada del cron,
        // cuando el candado (60 s) ya se habrá liberado.
        if ( false !== get_transient( 'ccmck_guia_lock_' . $order_id ) ) {
            return rest_ensure_response( array(
                'ok'       => false,
                'skipped'  => true,
                'reason'   => 'generación en curso (lock)',
                'intentos' => $intentos,
            ) );
        }
        set_transient( 'ccmck_guia_lock_' . $order_id, 1, 60 );

        // El contador sube ANTES de intentar: si la petición muere a mitad —que es
        // justamente el fallo que este barredor cubre— el intento igual queda contado
        // y el pedido no se reintenta para siempre.
        $intentos++;
        $order->update_meta_data( self::META_INTENTOS, (string) $intentos );
        $order->save();

        $ce     = self::ce_requested( '', self::order_shipping_ids( $order ), (string) $order->get_meta( self::META_MODALIDAD ) );
        $result = self::generate_for_order( $order, array( 'contra_entrega' => $ce ) );

        if ( ! $result['ok'] ) {
            delete_transient( 'ccmck_guia_lock_' . $order_id );
            $order->add_order_note( sprintf(
                'Barredor de guías: intento %d de %d falló (%s).',
                $intentos,
                self::SWEEP_MAX_INTENTOS,
                $result['error']
            ) );
            return new WP_Error( 'ccmck_failed', $result['error'], array( 'status' => 422 ) );
        }

        $order->add_order_note( sprintf(
            'Guía generada por el barredor en el intento %d (el camino automático no la había creado).',
            $intentos
        ) );

        return rest_ensure_response( array(
            'ok'       => true,
            'guia'     => (string) $order->get_meta( self::META_GUIA ),
            'intentos' => $intentos,
        ) );
    }

    public static function init(): void {
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_processing' ), 20 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin' ) );
        add_action( 'wp_ajax_ccmck_guia_label', array( __CLASS__, 'ajax_label' ) );
        add_action( 'wp_ajax_ccmck_guia_generate', array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_ccmck_rotulo_cliente', array( __CLASS__, 'ajax_customer_label' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        // Prio 10: corre antes de woocommerce_checkout_update_order_meta, donde
        // el plugin de ciudades recorta el DANE de la ciudad.
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'capture_checkout_dane' ), 10, 2 );
        // Webhooks WC hacia n8n: entrega síncrona (la cola de Action Scheduler
        // los retrasaba ~80-90 min) con timeout corto.
        add_filter( 'woocommerce_webhook_deliver_async', array( __CLASS__, 'webhook_deliver_sync' ), 10, 3 );
        add_filter( 'woocommerce_webhook_http_args', array( __CLASS__, 'webhook_http_args' ), 10, 3 );
    }
}
