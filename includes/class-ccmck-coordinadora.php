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
                'cuenta'         => 2,
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
}
