<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cotización directa del flete de Coordinadora (Cotizador.cotizar) agrupando el
 * carrito en cajas según reglas por tipo de producto. Reemplaza la tarifa del
 * plugin de terceros (coordinadora:N) por la propia; si la API falla, la deja
 * intacta como fallback. La mayoría de métodos son PUROS y testeables sin WC.
 */
final class CCMCK_Coordinadora {
    const RATE_ID = 'ccmck_coordinadora';
    const LABEL   = 'Coordinadora';

    /** Extrae el DANE de 8 dígitos de 'CIUDAD (DEP) (05001000)' o '05001000'. PURO. */
    public static function dane_from_city( string $city ): string {
        return preg_match( '/(\d{8})\D*$/', $city, $m ) ? $m[1] : '';
    }

    /**
     * Clasifica una línea del carrito. Precedencia: regla de categoría > peso.
     * PURO.
     *
     * @param array          $item      {cat_ids:int[], weight:float}
     * @param float          $threshold Umbral de "pesado" (kg).
     * @param array<int,int> $rules     cat_id => unidades por caja.
     * @return array{kind:string, units_per_box:int}
     */
    public static function classify_item( array $item, float $threshold, array $rules ): array {
        $cat_ids = array_map( 'intval', (array) ( $item['cat_ids'] ?? array() ) );
        foreach ( $cat_ids as $cid ) {
            if ( isset( $rules[ $cid ] ) ) {
                return array( 'kind' => 'rule', 'units_per_box' => max( 1, (int) $rules[ $cid ] ) );
            }
        }
        $weight = (float) ( $item['weight'] ?? 0 );
        if ( $weight >= $threshold ) {
            return array( 'kind' => 'heavy', 'units_per_box' => 1 );
        }
        return array( 'kind' => 'small', 'units_per_box' => 0 );
    }

    /**
     * Apila unidades en un bulto: footprint = máximo, alto y peso = suma. Para
     * unidades idénticas el volumen escala lineal con la cantidad. PURO.
     *
     * @param array $units Lista de {largo,ancho,alto,peso}.
     * @return array{largo:float,ancho:float,alto:float,peso:float}
     */
    public static function stack_box( array $units ): array {
        $largo = 0.0; $ancho = 0.0; $alto = 0.0; $peso = 0.0;
        foreach ( $units as $u ) {
            $largo  = max( $largo, (float) ( $u['largo'] ?? 0 ) );
            $ancho  = max( $ancho, (float) ( $u['ancho'] ?? 0 ) );
            $alto  += (float) ( $u['alto'] ?? 0 );
            $peso  += (float) ( $u['peso'] ?? 0 );
        }
        return array( 'largo' => $largo, 'ancho' => $ancho, 'alto' => $alto, 'peso' => $peso );
    }

