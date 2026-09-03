<?php
/**
 * Informes: saca del reporte clásico las ventas hechas con el botón "Venta" del
 * bot de Chatwoot, para que WooCommerce → Informes → Ventas mida la tienda web y
 * no la gestión del asesor; y agrega una pestaña propia "Ventas del bot" con el
 * mismo gráfico de WooCommerce, apuntado sólo a esas ventas. Los pedidos siguen
 * existiendo normales (lista de pedidos, guías, Alegra); sólo cambia qué suma en
 * cada vista del informe.
 *
 * Ojo contable: con esto el informe "Ventas" deja de cuadrar con lo facturado,
 * porque las ventas por chat son ventas reales — sólo se movieron a su propia
 * pestaña, no desaparecen. Por eso además se avisa cuánto se excluyó.
 *
 * El bot marca sus pedidos con la meta _ccm_origen = chatwoot_venta al crearlos.
 * El reporte clásico (WC_Admin_Report) consulta wp_posts/wp_postmeta con el alias
 * "posts" incluso con HPOS activo (woocommerce#54732), así que el filtro va ahí.
 * Si esas tablas no tuvieran la meta, el NOT IN no excluye nada: falla abierto,
 * nunca rompe el informe.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Reports {

    const META_ORIGEN  = '_ccm_origen';
    const ORIGEN_BOT   = 'chatwoot_venta';
    /** Meta nueva (2026-09-03): 'asesor' cuando la venta la atribuye un asesor, 'bot' si la cerró el bot. */
    const META_CANAL   = '_ccm_canal_venta';
    const CANAL_ASESOR = 'asesor';
    /** Meta con el id de vendedor de Alegra que escribe el botón Venta. */
    const META_SELLER  = '_ccm_alegra_seller_id';

    const SCOPE_EXCLUDE_ALL = 'exclude_all'; // pestaña Ventas: fuera TODO lo del chat
    const SCOPE_ONLY_BOT    = 'only_bot';    // pestaña Ventas del bot
    const SCOPE_ONLY_ASESOR = 'only_asesor'; // pestaña Ventas asesores

    /**
     * Qué subconjunto cuenta el informe clásico mientras se pinta. Lo cambian
     * render_bot_report() / render_asesores_report() justo antes de delegar en
     * WC_Report_Sales_By_Date y lo devuelven a EXCLUDE_ALL al terminar, así el
     * resto de pestañas (Ventas, Clientes, Stock, Impuestos) siguen excluyendo.
     */
    private static string $scope = self::SCOPE_EXCLUDE_ALL;

    /** Con SCOPE_ONLY_ASESOR: id de vendedor Alegra a filtrar ('' = todos). Solo dígitos. */
    private static string $vendedor = '';

    /**
     * Fija scope y vendedor. Scope desconocido → EXCLUDE_ALL; vendedor se limpia a
     * dígitos para que jamás llegue nada raro al SQL (igual pasa por prepare()). PURO.
     */
    public static function set_scope( string $scope, string $vendedor = '' ): void {
        $validos        = array( self::SCOPE_EXCLUDE_ALL, self::SCOPE_ONLY_BOT, self::SCOPE_ONLY_ASESOR );
        self::$scope    = in_array( $scope, $validos, true ) ? $scope : self::SCOPE_EXCLUDE_ALL;
        self::$vendedor = preg_replace( '/\D+/', '', $vendedor );
    }

    /**
     * Rango de fechas del informe, con la misma semántica que
     * WC_Admin_Report::calculate_current_range(). PURO.
     *
     * @param string $range 'custom'|'year'|'last_month'|'month'|'7day'.
     * @param string $start Fecha inicial (sólo para 'custom').
     * @param string $end   Fecha final (sólo para 'custom').
     * @param int    $now   Timestamp "ahora" (hora local del sitio).
     * @return array{0:string,1:string} [inicio, fin] en Y-m-d.
     */
    public static function range_dates( string $range, string $start, string $end, int $now ): array {
        $hoy = gmdate( 'Y-m-d', $now );
        switch ( $range ) {
            case 'custom':
                $s = strtotime( $start );
                $e = strtotime( $end );
                if ( ! $s ) {
                    $s = strtotime( gmdate( 'Y-m-01', $now ) );
                }
                if ( ! $e ) {
                    $e = $now;
                }
                return array( gmdate( 'Y-m-d', $s ), gmdate( 'Y-m-d', $e ) );

            case 'year':
                return array( gmdate( 'Y-01-01', $now ), $hoy );

            case 'last_month':
                $primero_de_este = strtotime( gmdate( 'Y-m-01', $now ) );
                $un_dia_antes    = strtotime( '-1 day', $primero_de_este );
                return array( gmdate( 'Y-m-01', $un_dia_antes ), gmdate( 'Y-m-t', $un_dia_antes ) );

            case '7day':
                return array( gmdate( 'Y-m-d', strtotime( '-6 days', $now ) ), $hoy );

            case 'month':
            default:
                return array( gmdate( 'Y-m-01', $now ), $hoy );
        }
    }

    /** Estados que el informe cuenta como venta (mismo filtro que usa WooCommerce). */
    private static function order_statuses(): array {
        $estados = apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) );
        // Prefijo "wc-" sin ltrim(): ltrim('completed','wc-') devuelve 'ompleted',
        // porque su segundo argumento es una lista de caracteres, no un prefijo.
        return array_map(
            static function ( $s ) {
                $s = (string) $s;
                return 0 === strpos( $s, 'wc-' ) ? $s : 'wc-' . $s;
            },
            (array) $estados
        );
    }

    /**
     * IDs de pedido creados por el botón Venta (bot o asesor). Ya viene escapada
     * por $wpdb->prepare(), se inserta tal cual en otro WHERE.
     */
    public static function chat_orders_subquery(): string {
        global $wpdb;
        return $wpdb->prepare(
            "SELECT ccm_org.post_id FROM {$wpdb->postmeta} AS ccm_org
              WHERE ccm_org.meta_key = %s AND ccm_org.meta_value = %s",
            self::META_ORIGEN,
            self::ORIGEN_BOT
        );
    }

    /**
     * IDs de pedido atribuidos a un asesor (meta _ccm_canal_venta = asesor), y si
     * $vendedor no está vacío, solo los de ese vendedor de Alegra.
     */
    public static function asesor_orders_subquery( string $vendedor = '' ): string {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT ccm_can.post_id FROM {$wpdb->postmeta} AS ccm_can
              WHERE ccm_can.meta_key = %s AND ccm_can.meta_value = %s",
            self::META_CANAL,
            self::CANAL_ASESOR
        );
        $vendedor = preg_replace( '/\D+/', '', $vendedor );
        if ( '' !== $vendedor ) {
            $sql .= $wpdb->prepare(
                " AND ccm_can.post_id IN ( SELECT ccm_sel.post_id FROM {$wpdb->postmeta} AS ccm_sel
                                            WHERE ccm_sel.meta_key = %s AND ccm_sel.meta_value = %s )",
                self::META_SELLER,
                $vendedor
            );
        }
        return $sql;
    }

    /**
     * IDs del bot = pedidos del chat que NO son de asesor. Los pedidos anteriores a
     * 2026-09-03 no tienen _ccm_canal_venta y por eso cuentan como bot: es lo que
     * fueron (todos llevaban vendedor 9).
     */
    public static function bot_orders_subquery(): string {
        return self::chat_orders_subquery() . ' AND ccm_org.post_id NOT IN ( ' . self::asesor_orders_subquery() . ' )';
    }

    /**
     * Filtra el informe clásico según el scope activo. Se engancha a
     * woocommerce_reports_get_order_report_query, que recibe la query ya armada.
     *
     * @param array $query Partes SQL del informe (select/from/where/…).
     * @return array
     */
    public static function filter_report_query( $query ) {
        if ( ! is_array( $query ) ) {
            return $query;
        }
        switch ( self::$scope ) {
            case self::SCOPE_ONLY_BOT:
                $clausula = ' AND posts.ID IN ( ' . self::bot_orders_subquery() . ' ) ';
                break;
            case self::SCOPE_ONLY_ASESOR:
                $clausula = ' AND posts.ID IN ( ' . self::asesor_orders_subquery( self::$vendedor ) . ' ) ';
                break;
            default:
                $clausula = ' AND posts.ID NOT IN ( ' . self::chat_orders_subquery() . ' ) ';
        }
        $query['where'] = ( $query['where'] ?? '' ) . $clausula;
        return $query;
    }

    /**
     * Cuenta y suma los pedidos cuyo ID está en $subquery, con las mismas tablas y
     * estados que el informe, para que la cifra sea exactamente lo excluido/incluido.
     *
     * @return array{n:int, total:float}
     */
    private static function totals_for( string $subquery, string $desde, string $hasta ): array {
        global $wpdb;
        $estados      = self::order_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $estados ), '%s' ) );
        $args         = array_merge( $estados, array( $desde . ' 00:00:00', $hasta . ' 23:59:59' ) );
        $row          = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT posts.ID) AS n,
                        COALESCE( SUM( ccm_tot.meta_value + 0 ), 0 ) AS total
                   FROM {$wpdb->posts} AS posts
                   LEFT JOIN {$wpdb->postmeta} AS ccm_tot
                          ON ccm_tot.post_id = posts.ID
                         AND ccm_tot.meta_key = '_order_total'
                  WHERE posts.post_type = 'shop_order'
                    AND posts.ID IN ( {$subquery} )
                    AND posts.post_status IN ( {$placeholders} )
                    AND posts.post_date >= %s AND posts.post_date <= %s",
                $args
            ),
            ARRAY_A
        );
        return array(
            'n'     => (int) ( $row['n'] ?? 0 ),
            'total' => (float) ( $row['total'] ?? 0 ),
        );
    }

    /**
     * Totales del chat en el rango, separados: 'bot' y 'asesor'. Son disjuntos por
     * construcción (bot = chat que no es asesor), así que su suma es todo el chat.
     *
     * @return array{bot:array{n:int,total:float}, asesor:array{n:int,total:float}}
     */
    public static function chat_totals( string $desde, string $hasta ): array {
        return array(
            'bot'    => self::totals_for( self::bot_orders_subquery(), $desde, $hasta ),
            'asesor' => self::totals_for( self::asesor_orders_subquery(), $desde, $hasta ),
        );
    }

    /** Tab/report actuales del informe clásico (con los defaults de WooCommerce). */
    private static function current_tab_report(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $tab    = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'orders';
        $report = isset( $_GET['report'] ) ? sanitize_text_field( wp_unslash( $_GET['report'] ) ) : 'sales_by_date';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return array( $tab, $report );
    }

    /** ¿Estamos viendo específicamente Informes → Ventas (el reporte por fecha)? */
    private static function is_orders_sales_report(): bool {
        if ( ! is_admin() || empty( $_GET['page'] ) || 'wc-reports' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }
        list( $tab, $report ) = self::current_tab_report();
        return 'orders' === $tab && 'sales_by_date' === $report;
    }

    /**
     * Aviso con lo que se dejó fuera de "Ventas". Sólo en esa pestaña —en la
     * pestaña nueva "Ventas del bot" no tendría sentido, porque ahí SÍ son esas
     * ventas—. Se muestra siempre (aunque sean 0) para que quede claro que el
     * informe está filtrado y no haya dudas sobre si faltan pedidos.
     */
    public static function render_notice(): void {
        if ( ! self::is_orders_sales_report() ) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'month';
        $start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
        $end   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        list( $desde, $hasta ) = self::range_dates( $range, $start, $end, (int) current_time( 'timestamp' ) );
        $t = self::chat_totals( $desde, $hasta );

        $parte = static function ( string $titulo, array $tot, string $tab ): string {
            return sprintf(
                '<strong>%s</strong> %s — <a href="%s">%s</a>',
                esc_html( $titulo ),
                wp_kses_post( sprintf(
                    /* translators: 1: número de pedidos, 2: total */
                    _n( '%1$s pedido · %2$s', '%1$s pedidos · %2$s', $tot['n'], 'ccm-checkout' ),
                    number_format_i18n( $tot['n'] ),
                    wc_price( $tot['total'] )
                ) ),
                esc_url( admin_url( 'admin.php?page=wc-reports&tab=' . $tab ) ),
                esc_html__( 'verlas', 'ccm-checkout' )
            );
        };

        printf(
            '<div class="notice notice-info"><p>%s <em>(%s → %s)</em>: %s &nbsp;|&nbsp; %s</p></div>',
            esc_html__( 'Excluidas de este informe', 'ccm-checkout' ),
            esc_html( $desde ),
            esc_html( $hasta ),
            $parte( __( 'Ventas del bot:', 'ccm-checkout' ), $t['bot'], 'ccmck_bot' ),          // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $parte( __( 'Ventas asesores:', 'ccm-checkout' ), $t['asesor'], 'ccmck_asesores' )  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    /**
     * Registra la pestaña "Ventas del bot" en WooCommerce → Informes, junto a
     * Ventas/Clientes/Stock/Impuestos.
     *
     * @param array $reports Estructura de pestañas de WC_Admin_Reports.
     * @return array
     */
    public static function register_tab( $reports ) {
        if ( ! is_array( $reports ) ) {
            return $reports;
        }
        $reports['ccmck_bot'] = array(
            'title'   => __( 'Ventas del bot', 'ccm-checkout' ),
            'reports' => array(
                'main' => array(
                    'title'       => __( 'Ventas del bot', 'ccm-checkout' ),
                    'description' => '',
                    'hide_title'  => true,
                    'callback'    => array( __CLASS__, 'render_bot_report' ),
                ),
            ),
        );
        $reports['ccmck_asesores'] = array(
            'title'   => __( 'Ventas asesores', 'ccm-checkout' ),
            'reports' => array(
                'main' => array(
                    'title'       => __( 'Ventas asesores', 'ccm-checkout' ),
                    'description' => '',
                    'hide_title'  => true,
                    'callback'    => array( __CLASS__, 'render_asesores_report' ),
                ),
            ),
        );
        return $reports;
    }

    /**
     * Pinta la pestaña "Ventas del bot" reusando EXACTAMENTE el reporte "Ventas"
     * de WooCommerce (mismo gráfico, mismo selector de rango, mismo CSV) — sólo
     * que con el filtro invertido para que solo cuenten los pedidos del bot.
     */
    public static function render_bot_report(): void {
        if ( ! defined( 'WC_ABSPATH' ) ) {
            return;
        }
        $archivo = WC_ABSPATH . 'includes/admin/reports/class-wc-report-sales-by-date.php';
        if ( ! class_exists( 'WC_Report_Sales_By_Date' ) ) {
            if ( ! file_exists( $archivo ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'No se pudo cargar el reporte de ventas de WooCommerce.', 'ccm-checkout' ) . '</p></div>';
                return;
            }
            include_once $archivo;
        }
        self::set_scope( self::SCOPE_ONLY_BOT );
        $reporte = new WC_Report_Sales_By_Date();
        $reporte->output_report();
        self::set_scope( self::SCOPE_EXCLUDE_ALL );
    }

    /** Vendedor pedido por GET, limpio a dígitos ('' = todos). PURO. */
    public static function vendedor_param( array $get ): string {
        return preg_replace( '/\D+/', '', (string) ( $get['vendedor'] ?? '' ) );
    }

    /**
     * Pedidos y total por vendedor entre las ventas de asesores del rango. Mismas
     * tablas y estados que el informe. Ordenado por total desc.
     *
     * @return array<int, array{vendedor_id:string, vendedor:string, n:int, total:float}>
     */
    public static function resumen_por_vendedor( string $desde, string $hasta ): array {
        global $wpdb;
        $estados      = self::order_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $estados ), '%s' ) );
        $args         = array_merge(
            array( self::META_SELLER, '_ccm_alegra_seller_nombre' ),
            $estados,
            array( $desde . ' 00:00:00', $hasta . ' 23:59:59' )
        );
        $filas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COALESCE( ccm_sel.meta_value, '' ) AS vendedor_id,
                        COALESCE( MAX( ccm_nom.meta_value ), '' ) AS vendedor,
                        COUNT(DISTINCT posts.ID) AS n,
                        COALESCE( SUM( ccm_tot.meta_value + 0 ), 0 ) AS total
                   FROM {$wpdb->posts} AS posts
                   LEFT JOIN {$wpdb->postmeta} AS ccm_sel ON ccm_sel.post_id = posts.ID AND ccm_sel.meta_key = %s
                   LEFT JOIN {$wpdb->postmeta} AS ccm_nom ON ccm_nom.post_id = posts.ID AND ccm_nom.meta_key = %s
                   LEFT JOIN {$wpdb->postmeta} AS ccm_tot ON ccm_tot.post_id = posts.ID AND ccm_tot.meta_key = '_order_total'
                  WHERE posts.post_type = 'shop_order'
                    AND posts.ID IN ( " . self::asesor_orders_subquery() . " )
                    AND posts.post_status IN ( {$placeholders} )
                    AND posts.post_date >= %s AND posts.post_date <= %s
                  GROUP BY ccm_sel.meta_value
                  ORDER BY total DESC",
                $args
            ),
            ARRAY_A
        );
        $out = array();
        foreach ( (array) $filas as $f ) {
            $out[] = array(
                'vendedor_id' => (string) ( $f['vendedor_id'] ?? '' ),
                'vendedor'    => (string) ( $f['vendedor'] ?? '' ),
                'n'           => (int) ( $f['n'] ?? 0 ),
                'total'       => (float) ( $f['total'] ?? 0 ),
            );
        }
        usort( $out, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
        return $out;
    }

    /** Tabla resumen (vendedor · pedidos · total) con fila de suma. '' si no hay filas. PURO. */
    public static function resumen_markup( array $filas ): string {
        if ( ! $filas ) {
            return '';
        }
        $n = 0;
        $t = 0.0;
        $tr = '';
        foreach ( $filas as $f ) {
            $n  += (int) $f['n'];
            $t  += (float) $f['total'];
            $tr .= sprintf(
                '<tr><td>%s</td><td style="text-align:right">%d</td><td style="text-align:right">%s</td></tr>',
                esc_html( '' !== $f['vendedor'] ? $f['vendedor'] : ( 'id ' . $f['vendedor_id'] ) ),
                (int) $f['n'],
                wc_price( (float) $f['total'] )
            );
        }
        return '<table class="widefat striped" style="max-width:640px;margin:8px 0 16px">'
            . '<thead><tr><th>' . esc_html__( 'Vendedor', 'ccm-checkout' ) . '</th>'
            . '<th style="text-align:right">' . esc_html__( 'Pedidos', 'ccm-checkout' ) . '</th>'
            . '<th style="text-align:right">' . esc_html__( 'Total', 'ccm-checkout' ) . '</th></tr></thead>'
            . '<tbody>' . $tr . '</tbody>'
            . '<tfoot><tr><th>' . esc_html__( 'Total asesores', 'ccm-checkout' ) . '</th>'
            . '<th style="text-align:right">' . (int) $n . '</th>'
            . '<th style="text-align:right">' . wc_price( $t ) . '</th></tr></tfoot></table>';
    }

    /**
     * Formulario GET con el selector de vendedor; $hidden conserva page/tab/range/
     * fechas para que al cambiar de vendedor no se pierda el rango. PURO.
     */
    public static function vendedor_select_markup( array $filas, string $actual, array $hidden ): string {
        $h = '';
        foreach ( $hidden as $k => $v ) {
            if ( 'vendedor' === $k ) {
                continue;
            }
            $h .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $k ), esc_attr( (string) $v ) );
        }
        $opts = '<option value="">' . esc_html__( 'Todos los asesores', 'ccm-checkout' ) . '</option>';
        foreach ( $filas as $f ) {
            $id    = (string) $f['vendedor_id'];
            $opts .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $id ),
                $id === $actual ? ' selected' : '',
                esc_html( '' !== $f['vendedor'] ? $f['vendedor'] : ( 'id ' . $id ) )
            );
        }
        return '<form method="get" style="margin:12px 0">' . $h
            . '<label>' . esc_html__( 'Vendedor:', 'ccm-checkout' ) . ' <select name="vendedor" onchange="this.form.submit()">' . $opts . '</select></label>'
            . '</form>';
    }

    /**
     * Pinta "Ventas asesores": selector de vendedor + resumen + el mismo reporte
     * "Ventas" de WooCommerce restringido a canal=asesor (y al vendedor elegido).
     */
    public static function render_asesores_report(): void {
        if ( ! defined( 'WC_ABSPATH' ) ) {
            return;
        }
        $archivo = WC_ABSPATH . 'includes/admin/reports/class-wc-report-sales-by-date.php';
        if ( ! class_exists( 'WC_Report_Sales_By_Date' ) ) {
            if ( ! file_exists( $archivo ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'No se pudo cargar el reporte de ventas de WooCommerce.', 'ccm-checkout' ) . '</p></div>';
                return;
            }
            include_once $archivo;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $get      = array_map( static fn( $v ) => sanitize_text_field( wp_unslash( (string) $v ) ), $_GET );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $vendedor = self::vendedor_param( $get );
        list( $desde, $hasta ) = self::range_dates( $get['range'] ?? 'month', $get['start_date'] ?? '', $get['end_date'] ?? '', (int) current_time( 'timestamp' ) );

        $filas  = self::resumen_por_vendedor( $desde, $hasta );
        $hidden = array_intersect_key( $get, array_flip( array( 'page', 'tab', 'report', 'range', 'start_date', 'end_date' ) ) );
        echo self::vendedor_select_markup( $filas, $vendedor, $hidden ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup ya escapado dentro
        echo self::resumen_markup( $filas );                              // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        self::set_scope( self::SCOPE_ONLY_ASESOR, $vendedor );
        $reporte = new WC_Report_Sales_By_Date();
        $reporte->output_report();
        self::set_scope( self::SCOPE_EXCLUDE_ALL );
    }

    public static function init(): void {
        add_filter( 'woocommerce_reports_get_order_report_query', array( __CLASS__, 'filter_report_query' ) );
        add_filter( 'woocommerce_admin_reports', array( __CLASS__, 'register_tab' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }
}
