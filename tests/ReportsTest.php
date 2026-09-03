<?php
use PHPUnit\Framework\TestCase;

/** wpdb mínimo: sólo lo que usa CCMCK_Reports para armar el SQL. */
final class CCMCK_Fake_Wpdb {
    public $posts    = 'wp_posts';
    public $postmeta = 'wp_postmeta';

    /** Sustituye %s/%d como wpdb::prepare y revienta si sobran argumentos. */
    public function prepare( $sql, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $out = '';
        $len = strlen( $sql );
        for ( $i = 0; $i < $len; $i++ ) {
            if ( '%' === $sql[ $i ] && isset( $sql[ $i + 1 ] ) && in_array( $sql[ $i + 1 ], array( 's', 'd' ), true ) ) {
                $v    = array_shift( $args );
                $out .= 'd' === $sql[ $i + 1 ] ? (int) $v : "'" . addslashes( (string) $v ) . "'";
                $i++;
                continue;
            }
            $out .= $sql[ $i ];
        }
        if ( count( $args ) ) {
            throw new RuntimeException( 'sobran ' . count( $args ) . ' argumentos en prepare()' );
        }
        return $out;
    }

    /** Último SQL recibido y filas a devolver, para probar qué se consulta sin base real. */
    public array $sqls    = array();
    public array $row     = array( 'n' => 0, 'total' => 0 );
    public array $results = array();

    public function get_row( $sql, $output = ARRAY_A ) {
        $this->sqls[] = $sql;
        return $this->row;
    }

    public function get_results( $sql, $output = ARRAY_A ) {
        $this->sqls[] = $sql;
        return $this->results;
    }
}

final class ReportsTest extends TestCase {

    /** Miércoles 29 de julio de 2026, para que los rangos sean deterministas. */
    private function now(): int {
        return strtotime( '2026-07-29 15:00:00 UTC' );
    }

    // --- range_dates: mismos rangos que los botones del informe ---

    public function test_range_este_mes(): void {
        $this->assertSame( array( '2026-07-01', '2026-07-29' ), CCMCK_Reports::range_dates( 'month', '', '', $this->now() ) );
        // Sin parámetro, el informe cae en "Este mes".
        $this->assertSame( array( '2026-07-01', '2026-07-29' ), CCMCK_Reports::range_dates( '', '', '', $this->now() ) );
    }

    public function test_range_mes_pasado_y_ano(): void {
        $this->assertSame( array( '2026-06-01', '2026-06-30' ), CCMCK_Reports::range_dates( 'last_month', '', '', $this->now() ) );
        $this->assertSame( array( '2026-01-01', '2026-07-29' ), CCMCK_Reports::range_dates( 'year', '', '', $this->now() ) );
    }

    public function test_range_7_dias_incluye_hoy(): void {
        $this->assertSame( array( '2026-07-23', '2026-07-29' ), CCMCK_Reports::range_dates( '7day', '', '', $this->now() ) );
    }

    public function test_range_personalizado(): void {
        $this->assertSame(
            array( '2026-07-10', '2026-07-20' ),
            CCMCK_Reports::range_dates( 'custom', '2026-07-10', '2026-07-20', $this->now() )
        );
        // Fechas vacías: no reventar, caer en el mes actual.
        $this->assertSame(
            array( '2026-07-01', '2026-07-29' ),
            CCMCK_Reports::range_dates( 'custom', '', '', $this->now() )
        );
    }

    // --- filter_report_query: la cláusula que saca las ventas del bot ---

    public function test_filtro_excluye_ventas_del_bot(): void {
        $GLOBALS['wpdb'] = new CCMCK_Fake_Wpdb();
        $q               = CCMCK_Reports::filter_report_query( array(
            'select' => 'SUM(x)',
            'where'  => " AND posts.post_status='wc-completed'",
        ) );

        $this->assertStringContainsString( "post_status='wc-completed'", $q['where'], 'no debe pisar el where original' );
        $this->assertStringContainsString( 'posts.ID NOT IN', $q['where'] );
        $this->assertStringContainsString( "'_ccm_origen'", $q['where'] );
        $this->assertStringContainsString( "'chatwoot_venta'", $q['where'] );
        $this->assertSame( substr_count( $q['where'], '(' ), substr_count( $q['where'], ')' ), 'paréntesis balanceados' );
    }

