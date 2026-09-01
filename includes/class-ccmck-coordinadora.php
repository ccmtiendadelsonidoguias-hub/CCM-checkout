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

    /* =====================================================================
       PREFLIGHT Y PUENTE CONTRA LA COTIZACIÓN DUPLICADA

       Medido en dev el 1-sep-2026, con el hash del paquete invalidado, se
       llamaba DOS veces al mismo transportista en cada cálculo:

         coordinadora#0 (plugin oficial) -> appspot.com  ~843 ms de HTTP
         CCMCK_Coordinadora::rates       -> ws.coordinadora.com  ~751 ms

       Y la tarifa del oficial la borra `apply_quote()` tres líneas después.
       Serie N=12: p50 1.571 ms; interceptando solo esa llamada, p50 703 ms.

       El plugin oficial NO se desactiva: sigue siendo el respaldo cuando
       nosotros no podemos cotizar (producto sin peso o dimensiones, destino
       sin DANE). Lo que se quita es ejecutar las dos en el caso normal.
       ===================================================================== */

    /** Host y ruta EXACTOS de la cotización del plugin oficial. */
    const OFICIAL_HOST = 'wc-backend-dot-cm-integraciones.uk.r.appspot.com';
    const OFICIAL_PATH = '/api/coordinadoraWs/CalculateShipping';

    /** Acumulador de telemetría del request. Se escribe una vez, en shutdown. */
    private static array $stats = array();

    /** Memo del request: una cotización por paquete, no una por hook. */
    private static array $memo = array();

    /**
     * Bandera de ALCANCE DE REQUEST, no global.
     *
     * Solo vale `true` entre `woocommerce_before_get_rates_for_package` y
     * `woocommerce_after_get_rates_for_package` del método oficial, y solo si
     * nuestra cotización YA devolvió `ok`. Fuera de esa ventana el filtro de
     * HTTP no hace nada, así que ninguna otra llamada del sitio puede caer
     * dentro por accidente.
     */
    private static bool $dentro_del_oficial = false;

    /**
     * ¿Este paquete tiene todo lo que hace falta para que cotizemos? PURO.
     *
     * Es la ÚNICA fuente de esas reglas: `rates()` la usa, y el puente la usa
     * para decidir si puede impedir la llamada del plugin oficial. Duplicarlas
     * sería el peor fallo posible aquí — el puente bloquearía al oficial
     * creyendo que podemos cotizar cuando no, y el cliente se quedaría sin
     * flete sin que nada lo avise.
     *
     * @param array  $package Paquete de WooCommerce.
     * @param bool   $activo  Ajuste `coordinadora_enabled`.
     * @param string $apikey  Credencial.
     * @param string $clave   Credencial.
     * @return array{elegible:bool,motivo:string} Motivo vacío si es elegible.
     */
    public static function preflight( array $package, bool $activo, string $apikey, string $clave ): array {
        if ( ! $activo ) {
            return array( 'elegible' => false, 'motivo' => 'apagado' );
        }
        if ( '' === $apikey || '' === $clave ) {
            return array( 'elegible' => false, 'motivo' => 'sin_credenciales' );
        }

        $items = self::items_from_package( $package );
        if ( ! $items ) {
            return array( 'elegible' => false, 'motivo' => 'sin_contenido' );
        }

        // Peso y dimensiones se distinguen a proposito: son dos problemas de
        // catálogo distintos, con dos contadores distintos, y se arreglan por
        // vías distintas. Un solo «faltan datos» no diría a quién avisar.
        foreach ( $items as $it ) {
            if ( $it['weight'] <= 0 ) {
                return array( 'elegible' => false, 'motivo' => 'sin_peso' );
            }
            if ( $it['largo'] <= 0 || $it['ancho'] <= 0 || $it['alto'] <= 0 ) {
                return array( 'elegible' => false, 'motivo' => 'sin_dimensiones' );
            }
        }

        if ( '' === self::dane_from_city( (string) ( $package['destination']['city'] ?? '' ) ) ) {
            return array( 'elegible' => false, 'motivo' => 'sin_dane' );
        }

        return array( 'elegible' => true, 'motivo' => '' );
    }

    /** Contador que corresponde a un motivo de inelegibilidad, o null. PURO. */
    public static function contador_de_motivo( string $motivo ): ?string {
        $mapa = array(
            'sin_peso'        => 'ccmck_fallback_missing_weight',
            'sin_dimensiones' => 'ccmck_fallback_missing_dimensions',
            'sin_dane'        => 'ccmck_fallback_missing_dane',
        );
        return $mapa[ $motivo ] ?? null;
    }

    /**
     * ¿Es EXACTAMENTE la URL de cotización del plugin oficial? PURO.
     *
     * Host completo y ruta completa, no subcadenas: el mismo dominio publica
     * eventos y webhooks, y ese plugin también pide `calculateSameDayShipping`.
     * Interceptar cualquiera de esos rompería guías o sincronización de pedidos
     * sin que nada lo avise. Ante la duda se devuelve false, que deja al plugin
     * oficial trabajar como hoy.
     */
    public static function es_url_de_cotizacion_oficial( string $url ): bool {
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) ) {
            return false;
        }
        return 'https' === ( $p['scheme'] ?? '' )
            && self::OFICIAL_HOST === ( $p['host'] ?? '' )
            && self::OFICIAL_PATH === ( $p['path'] ?? '' );
    }

    /**
     * Respuesta que se le devuelve al plugin oficial en vez de dejarlo salir.
     *
     * 204 y cuerpo vacío, NO un `WP_Error`. Su código registra `is_wp_error`
     * con nivel ERROR en el log de WooCommerce: abortar así metería un error
     * falso en cada cálculo de carrito y taparía los errores de verdad. Con un
     * 204 solo escribe una línea de nivel info y devuelve false sin tarifa,
     * que es exactamente lo que se quiere.
     */
    public static function respuesta_sintetica(): array {
        return array(
            'headers'  => array(),
            'body'     => '',
            'response' => array( 'code' => 204, 'message' => 'No Content' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    /** Suma uno a un contador del request. Sin PII: solo nombres y números. */
    public static function stats_add( string $contador ): void {
        if ( '' === $contador ) {
            return;
        }
        self::$stats[ $contador ] = ( self::$stats[ $contador ] ?? 0 ) + 1;
    }

    /** Prefijo de las opciones de métrica. Una opción por día y contador. */
    const PREFIJO_METRICA = 'ccmck_m:';

    /**
     * Vuelca los contadores UNA vez por request, en `shutdown`.
     *
     * ATOMICIDAD. La versión anterior hacía `get_option` → modificar array →
     * `update_option` sobre una sola opción con todo dentro. Dos peticiones
     * simultáneas leían el mismo valor y la segunda escribía encima: se perdían
     * incrementos, y con ellos cualquier porcentaje que se quisiera calcular.
     *
     * Ahora cada contador es su propia fila y se incrementa con UNA sentencia
     * `INSERT ... ON DUPLICATE KEY UPDATE option_value = option_value + n`, que
     * MariaDB resuelve de forma atómica sobre el índice único de `option_name`.
     * Sin bloqueos, sin tabla nueva, sin transacciones: nada que revertir más
     * allá de borrar filas.
     *
     * Se sigue acumulando en memoria y volcando al final para no lanzar una
     * consulta por evento: sin caché de objetos persistente, el cálculo de
     * envío corre varias veces por petición.
     */
    public static function stats_flush(): void {
        if ( ! self::$stats ) {
            return;
        }

        global $wpdb;
        $dia = gmdate( 'Ymd' );

        foreach ( self::$stats as $contador => $n ) {
            $n = (int) $n;
            if ( $n <= 0 ) {
                continue;
            }
            $nombre = self::PREFIJO_METRICA . $dia . ':' . $contador;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- incremento atómico; `update_option` pierde escrituras concurrentes.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload )
                     VALUES ( %s, %d, 'off' )
                     ON DUPLICATE KEY UPDATE option_value = option_value + %d",
                    $nombre,
                    $n,
                    $n
                )
            );

            // La fila se escribió por SQL directo: la caché de opciones del
            // request no lo sabe y devolvería el valor viejo.
            wp_cache_delete( $nombre, 'options' );
        }

        self::$stats = array();
    }

    /**
     * Lee las métricas acumuladas, por día. Para informar, no para el flujo.
     *
     * @param int $dias Cuántos días hacia atrás.
     * @return array<string,array<string,int>> día => contador => valor
     */
    public static function stats_leer( int $dias = 14 ): array {
        global $wpdb;

        $like = $wpdb->esc_like( self::PREFIJO_METRICA ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $filas = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

        $corte = gmdate( 'Ymd', time() - $dias * DAY_IN_SECONDS );
        $out   = array();
        foreach ( (array) $filas as $f ) {
            $partes = explode( ':', (string) $f->option_name, 3 );
            if ( 3 !== count( $partes ) ) {
                continue;
            }
            if ( $partes[1] < $corte ) {
                continue;
            }
            $out[ $partes[1] ][ $partes[2] ] = (int) $f->option_value;
        }
        krsort( $out );
        return $out;
    }

    /** Borra métricas más viejas que N días. Idempotente. */
    public static function stats_podar( int $dias = 14 ): int {
        global $wpdb;
        $corte = gmdate( 'Ymd', time() - $dias * DAY_IN_SECONDS );
        $like  = $wpdb->esc_like( self::PREFIJO_METRICA ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $filas = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        $n = 0;
        foreach ( (array) $filas as $nombre ) {
            $partes = explode( ':', (string) $nombre, 3 );
            if ( 3 === count( $partes ) && $partes[1] < $corte ) {
                delete_option( $nombre );
                $n++;
            }
        }
        return $n;
    }


    /**
     * Cotiza con memo de request.
     *
     * `quote()` ya cachea en transient 12 h, pero dentro de una misma petición
     * el cálculo de envío puede correr más de una vez; el memo evita repetir
     * incluso la lectura del transient y, sobre todo, garantiza que el puente y
     * `rates()` vean EL MISMO resultado.
     */
    private static function quote_memo( array $args ): array {
        $k = self::cache_key( $args );
        if ( ! isset( self::$memo[ $k ] ) ) {
            self::$memo[ $k ] = self::quote( $args );
        }
        return self::$memo[ $k ];
    }

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
        $fail = array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => '', 'fallo' => 'api' );
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
            /*
             * Un acierto de caché NO es un evento de red y no puede contarse
             * como tal. Antes `rates()` sumaba el resultado —éxito o fallo— sin
             * saber si venía de la red o del transient, así que
             * `network_failure / eligible` no era una tasa de fallos HTTP: era
             * una mezcla. Los contadores `remote_*` de abajo son los únicos que
             * cuentan salidas de verdad.
             *
             * Un fallo recuperado del transient se cuenta como acierto de caché
             * de un fallo, no como un fallo de red nuevo.
             */
            self::stats_add( empty( $hit['ok'] ) ? 'ccmck_transient_failure_hit' : 'ccmck_transient_success_hit' );
            return $hit;
        }

        self::stats_add( 'ccmck_remote_attempt' );

        $body     = wp_json_encode( self::build_request( $args ) );
        $response = wp_remote_post( 'https://ws.coordinadora.com/ags/1.5/server.php', array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $body,
        ) );
        if ( is_wp_error( $response ) ) {
            self::log( 'HTTP: ' . $response->get_error_message() );
            // `fallo` separa red de API: son dos problemas distintos y dos
            // contadores distintos. Sin esta marca, ambos llegan como ok=false
            // y la telemetria no puede distinguir «Coordinadora esta caida» de
            // «Coordinadora dice que no puede llevar esto».
            self::stats_add( 'ccmck_remote_network_failure' );
            $fail = array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => $response->get_error_message(), 'fallo' => 'red' );
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
        self::stats_add( $parsed['ok'] ? 'ccmck_remote_success' : 'ccmck_remote_api_failure' );
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
    /**
     * Construye los argumentos de la cotización a partir del paquete. PURO
     * salvo la lectura de ajustes.
     *
     * Extraído de `rates()` para que el puente pueda pedir EXACTAMENTE la misma
     * cotización, con la misma clave de caché. Si se construyeran por separado,
     * el puente cotizaría una cosa y `rates()` otra: dos llamadas HTTP en vez
     * de ninguna, que es lo contrario de lo que se quiere.
     */
    public static function args_para( array $package ): array {
        $items     = self::items_from_package( $package );
        $threshold = (float) CCMCK_Settings::get( 'coordinadora_weight_threshold', 5.0 );
        $boxes     = self::pack( $items, $threshold, self::rules_map() );

        return array(
            'nit'        => (string) CCMCK_Settings::get( 'coordinadora_nit', '' ),
            'origen'     => (string) CCMCK_Settings::get( 'coordinadora_origin', '08001000' ),
            'destino'    => self::dane_from_city( (string) ( $package['destination']['city'] ?? '' ) ),
            'valoracion' => self::valoracion_from_package( $package ),
            'detalle'    => self::build_detalle( $boxes ),
            'apikey'     => (string) CCMCK_Settings::get( 'coordinadora_apikey', '' ),
            'clave'      => (string) CCMCK_Settings::get( 'coordinadora_clave', '' ),
        );
    }

    /**
     * ¿Dejó el plugin oficial una tarifa suya en el paquete? PURA.
     *
     * `apply_quote()` borra las tarifas `coordinadora*` cuando cotizamos, así
     * que esta comprobación solo tiene sentido ANTES de eso — o sea, sobre lo
     * que llega al filtro. Distinguir «se le dejó correr» de «entregó tarifa»
     * importa: medido en dev, con un producto sin peso el oficial corre y NO
     * deja nada, y el «respaldo» que justificaba mantenerlo no respalda.
     *
     * @param array $rates Tarifas tal como llegan al filtro.
     */
    public static function hay_tarifa_oficial( array $rates ): bool {
        foreach ( $rates as $id => $rate ) {
            if ( 0 === strpos( (string) $id, 'coordinadora' ) ) {
                return true;
            }
            $mid = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '';
            if ( '' !== $mid && 0 === strpos( $mid, 'coordinadora' ) ) {
                return true;
            }
        }
        return false;
    }

    public static function rates( $rates, $package = array() ): array {
        $rates   = is_array( $rates ) ? $rates : array();
        $package = is_array( $package ) ? $package : array();

        // Las reglas de elegibilidad viven en preflight() y solo ahí. El puente
        // usa la misma función, así que nunca puede bloquear al plugin oficial
        // creyendo que podemos cotizar cuando no.
        $pre = self::preflight(
            $package,
            (bool) CCMCK_Settings::get( 'coordinadora_enabled', false ),
            (string) CCMCK_Settings::get( 'coordinadora_apikey', '' ),
            (string) CCMCK_Settings::get( 'coordinadora_clave', '' )
        );

        if ( ! $pre['elegible'] ) {
            $contador = self::contador_de_motivo( $pre['motivo'] );
            if ( null !== $contador ) {
                // Solo cuenta como respaldo cuando el motivo es de catálogo o
                // destino. Que el módulo esté apagado o sin credenciales no es
                // un respaldo: es que no está en juego.
                self::stats_add( $contador );
                self::contar_respaldo( $rates );
                self::log( 'Sin cotizar (' . $pre['motivo'] . '); se deja correr el plugin oficial.' );
            }
            return $rates;
        }

        self::stats_add( 'ccmck_quote_eligible' );

        $quote = self::quote_memo( self::args_para( $package ) );

        /*
         * Estos dos son POR LLAMADA A rates(), no eventos de red: el resultado
         * puede venir del memo del request o del transient. Para la tasa de
         * fallos HTTP hay que usar `ccmck_remote_*`, nunca estos.
         */
        if ( ! empty( $quote['ok'] ) ) {
            self::stats_add( 'ccmck_quote_result_ok' );
        } else {
            self::stats_add( 'ccmck_quote_result_fail' );
            self::contar_respaldo( $rates );
        }

        return self::apply_quote( $rates, $quote );
    }

    /**
     * Cuenta que el oficial pudo correr Y si de verdad dejó tarifa.
     *
     * Dos contadores porque son dos hechos distintos, y confundirlos fue el
     * defecto del contador anterior: `ccmck_official_fallback_used` subía con
     * solo dejarle paso, aunque después no entregara nada.
     */
    private static function contar_respaldo( array $rates ): void {
        self::stats_add( 'ccmck_official_fallback_allowed' );
        if ( self::hay_tarifa_oficial( $rates ) ) {
            self::stats_add( 'ccmck_official_rate_present' );
        }
    }



    /* =====================================================================
       EL PUENTE

       Orden real de WooCommerce dentro de calculate_shipping_for_package():

         por cada método:  before_get_rates_for_package
                           get_rates_for_package()   <-- el oficial sale a la red
                           after_get_rates_for_package
         al final:         filtro woocommerce_package_rates  <-- aquí corre rates()

       O sea que cuando el oficial cotiza, nosotros todavía no. Por eso el
       puente adelanta NUESTRA cotización al `before` del método oficial: solo
       si esa cotización ya devolvió `ok` se impide su llamada HTTP. Si la
       nuestra falla, el oficial sale exactamente como hoy.

       Después, `rates()` reutiliza el memo del request: una cotización por
       paquete, no una por hook.
       ===================================================================== */

    /**
     * Opción PROPIA del kill switch, fuera de los ajustes generales.
     *
     * Vivía dentro de `ccmck_settings`, y esta clase engancha `purge_cache()` a
     * `update_option_ccmck_settings`: apagar el puente habría borrado TODAS las
     * cotizaciones cacheadas, o sea que el rollback del puente habría costado
     * una tanda de llamadas a Coordinadora que nadie pidió. Separada, apagarlo
     * toca solo el puente.
     */
    const OPCION_PUENTE = 'ccmck_coordinadora_puente';

    /** Kill switch. Apagado por defecto: encenderlo es un acto deliberado. */
    public static function puente_activo(): bool {
        return (bool) get_option( self::OPCION_PUENTE, false );
    }

    /**
     * Adelanta nuestra cotización justo antes de que el método oficial salga.
     *
     * @param array $package Paquete de WooCommerce.
     * @param mixed $metodo  Instancia del método de envío.
     */
    public static function antes_del_oficial( $package, $metodo ): void {
        self::$dentro_del_oficial = false;

        if ( ! self::puente_activo() ) {
            return;
        }
        if ( ! is_object( $metodo ) || 'coordinadora' !== ( $metodo->id ?? '' ) ) {
            return;
        }
        $package = is_array( $package ) ? $package : array();

        $pre = self::preflight(
            $package,
            (bool) CCMCK_Settings::get( 'coordinadora_enabled', false ),
            (string) CCMCK_Settings::get( 'coordinadora_apikey', '' ),
            (string) CCMCK_Settings::get( 'coordinadora_clave', '' )
        );
        if ( ! $pre['elegible'] ) {
            // No podemos cotizar: el oficial es el respaldo y tiene que correr.
            return;
        }

        $quote = self::quote_memo( self::args_para( $package ) );

        // SOLO si ya tenemos una cotización buena en la mano. Un fallo nuestro
        // deja pasar al oficial, que es el único punto de esta arquitectura.
        if ( ! empty( $quote['ok'] ) ) {
            self::$dentro_del_oficial = true;
        }
    }

    /** Cierra la ventana. Fuera de ella el filtro de HTTP no hace nada. */
    public static function despues_del_oficial( $package, $metodo ): void {
        self::$dentro_del_oficial = false;
    }

    /**
     * Intercepta SOLO la cotización del plugin oficial, SOLO dentro de su
     * ventana, y SOLO si ya tenemos tarifa propia.
     *
     * Tres condiciones, no una. Cualquier otra llamada del sitio —guías,
     * webhooks, same-day, o nuestra propia cotización a ws.coordinadora.com—
     * pasa intacta.
     */
    /**
     * Las dos condiciones que tienen que darse a la vez. PURA.
     *
     * Separada para poder probarla sin WordPress: es la que decide si se corta
     * una llamada de red ajena, y equivocarse aquí deja al cliente sin flete.
     *
     * @param bool   $dentro_de_la_ventana ¿Estamos entre el before y el after
     *                                    del método oficial, con cotización
     *                                    propia ya buena?
     * @param string $url                  URL que se está por pedir.
     */
    public static function debe_interceptar( bool $dentro_de_la_ventana, string $url ): bool {
        return $dentro_de_la_ventana && self::es_url_de_cotizacion_oficial( $url );
    }

    public static function intercepta_http( $pre, $args, $url ) {
        if ( ! self::debe_interceptar( self::$dentro_del_oficial, (string) $url ) ) {
            return $pre;
        }
        self::stats_add( 'ccmck_official_http_evitado' );
        return self::respuesta_sintetica();
    }

    public static function init(): void {
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'rates' ), 20, 2 );

        // El puente. Los tres enganches van juntos: sin el `after` la ventana
        // se quedaría abierta, y sin el filtro de HTTP no serviría de nada.
        add_action( 'woocommerce_before_get_rates_for_package', array( __CLASS__, 'antes_del_oficial' ), 10, 2 );
        add_action( 'woocommerce_after_get_rates_for_package', array( __CLASS__, 'despues_del_oficial' ), 10, 2 );
        add_filter( 'pre_http_request', array( __CLASS__, 'intercepta_http' ), 5, 3 );

        // Una sola escritura por petición, al final.
        add_action( 'shutdown', array( __CLASS__, 'stats_flush' ), 20 );
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
