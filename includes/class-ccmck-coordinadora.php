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
}