    public function test_filtro_no_rompe_con_entradas_raras(): void {
        $GLOBALS['wpdb'] = new CCMCK_Fake_Wpdb();
        // Si WooCommerce cambia la forma de la query, se devuelve tal cual.
        $this->assertSame( 'no-soy-array', CCMCK_Reports::filter_report_query( 'no-soy-array' ) );
        // Sin 'where' previo también debe funcionar.
        $q = CCMCK_Reports::filter_report_query( array( 'select' => 'x' ) );
        $this->assertStringContainsString( 'NOT IN', $q['where'] );
    }

    // --- scopes: Ventas excluye todo el chat; bot y asesores son subconjuntos disjuntos ---

    public function test_scope_only_bot_incluye_solo_pedidos_del_bot(): void {
        $GLOBALS['wpdb'] = new CCMCK_Fake_Wpdb();
        try {
            CCMCK_Reports::set_scope( CCMCK_Reports::SCOPE_ONLY_BOT );
            $q = CCMCK_Reports::filter_report_query( array( 'where' => '' ) );
            $this->assertStringContainsString( 'posts.ID IN (', $q['where'] );
            $this->assertStringContainsString( "'chatwoot_venta'", $q['where'] );
            // bot = chat que NO es asesor: la subquery de asesor va dentro con NOT IN
            $this->assertStringContainsString( "'_ccm_canal_venta'", $q['where'] );
            $this->assertStringContainsString( "'asesor'", $q['where'] );
            $this->assertStringContainsString( 'NOT IN', $q['where'] );
            $this->assertSame( substr_count( $q['where'], '(' ), substr_count( $q['where'], ')' ) );
        } finally {
            CCMCK_Reports::set_scope( CCMCK_Reports::SCOPE_EXCLUDE_ALL );
        }
    }

    public function test_scope_only_asesor_incluye_solo_asesores(): void {
        $GLOBALS['wpdb'] = new CCMCK_Fake_Wpdb();
        try {
            CCMCK_Reports::set_scope( CCMCK_Reports::SCOPE_ONLY_ASESOR );
            $q = CCMCK_Reports::filter_report_query( array( 'where' => '' ) );
            $this->assertStringContainsString( 'posts.ID IN (', $q['where'] );
            $this->assertStringContainsString( "'_ccm_canal_venta'", $q['where'] );
            $this->assertStringContainsString( "'asesor'", $q['where'] );
            $this->assertStringNotContainsString( "'chatwoot_venta'", $q['where'], 'asesor se define por canal, no por origen' );
            $this->assertStringNotContainsString( '_ccm_alegra_seller_id', $q['where'], 'sin vendedor no filtra por vendedor' );
        } finally {
            CCMCK_Reports::set_scope( CCMCK_Reports::SCOPE_EXCLUDE_ALL );
        }
    }

    public function test_scope_only_asesor_filtra_por_vendedor(): void {
        $GLOBALS['wpdb'] = new CCMCK_Fake_Wpdb();
        try {
            CCMCK_Reports::set_scope( CCMCK_Reports::SCOPE_ONLY_ASESOR, '3' );
            $q = CCMCK_Reports::filter_report_query( array( 'where' => '' ) );
            $this->assertStringContainsString( "'_ccm_alegra_seller_id'", $q['where'] );
            $this->assertStringContainsString( "'3'", $q['where'] );
        } finally {
            CCMCK_Reports::set_scope( CCMCK_Reports::SCOPE_EXCLUDE_ALL );
        }
    }

    public function test_set_scope_rechaza_valores_raros(): void {
        $GLOBALS['wpdb'] = new CCMCK_Fake_Wpdb();
        CCMCK_Reports::set_scope( 'lo-que-sea', '3; DROP TABLE' );
        $q = CCMCK_Reports::filter_report_query( array( 'where' => '' ) );
        // Un scope desconocido cae al comportamiento por defecto (excluir todo el chat).
        $this->assertStringContainsString( 'posts.ID NOT IN (', $q['where'] );
        $this->assertStringNotContainsString( 'DROP', $q['where'] );
    }

