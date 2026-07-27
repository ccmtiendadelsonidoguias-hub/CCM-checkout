<?php
defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/ccmck/v1/cotizar — cotización de flete Coordinadora en tiempo
 * real para pedidos creados fuera del checkout (botón "💰 Venta" de Chatwoot
 * vía n8n cwVentaApi01). Reutiliza el mismo empacado por cajas y el mismo
 * Cotizador.cotizar del checkout (CCMCK_Coordinadora), para que el flete del
 * chat sea idéntico al del sitio. Autenticación: header X-CCMCK-Secret
 * (CCMCK_Guias::rest_permission, mismo secreto que /generar-guia).
 *
 * Contrato (para n8n):
 *   Request : { items: [{product_id, qty}], city?, state?, dane?, valoracion? }
 *             city acepta el formato del dropdown "NOMBRE (ABREV) (DANE)" o el
 *             nombre a secas si viene state; dane manda si viene explícito.
 *   200     : { ok: true, flete: 8040, dias: 1, dane: "08001000" }
 *   400/422 : { code, message } (WP_Error) — 400 request malformado; 422
 *             producto sin peso/medidas, ciudad sin DANE o fallo del cotizador.
 */
final class CCMCK_Cotizar {

    /**
     * Normaliza y valida el body del request. PURO.
     *
     * @param array $body Body JSON decodificado.
     * @return array{ok:bool, error:string, items:array<int,array{product_id:int,qty:int}>, city:string, state:string, dane:string, valoracion:int}
     */
    public static function parse_request( array $body ): array {
        $out = array( 'ok' => false, 'error' => '', 'items' => array(), 'city' => '', 'state' => '', 'dane' => '', 'valoracion' => 0 );

        $raw = $body['items'] ?? null;
        if ( ! is_array( $raw ) || ! $raw ) {
            $out['error'] = 'items requerido: [{product_id, qty}]';
            return $out;
        }
        foreach ( $raw as $i ) {
            $pid = (int) ( is_array( $i ) ? ( $i['product_id'] ?? 0 ) : 0 );
            $qty = (int) ( is_array( $i ) ? ( $i['qty'] ?? 0 ) : 0 );
            if ( $pid <= 0 || $qty <= 0 ) {
                $out['error'] = 'item inválido: product_id y qty deben ser > 0';
                return $out;
            }
            $out['items'][] = array( 'product_id' => $pid, 'qty' => $qty );
        }

        $out['city']       = trim( (string) ( $body['city'] ?? '' ) );
        $out['state']      = trim( (string) ( $body['state'] ?? '' ) );
        $out['dane']       = trim( (string) ( $body['dane'] ?? '' ) );
        $out['valoracion'] = max( 0, (int) ( $body['valoracion'] ?? 0 ) );
        $out['ok']         = true;
        return $out;
    }

