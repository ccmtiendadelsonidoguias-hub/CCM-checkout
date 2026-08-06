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

    const META_ORIGEN = '_ccm_origen';
    const ORIGEN_BOT  = 'chatwoot_venta';

    /**
     * Mientras es true, filter_report_query() invierte el filtro: en vez de
     * excluir las ventas del bot, incluye SÓLO esas. Lo activa render_bot_report()
     * justo antes de delegar en el WC_Report_Sales_By_Date de WooCommerce (mismo
     * gráfico/CSV que "Ventas"), y lo apaga apenas termina — así el resto de
     * pestañas (Ventas, Clientes, Stock, Impuestos) siguen excluyendo como antes.
     */
    private static $scope_only_bot = false;

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
     * Subquery reusada por el filtro del informe y por bot_totals(): IDs de
     * pedido con la meta del bot. Ya viene escapada por $wpdb->prepare(), así
     * que se puede insertar tal cual en el WHERE de otra query.
     */
    private static function bot_orders_subquery(): string {
        global $wpdb;
        return $wpdb->prepare(
            "SELECT ccm_org.post_id FROM {$wpdb->postmeta} AS ccm_org
              WHERE ccm_org.meta_key = %s AND ccm_org.meta_value = %s",
            self::META_ORIGEN,
            self::ORIGEN_BOT
        );
    }

    /**
     * Filtra el informe clásico por origen del pedido. Se engancha a
     * woocommerce_reports_get_order_report_query, que recibe la query ya armada.
     * Por defecto EXCLUYE las ventas del bot (pestaña "Ventas" normal); mientras
     * $scope_only_bot esté activo, hace lo contrario: sólo esas ventas (pestaña
     * "Ventas del bot").
     *
     * @param array $query Partes SQL del informe (select/from/where/…).
     * @return array
     */
    public static function filter_report_query( $query ) {
        if ( ! is_array( $query ) ) {
            return $query;
        }
        $operador        = self::$scope_only_bot ? 'IN' : 'NOT IN';
        $query['where']  = ( $query['where'] ?? '' ) . ' AND posts.ID ' . $operador . ' ( ' . self::bot_orders_subquery() . ' ) ';
        return $query;
    }

    /**
     * Cuántas ventas del bot cayeron en el rango y cuánto suman. Se calcula con
     * las mismas tablas y estados que el informe, para que el número que se
     * muestra sea exactamente lo que se excluyó/incluyó.
     *
     * @return array{n:int, total:float}
     */
    public static function bot_totals( string $desde, string $hasta ): array {
        global $wpdb;
        $estados      = self::order_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $estados ), '%s' ) );
        $args         = array_merge(
            array( self::META_ORIGEN, self::ORIGEN_BOT ),
            $estados,
            array( $desde . ' 00:00:00', $hasta . ' 23:59:59' )
        );
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT posts.ID) AS n,
                        COALESCE( SUM( ccm_tot.meta_value + 0 ), 0 ) AS total
                   FROM {$wpdb->posts} AS posts
                   INNER JOIN {$wpdb->postmeta} AS ccm_org
                           ON ccm_org.post_id = posts.ID
                          AND ccm_org.meta_key = %s AND ccm_org.meta_value = %s
                   LEFT JOIN {$wpdb->postmeta} AS ccm_tot
                          ON ccm_tot.post_id = posts.ID
                         AND ccm_tot.meta_key = '_order_total'
                  WHERE posts.post_type = 'shop_order'
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
        $bot = self::bot_totals( $desde, $hasta );

        printf(
            '<div class="notice notice-info"><p><strong>%s</strong> %s <em>(%s → %s)</em> — <a href="%s">%s</a></p></div>',
            esc_html__( 'Ventas del bot excluidas de este informe:', 'ccm-checkout' ),
            wp_kses_post( sprintf(
                /* translators: 1: número de pedidos, 2: total */
                _n( '%1$s pedido · %2$s', '%1$s pedidos · %2$s', $bot['n'], 'ccm-checkout' ),
                number_format_i18n( $bot['n'] ),
                wc_price( $bot['total'] )
            ) ),
            esc_html( $desde ),
            esc_html( $hasta ),
            esc_url( admin_url( 'admin.php?page=wc-reports&tab=ccmck_bot' ) ),
            esc_html__( 'verlas', 'ccm-checkout' )
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
        self::$scope_only_bot = true;
        $reporte               = new WC_Report_Sales_By_Date();
        $reporte->output_report();
        self::$scope_only_bot = false;
    }

    public static function init(): void {
        add_filter( 'woocommerce_reports_get_order_report_query', array( __CLASS__, 'filter_report_query' ) );
        add_filter( 'woocommerce_admin_reports', array( __CLASS__, 'register_tab' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }
}