    public function test_scope_vuelve_a_excluir_por_defecto(): void {
        $GLOBALS['wpdb'] = new CCMCK_Fake_Wpdb();
        $q = CCMCK_Reports::filter_report_query( array( 'where' => '' ) );
        $this->assertStringContainsString( 'posts.ID NOT IN (', $q['where'] );
        $this->assertStringNotContainsString( "'_ccm_canal_venta'", $q['where'], 'Ventas excluye por origen, no por canal' );
    }

    public function test_register_tab_agrega_ventas_del_bot_sin_tocar_las_demas(): void {
        $original = array(
            'orders'    => array( 'title' => 'Orders' ),
            'customers' => array( 'title' => 'Customers' ),
        );
        $reports = CCMCK_Reports::register_tab( $original );

        $this->assertArrayHasKey( 'orders', $reports, 'no debe tocar las pestañas existentes' );
        $this->assertArrayHasKey( 'customers', $reports );
        $this->assertArrayHasKey( 'ccmck_bot', $reports );
        $this->assertArrayHasKey( 'main', $reports['ccmck_bot']['reports'] );
    }

    public function test_register_tab_callback_apunta_al_render_correcto(): void {
        $reports = CCMCK_Reports::register_tab( array() );
        $this->assertSame( array( 'CCMCK_Reports', 'render_bot_report' ), $reports['ccmck_bot']['reports']['main']['callback'] );
        $this->assertTrue( $reports['ccmck_bot']['reports']['main']['hide_title'] );
    }

    public function test_register_tab_no_rompe_con_entrada_rara(): void {
        $this->assertSame( 'no-soy-array', CCMCK_Reports::register_tab( 'no-soy-array' ) );
    }

    // --- chat_totals: lo que el aviso de "Ventas" dice que excluyó, en dos cifras ---

    public function test_chat_totals_consulta_bot_y_asesor_por_separado(): void {
        $wpdb            = new CCMCK_Fake_Wpdb();
        $wpdb->row       = array( 'n' => '4', 'total' => '1250000.50' );
        $GLOBALS['wpdb'] = $wpdb;

        $t = CCMCK_Reports::chat_totals( '2026-09-01', '2026-09-03' );

        $this->assertSame( array( 'bot', 'asesor' ), array_keys( $t ) );
        $this->assertSame( 4, $t['bot']['n'] );
        $this->assertSame( 1250000.5, $t['asesor']['total'] );
        $this->assertCount( 2, $wpdb->sqls, 'una consulta por subconjunto' );
        // La del bot excluye asesores; la de asesores va por canal.
        $this->assertStringContainsString( 'NOT IN', $wpdb->sqls[0] );
        $this->assertStringContainsString( "'asesor'", $wpdb->sqls[1] );
        $this->assertStringNotContainsString( 'NOT IN', $wpdb->sqls[1] );
        foreach ( $wpdb->sqls as $sql ) {
            $this->assertStringContainsString( "'2026-09-01 00:00:00'", $sql );
            $this->assertStringContainsString( "'2026-09-03 23:59:59'", $sql );
            $this->assertStringContainsString( "'wc-completed'", $sql, 'mismos estados que el informe' );
            $this->assertStringContainsString( '_order_total', $sql );
        }
    }

    // --- pestaña "Ventas asesores" ---

    public function test_vendedor_param_solo_digitos(): void {
        $this->assertSame( '3', CCMCK_Reports::vendedor_param( array( 'vendedor' => '3' ) ) );
        $this->assertSame( '3', CCMCK_Reports::vendedor_param( array( 'vendedor' => ' 3; DROP ' ) ) );
        $this->assertSame( '', CCMCK_Reports::vendedor_param( array( 'vendedor' => 'todos' ) ) );
        $this->assertSame( '', CCMCK_Reports::vendedor_param( array() ) );
    }