    /**
     * Normaliza un nombre de municipio para comparar: quita el sufijo "(ABREV)",
     * los acentos, la puntuación, pasa a mayúsculas y colapsa espacios. PURO.
     */
    public static function normalize_city( string $s ): string {
        $s    = (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', trim( $s ) );
        $from = array( 'Á', 'À', 'Ä', 'Â', 'á', 'à', 'ä', 'â', 'É', 'È', 'Ë', 'Ê', 'é', 'è', 'ë', 'ê', 'Í', 'Ì', 'Ï', 'Î', 'í', 'ì', 'ï', 'î', 'Ó', 'Ò', 'Ö', 'Ô', 'ó', 'ò', 'ö', 'ô', 'Ú', 'Ù', 'Ü', 'Û', 'ú', 'ù', 'ü', 'û', 'Ñ', 'ñ' );
        $to   = array( 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'U', 'N', 'N' );
        $s    = str_replace( $from, $to, $s );
        $s    = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
        $s    = (string) preg_replace( '/[^A-Z0-9 ]+/', ' ', $s );
        return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
    }

    /**
     * Formas comparables de un municipio: el nombre normalizado y, si arranca con
     * un prefijo del tipo "SANTIAGO DE …", también el nombre sin ese prefijo — el
     * catálogo de Coordinadora guarda "TOLU" y el asesor escribe el nombre oficial
     * "Santiago de Tolú". Se recortan sólo prefijos "X DE " (no "EL"/"LA", que
     * generarían colisiones); el llamador exige coincidencia única. PURO.
     *
     * @return string[]
     */
    public static function city_candidates( string $s ): array {
        $n = self::normalize_city( $s );
        if ( '' === $n ) {
            return array();
        }
        $out = array( $n );
        if ( preg_match( '/^(?:SANTIAGO|SAN JOSE|SAN JUAN|SAN PEDRO|SANTA CRUZ|VILLA|CIUDAD) DE (.+)$/', $n, $m ) ) {
            $out[] = trim( $m[1] );
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Resuelve el código DANE destino. Precedencia: dane explícito (8 dígitos)
     * > DANE embebido en city ("NOMBRE (ABREV) (DANE)") > lookup por nombre
     * dentro del departamento (el popup de Venta manda el nombre a secas).
     * Sin departamento NO hay lookup por nombre: los nombres se repiten entre
     * departamentos. PURO.
     *
     * @param array  $catalog Departamento => (código DANE => "NOMBRE (ABREV)").
     * @param string $dane    Código explícito del request.
     * @param string $city    Ciudad como venga (value del dropdown o nombre).
     * @param string $state   Departamento (para el lookup por nombre).
     */
    public static function resolve_dane( array $catalog, string $dane, string $city, string $state ): string {
        if ( preg_match( '/^\d{8}$/', $dane ) ) {
            return $dane;
        }
        $from_city = CCMCK_Coordinadora::dane_from_city( $city );
        if ( '' !== $from_city ) {
            return $from_city;
        }
        $city  = trim( $city );
        $state = trim( $state );
        if ( '' === $city || '' === $state ) {
            return '';
        }
        $dept = null;
        foreach ( $catalog as $key => $cities ) {
            if ( 0 === strcasecmp( (string) $key, $state ) ) {
                $dept = (array) $cities;
                break;
            }
        }
        if ( null === $dept ) {
            return '';
        }
        // Match en dos pasadas, siempre EXACTO sobre nombres normalizados. Nunca por
        // prefijo/substring: "TOLU VIEJO" empieza por "TOLU" y son municipios distintos
        // (70823000 vs 70820000). Si hay más de un candidato, se devuelve '' antes que
        // adivinar un destino equivocado.
        $n_city = self::normalize_city( $city );
        $want   = self::city_candidates( $city );
        $exact  = array();
        $loose  = array();
        foreach ( $dept as $code => $label ) {
            $n_label = self::normalize_city( (string) $label );
            if ( '' !== $n_city && $n_city === $n_label ) {
                $exact[] = (string) $code;
                continue;
            }
            if ( array_intersect( $want, self::city_candidates( (string) $label ) ) ) {
                $loose[] = (string) $code;
            }
        }
        if ( 1 === count( $exact ) ) {
            return $exact[0];
        }
        if ( ! $exact && 1 === count( $loose ) ) {
            return $loose[0];
        }
        return '';
    }

    /**
     * Verifica que todos los ítems tengan peso y dimensiones (> 0). Devuelve ''
     * si todo bien, o la lista de SKUs con datos faltantes. PURO.
     *
     * @param array $items Forma de CCMCK_Coordinadora::pack() + sku.
     */
    public static function validate_products( array $items ): string {
        $missing = array();
        foreach ( $items as $it ) {
            $w = (float) ( $it['weight'] ?? 0 );
            $l = (float) ( $it['largo'] ?? 0 );
            $a = (float) ( $it['ancho'] ?? 0 );
            $h = (float) ( $it['alto'] ?? 0 );
            if ( $w <= 0 || $l <= 0 || $a <= 0 || $h <= 0 ) {
                $missing[] = (string) ( $it['sku'] ?? '?' );
            }
        }
        return implode( ', ', $missing );
    }

    /**
     * Mapea la respuesta del cotizador al payload del endpoint. PURO.
     *
     * @param array  $quote Forma de CCMCK_Coordinadora::parse_response().
     * @param string $dane  DANE destino resuelto.
     */
    public static function build_result( array $quote, string $dane ): array {
        if ( empty( $quote['ok'] ) ) {
            return array( 'ok' => false, 'error' => (string) ( $quote['error'] ?? 'cotización fallida' ) );
        }
        return array(
            'ok'    => true,
            'flete' => (int) ( $quote['flete_total'] ?? 0 ),
            'dias'  => (int) ( $quote['dias'] ?? 0 ),
            'dane'  => $dane,
        );
    }

    /** Handler REST. Delgado: junta datos de WC/ajustes y delega en los puros. */
    public static function rest_cotizar( $request ) {
        $body   = $request->get_json_params();
        $parsed = self::parse_request( is_array( $body ) ? $body : array() );
        if ( ! $parsed['ok'] ) {
            return new WP_Error( 'ccmck_bad_request', $parsed['error'], array( 'status' => 400 ) );
        }

        $apikey = (string) CCMCK_Settings::get( 'coordinadora_apikey', '' );
        $clave  = (string) CCMCK_Settings::get( 'coordinadora_clave', '' );
        if ( '' === $apikey || '' === $clave ) {
            return new WP_Error( 'ccmck_no_creds', 'Faltan credenciales del cotizador de Coordinadora.', array( 'status' => 422 ) );
        }

        $destino = self::resolve_dane( CCMCK_Cities::catalog(), $parsed['dane'], $parsed['city'], $parsed['state'] );
        if ( '' === $destino ) {
            return new WP_Error( 'ccmck_no_dane', 'No se pudo resolver el código DANE de la ciudad destino.', array( 'status' => 422 ) );
        }

        $items      = array();
        $valoracion = $parsed['valoracion'];
        $subtotal   = 0.0;
        foreach ( $parsed['items'] as $line ) {
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $line['product_id'] ) : null;
            if ( ! $product ) {
                return new WP_Error( 'ccmck_product_not_found', 'Producto no encontrado: ' . $line['product_id'], array( 'status' => 422 ) );
            }
            $items[]   = array(
                'qty'     => $line['qty'],
                'weight'  => (float) $product->get_weight(),
                'largo'   => (float) $product->get_length(),
                'ancho'   => (float) $product->get_width(),
                'alto'    => (float) $product->get_height(),
                'cat_ids' => function_exists( 'wc_get_product_cat_ids' ) ? array_map( 'intval', (array) wc_get_product_cat_ids( $product->get_id() ) ) : array(),
                'sku'     => (string) ( $product->get_sku() ? $product->get_sku() : $product->get_id() ),
            );
            $subtotal += (float) $product->get_price() * $line['qty'];
        }

        $missing = self::validate_products( $items );
        if ( '' !== $missing ) {
            return new WP_Error( 'ccmck_missing_dims', 'Producto sin peso/medidas: ' . $missing, array( 'status' => 422 ) );
        }
        if ( 0 === $valoracion ) {
            $valoracion = (int) round( $subtotal );
        }

        $threshold = (float) CCMCK_Settings::get( 'coordinadora_weight_threshold', 5.0 );
        $boxes     = CCMCK_Coordinadora::pack( $items, $threshold, CCMCK_Coordinadora::rules_map() );

        $quote = CCMCK_Coordinadora::quote( array(
            'nit'        => (string) CCMCK_Settings::get( 'coordinadora_nit', '' ),
            'origen'     => (string) CCMCK_Settings::get( 'coordinadora_origin', '08001000' ),
            'destino'    => $destino,
            'valoracion' => $valoracion,
            'detalle'    => CCMCK_Coordinadora::build_detalle( $boxes ),
            'apikey'     => $apikey,
            'clave'      => $clave,
        ) );

        $result = self::build_result( $quote, $destino );
        if ( ! $result['ok'] ) {
            return new WP_Error( 'ccmck_quote_failed', $result['error'], array( 'status' => 422 ) );
        }
        return rest_ensure_response( $result );
    }

    public static function register_rest_routes(): void {
        register_rest_route( 'ccmck/v1', '/cotizar', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_cotizar' ),
            'permission_callback' => array( 'CCMCK_Guias', 'rest_permission' ),
        ) );
    }

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }
}
