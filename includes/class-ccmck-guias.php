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

    const ENDPOINT_PROD    = 'https://guias.coordinadora.com/ws/guias/1.6/server.php';
    const ENDPOINT_SANDBOX = 'https://sandbox.coordinadora.com/agw/ws/guias/1.6/server.php';

    /**
     * ¿Se debe generar guía para este pedido? PURO.
     *
     * @param array $ctx {enabled, usuario, clave, shipping_ids, existing_guia, has_lock}
     * @return array{ok:bool, reason:string}
     */
    public static function should_generate( array $ctx ): array {
        if ( empty( $ctx['enabled'] ) ) {
            return array( 'ok' => false, 'reason' => 'generación de guías desactivada' );
        }
        if ( '' === (string) ( $ctx['usuario'] ?? '' ) || '' === (string) ( $ctx['clave'] ?? '' ) ) {
            return array( 'ok' => false, 'reason' => 'faltan credenciales del WS de guías' );
        }
        foreach ( (array) ( $ctx['shipping_ids'] ?? array() ) as $id ) {
            if ( 0 === strpos( (string) $id, 'ccmck_local_pickup' ) ) {
                return array( 'ok' => false, 'reason' => 'pedido con recogida local' );
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
}