    public function test_vendedor_param_ignora_arrays_sin_warning(): void {
        $this->assertSame( '', CCMCK_Reports::vendedor_param( array( 'vendedor' => array( '1' ) ) ) );
    }

    public function test_resumen_por_vendedor_agrupa_y_ordena(): void {
        $wpdb            = new CCMCK_Fake_Wpdb();
        $wpdb->results   = array(
            array( 'vendedor_id' => '4', 'vendedor' => 'Farid Sanchez', 'n' => '2', 'total' => '300000' ),
            array( 'vendedor_id' => '3', 'vendedor' => 'Heider Arrieta', 'n' => '5', 'total' => '900000' ),
        );
        $GLOBALS['wpdb'] = $wpdb;

        $filas = CCMCK_Reports::resumen_por_vendedor( '2026-09-01', '2026-09-30' );

        $this->assertSame( array( '3', '4' ), array_column( $filas, 'vendedor_id' ), 'ordenado por total desc' );
        $this->assertSame( 5, $filas[0]['n'] );
        $this->assertSame( 900000.0, $filas[0]['total'] );
        $sql = $wpdb->sqls[0];
        $this->assertStringContainsString( "'_ccm_canal_venta'", $sql );
        $this->assertStringContainsString( "'_ccm_alegra_seller_id'", $sql );
        $this->assertStringContainsString( "'_ccm_alegra_seller_nombre'", $sql );
        $this->assertStringContainsString( 'GROUP BY', $sql );
        $this->assertStringContainsString( "'2026-09-30 23:59:59'", $sql );
    }

    public function test_resumen_markup_suma_y_escapa(): void {
        $html = CCMCK_Reports::resumen_markup( array(
            array( 'vendedor_id' => '3', 'vendedor' => 'Heider <b>A</b>', 'n' => 5, 'total' => 900000.0 ),
            array( 'vendedor_id' => '4', 'vendedor' => 'Farid', 'n' => 2, 'total' => 300000.0 ),
        ) );
        $this->assertStringContainsString( 'Heider &lt;b&gt;A&lt;/b&gt;', $html, 'nombre escapado' );
        $this->assertStringContainsString( '>7<', $html, 'suma de pedidos' );
        $this->assertStringContainsString( wc_price( 1200000.0 ), $html, 'suma de totales' );
        $this->assertSame( '', CCMCK_Reports::resumen_markup( array() ), 'sin filas, sin tabla' );
    }

    public function test_vendedor_select_markup_marca_el_actual_y_conserva_el_rango(): void {
        $filas = array(
            array( 'vendedor_id' => '3', 'vendedor' => 'Heider', 'n' => 1, 'total' => 1.0 ),
            array( 'vendedor_id' => '4', 'vendedor' => 'Farid', 'n' => 1, 'total' => 1.0 ),
        );
        $html = CCMCK_Reports::vendedor_select_markup( $filas, '4', array( 'range' => 'month', 'page' => 'wc-reports', 'tab' => 'ccmck_asesores' ) );
        $this->assertStringContainsString( '<option value="4" selected', $html );
        $this->assertStringNotContainsString( '<option value="3" selected', $html );
        $this->assertStringContainsString( '<option value="">', $html, 'opción Todos' );
        $this->assertStringContainsString( 'name="range" value="month"', $html );
        $this->assertStringContainsString( 'name="tab" value="ccmck_asesores"', $html );
        $this->assertStringNotContainsString( 'name="vendedor" value=', $html, 'vendedor va en el select, no en hidden' );
    }

    public function test_register_tab_agrega_ventas_asesores(): void {
        $reports = CCMCK_Reports::register_tab( array( 'orders' => array( 'title' => 'Orders' ) ) );
        $this->assertArrayHasKey( 'ccmck_asesores', $reports );
        $this->assertSame( array( 'CCMCK_Reports', 'render_asesores_report' ), $reports['ccmck_asesores']['reports']['main']['callback'] );
        $this->assertArrayHasKey( 'ccmck_bot', $reports, 'la del bot sigue' );
        $this->assertArrayHasKey( 'orders', $reports );
    }
}