    /**
     * Agrupa cajas idénticas (dims + peso redondeados) en entradas detalle[] con
     * su conteo en `unidades`. PURO.
     *
     * @param array $boxes Lista de {largo,ancho,alto,peso}.
     * @return array<int,array{ubl:int,alto:float,ancho:float,largo:float,peso:float,unidades:int}>
     */
    public static function build_detalle( array $boxes ): array {
        $groups = array();
        foreach ( $boxes as $b ) {
            $largo = round( (float) ( $b['largo'] ?? 0 ), 2 );
            $ancho = round( (float) ( $b['ancho'] ?? 0 ), 2 );
            $alto  = round( (float) ( $b['alto'] ?? 0 ), 2 );
            $peso  = round( (float) ( $b['peso'] ?? 0 ), 3 );
            $key   = $largo . 'x' . $ancho . 'x' . $alto . 'x' . $peso;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'ubl' => 0, 'alto' => $alto, 'ancho' => $ancho,
                    'largo' => $largo, 'peso' => $peso, 'unidades' => 0,
                );
            }
            $groups[ $key ]['unidades']++;
        }
        return array_values( $groups );
    }

    /**
     * Reparte el carrito en cajas. rule/heavy → ceil(qty/N) cajas homogéneas de
     * hasta N unidades del mismo producto; small → una caja consolidada con todas
     * las unidades <umbral (de todos los productos). PURO.
     *
     * @param array          $items     Lista {qty,weight,largo,ancho,alto,cat_ids}.
     * @param float          $threshold Umbral de "pesado" (kg).
     * @param array<int,int> $rules     cat_id => unidades por caja.
     * @return array<int,array{largo:float,ancho:float,alto:float,peso:float}>
     */
    public static function pack( array $items, float $threshold, array $rules ): array {
        $boxes       = array();
        $small_units = array();

        foreach ( $items as $item ) {
            $qty = max( 0, (int) ( $item['qty'] ?? 0 ) );
            if ( $qty <= 0 ) {
                continue;
            }
            $unit = array(
                'largo' => (float) ( $item['largo'] ?? 0 ),
                'ancho' => (float) ( $item['ancho'] ?? 0 ),
                'alto'  => (float) ( $item['alto'] ?? 0 ),
                'peso'  => (float) ( $item['weight'] ?? 0 ),
            );
            $c = self::classify_item( $item, $threshold, $rules );

            if ( 'small' === $c['kind'] ) {
                for ( $i = 0; $i < $qty; $i++ ) {
                    $small_units[] = $unit;
                }
                continue;
            }

            $n         = max( 1, (int) $c['units_per_box'] );
            $remaining = $qty;
            while ( $remaining > 0 ) {
                $in_box     = min( $n, $remaining );
                $boxes[]    = self::stack_box( array_fill( 0, $in_box, $unit ) );
                $remaining -= $in_box;
            }
        }

        if ( $small_units ) {
            $boxes[] = self::stack_box( $small_units );
        }
        return $boxes;
    }

    /**
     * Arma el body JSON-RPC de Cotizador.cotizar. Campos fijos verificados:
     * div="01", cuenta=2, producto=0, nivel_servicio=[{item:1}]. PURO.
     *
     * @param array $args {nit,origen,destino,valoracion,detalle,apikey,clave}
     */
    public static function build_request( array $args ): array {
        return array(
            'jsonrpc' => '2.0',
            'id'      => 0,
            'method'  => 'Cotizador.cotizar',
            'params'  => array(
                'nit'            => (string) ( $args['nit'] ?? '' ),
                'div'            => '01',
                'cuenta'         => (int) ( $args['cuenta'] ?? 2 ),
                'producto'       => 0,
                'origen'         => (string) ( $args['origen'] ?? '' ),
                'destino'        => (string) ( $args['destino'] ?? '' ),
                'valoracion'     => (int) ( $args['valoracion'] ?? 0 ),
                'nivel_servicio' => array( array( 'item' => 1 ) ),
                'detalle'        => array_values( (array) ( $args['detalle'] ?? array() ) ),
                'apikey'         => (string) ( $args['apikey'] ?? '' ),
                'clave'          => (string) ( $args['clave'] ?? '' ),
            ),
        );
    }

    /**
     * Parsea la respuesta. HTTP siempre 200: falló si el body no es JSON o si
     * error !== null. PURO.
     *
     * @return array{ok:bool, flete_total:int, dias:int, error:string}
     */
    public static function parse_response( string $body, $http_code ): array {
        $fail = array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => '' );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            $fail['error'] = 'Respuesta no-JSON de Coordinadora (HTTP ' . (int) $http_code . ')';
            return $fail;
        }
        if ( isset( $data['error'] ) && null !== $data['error'] ) {
            $msg = is_array( $data['error'] ) ? ( $data['error']['message'] ?? 'error' ) : (string) $data['error'];
            $fail['error'] = (string) $msg;
            return $fail;
        }
        $result = $data['result'] ?? null;
        if ( ! is_array( $result ) || ! isset( $result['flete_total'] ) ) {
            $fail['error'] = 'Respuesta sin flete_total';
            return $fail;
        }
        return array(
            'ok'          => true,
            'flete_total' => (int) $result['flete_total'],
            'dias'        => (int) ( $result['dias_entrega'] ?? 0 ),
            'error'       => '',
        );
    }

    /**
     * Aplica la cotización a las tarifas del paquete: si ok, quita la tarifa del
     * plugin viejo (coordinadora*) y añade la propia con los días en meta; si no,
     * devuelve las tarifas intactas (fallback). PURO (salvo el new WC_Shipping_Rate).
     *
     * @param array $rates rate_id => WC_Shipping_Rate
     * @param array $quote Salida de parse_response/quote.
     */
    public static function apply_quote( array $rates, array $quote ): array {
        if ( empty( $quote['ok'] ) || ! class_exists( 'WC_Shipping_Rate' ) ) {
            return $rates;
        }
        foreach ( array_keys( $rates ) as $id ) {
            if ( 0 === strpos( (string) $id, 'coordinadora' ) ) {
                unset( $rates[ $id ] );
            }
        }
        $rate = new WC_Shipping_Rate( self::RATE_ID, self::LABEL, (float) $quote['flete_total'], array(), self::RATE_ID );
        if ( ! empty( $quote['dias'] ) && method_exists( $rate, 'add_meta_data' ) ) {
            $rate->add_meta_data( 'dias_entrega', (int) $quote['dias'] );
        }
        $rates[ self::RATE_ID ] = $rate;
        return $rates;
    }

    /** Log al canal de WooCommerce. */
    private static function log( string $msg ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning( $msg, array( 'source' => 'ccmck-coordinadora' ) );
        }
    }

    /** Reglas de caja como mapa cat_id => N desde los ajustes. Público: lo reúsa el endpoint /cotizar. */
    public static function rules_map(): array {
        $rows = (array) CCMCK_Settings::get( 'coordinadora_box_rules', array() );
        $map  = array();
        foreach ( $rows as $row ) {
            $cat = (int) ( $row['cat'] ?? 0 );
            $n   = (int) ( $row['n'] ?? 0 );
            if ( $cat > 0 && $n > 0 ) {
                $map[ $cat ] = $n;
            }
        }
        return $map;
    }

    /** Normaliza $package['contents'] a la forma que consume pack(). */
    private static function items_from_package( array $package ): array {
        $items    = array();
        $contents = ( isset( $package['contents'] ) && is_array( $package['contents'] ) ) ? $package['contents'] : array();
        foreach ( $contents as $line ) {
            $product = ( isset( $line['data'] ) && is_object( $line['data'] ) ) ? $line['data'] : null;
            if ( ! $product ) {
                continue;
            }
            $id      = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
            $cat_ids = ( $id && function_exists( 'wc_get_product_cat_ids' ) ) ? (array) wc_get_product_cat_ids( $id ) : array();
            $items[] = array(
                'qty'     => (int) ( $line['quantity'] ?? 0 ),
                'weight'  => (float) ( method_exists( $product, 'get_weight' ) ? $product->get_weight() : 0 ),
                'largo'   => (float) ( method_exists( $product, 'get_length' ) ? $product->get_length() : 0 ),
                'ancho'   => (float) ( method_exists( $product, 'get_width' )  ? $product->get_width()  : 0 ),
                'alto'    => (float) ( method_exists( $product, 'get_height' ) ? $product->get_height() : 0 ),
                'cat_ids' => array_map( 'intval', $cat_ids ),
            );
        }
        return $items;
    }

    /** Valor declarado = subtotal del paquete (COP, int). */
    private static function valoracion_from_package( array $package ): int {
        $total    = 0.0;
        $contents = ( isset( $package['contents'] ) && is_array( $package['contents'] ) ) ? $package['contents'] : array();
        foreach ( $contents as $line ) {
            $total += (float) ( $line['line_total'] ?? 0 );
        }
        return (int) round( $total );
    }

    /**
     * Clave de caché de una cotización. PURA.
     *
     * Destino, origen, valoración, contenido, cuenta y nit: dos cotizaciones
     * con esos mismos datos comparten respuesta. Las CREDENCIALES (apikey,
     * clave) quedan FUERA a propósito — esta clave se guarda en la base de
     * datos.
     *
     * `cuenta` y `nit` SÍ entran, aunque hoy tarifen igual: CCMCK_Guias cotiza
     * la guía CE con `guias_cuenta_ce` (6, ver class-ccmck-guias.php) y el
     * carrito/checkout con la cuenta por defecto (2) — verificado que ambas
     * tarifan igual, pero justamente por si eso diverge algún día (ver
     * comentario en class-ccmck-guias.php). Sin `cuenta` en la clave, una
     * caché compartida entre las dos anularía esa previsión en silencio: el
     * carrito podría servir una tarifa cacheada con la cuenta 6, o la guía CE
     * una con la 2, sin que nada lo avise.
     */
    public static function cache_key( array $args ): string {
        $material = array(
            'origen'     => (string) ( $args['origen'] ?? '' ),
            'destino'    => (string) ( $args['destino'] ?? '' ),
            'valoracion' => (int) ( $args['valoracion'] ?? 0 ),
            'detalle'    => $args['detalle'] ?? array(),
            'cuenta'     => (int) ( $args['cuenta'] ?? 2 ),
            'nit'        => (string) ( $args['nit'] ?? '' ),
        );
        return 'ccmck_cot_' . md5( (string) wp_json_encode( $material ) );
    }

    /** Borra las cotizaciones cacheadas. Cambiar tarifas o credenciales las invalida. */
    public static function purge_cache(): void {
        global $wpdb;
        // Supuesto verificado (no algo que el revisor pudiera ver en el codigo):
        // ni el VPS ni el entorno local tienen cache de objetos persistente
        // (sin object-cache.php como drop-in, sin plugin Redis/Memcached), asi
        // que los transients viven en wp_options y este DELETE los alcanza de
        // verdad. Si algun dia se instala un cache de objetos persistente, esta
        // purga se vuelve un no-op silencioso: esos transients dejarian de
        // pasar por wp_options.
        $like = $wpdb->esc_like( 'ccmck_cot_' ) . '%';
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_{$like}'
                OR option_name LIKE '_transient_timeout_{$like}'"
        );
    }

    /** Llama a Cotizador.cotizar (timeout 5 s). Devuelve la forma de parse_response. */
    public static function quote( array $args ): array {
        $key = self::cache_key( $args );
        $hit = get_transient( $key );
        if ( is_array( $hit ) ) {
            return $hit;
        }

        $body     = wp_json_encode( self::build_request( $args ) );
        $response = wp_remote_post( 'https://ws.coordinadora.com/ags/1.5/server.php', array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $body,
        ) );
        if ( is_wp_error( $response ) ) {
            self::log( 'HTTP: ' . $response->get_error_message() );
            $fail = array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => $response->get_error_message() );
            // Decision consciente: un corte de red breve (timeout, DNS, TLS)
            // deja de cotizar 5 minutos para TODOS los visitantes con este
            // mismo envio, a cambio de no clavarle a cada uno los 5s de
            // timeout mientras Coordinadora esta caida. Mismo TTL corto que
            // los fallos de nivel API, por la misma razon.
            set_transient( $key, $fail, 5 * MINUTE_IN_SECONDS );
            return $fail;
        }
        $parsed = self::parse_response(
            (string) wp_remote_retrieve_body( $response ),
            wp_remote_retrieve_response_code( $response )
        );
        if ( ! $parsed['ok'] ) {
            self::log( 'API: ' . $parsed['error'] );
        }

        // Los fallos caducan en minutos: cachear un error de la API medio día
        // es clavarlo.
        set_transient( $key, $parsed, $parsed['ok'] ? 12 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
        return $parsed;
    }

    /**
     * Filtro woocommerce_package_rates: cotiza y reemplaza la tarifa de
     * Coordinadora. Aborta (devuelve $rates intacto) si el toggle está off, faltan
     * credenciales, algún producto no tiene peso/dimensiones, o no hay DANE destino.
     */
    public static function rates( $rates, $package = array() ): array {
        $rates = is_array( $rates ) ? $rates : array();
        if ( ! CCMCK_Settings::get( 'coordinadora_enabled', false ) ) {
            return $rates;
        }
        $apikey = (string) CCMCK_Settings::get( 'coordinadora_apikey', '' );
        $clave  = (string) CCMCK_Settings::get( 'coordinadora_clave', '' );
        if ( '' === $apikey || '' === $clave ) {
            return $rates;
        }
        $package = is_array( $package ) ? $package : array();
        $items   = self::items_from_package( $package );
        if ( ! $items ) {
            return $rates;
        }
        foreach ( $items as $it ) {
            if ( $it['weight'] <= 0 || $it['largo'] <= 0 || $it['ancho'] <= 0 || $it['alto'] <= 0 ) {
                self::log( 'Producto sin peso/dimensiones; se usa el fallback del plugin viejo.' );
                return $rates;
            }
        }
        $destino = self::dane_from_city( (string) ( $package['destination']['city'] ?? '' ) );
        if ( '' === $destino ) {
            return $rates;
        }
        $threshold = (float) CCMCK_Settings::get( 'coordinadora_weight_threshold', 5.0 );
        $boxes     = self::pack( $items, $threshold, self::rules_map() );
        $detalle   = self::build_detalle( $boxes );

        $quote = self::quote( array(
            'nit'        => (string) CCMCK_Settings::get( 'coordinadora_nit', '' ),
            'origen'     => (string) CCMCK_Settings::get( 'coordinadora_origin', '08001000' ),
            'destino'    => $destino,
            'valoracion' => self::valoracion_from_package( $package ),
            'detalle'    => $detalle,
            'apikey'     => $apikey,
            'clave'      => $clave,
        ) );
        return self::apply_quote( $rates, $quote );
    }

    public static function init(): void {
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'rates' ), 20, 2 );
        // update_option_{$option} NO dispara en el primer guardado de la
        // opción: ahí WordPress dispara add_option_{$option} en su lugar
        // (la opción todavía no existe en wp_options). Sin este segundo
        // enganche, la primerísima vez que se guardan los ajustes (activar
        // el toggle, cargar credenciales, cambiar reglas de caja) no purga
        // nada cacheado antes de esa configuración inicial.
        add_action( 'update_option_' . CCMCK_Settings::OPTION, array( __CLASS__, 'purge_cache' ) );
        add_action( 'add_option_' . CCMCK_Settings::OPTION, array( __CLASS__, 'purge_cache' ) );
    }
}
