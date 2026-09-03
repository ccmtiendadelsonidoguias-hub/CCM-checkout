# Ventas de asesores por el botón Venta — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada venta hecha con el botón 💰 Venta de Chatwoot quede atribuida al asesor que la registra (o al bot solo si él lo marca), y que WooCommerce → Informes tenga una pestaña «Ventas asesores» separada de «Ventas» y de «Ventas del bot».

**Architecture:** El popup (`cwVentaPage01`) lee el agente del `appContext` que Chatwoot le manda y lo envía a la API (`cwVentaApi01`); un único nodo `Agente → vendedor` traduce correo → vendedor/centro de costo de Alegra; `Order payload` escribe la meta nueva `_ccm_canal_venta` (`asesor|bot`) sin tocar `_ccm_origen`, así facturación/flete/recogida/Siigo no cambian. El plugin (`CCMCK_Reports`) pasa de un booleano a un `$scope` de tres valores y gana la pestaña «Ventas asesores» con filtro y resumen por vendedor.

**Tech Stack:** PHP 8.3 + PHPUnit 12.5 (`php phpunit.phar`), n8n 2.x en Docker (`n8n-n8n-1` en `root@2.24.202.75`), WordPress en Hostinger (`ssh ccm-web`, `wp-cli`), Python 3 para cirugía de JSON, Node para arneses.

**Spec:** `docs/superpowers/specs/2026-09-03-ventas-asesores-design.md`

## Global Constraints

- **Un GO explícito del dueño por cada despliegue a producción** (Tareas 4, 6 y 8). Sin GO no se importa nada. Un GO no se extiende al siguiente.
- **Import + publish + re-registro del webhook son UN paso** en n8n: `n8n import:workflow` → `n8n publish:workflow` → `POST /api/v1/workflows/{id}/deactivate` → `POST …/activate` (la llave está en `/root/.n8n_api_key`; tras un import la fila de `webhook_entity` desaparece y la URL da 404 hasta reactivar).
- **Antes de parchar un export, verificar que draft == publicado** (nodos y hash de `parameters`) leyendo `workflow_history` con `require('/usr/local/lib/node_modules/n8n/node_modules/sqlite3')`. Si no cuadran, detenerse.
- **Backup antes de tocar**: `/root/backups/<id>_PRE_ASESORES_20260903.json`.
- **Nodos nuevos en n8n con `onError: "continueRegularOutput"`** salvo IF/Code puros en el camino del turno (regla 4 de `CLAUDE.md`, misma excepción que ya aplica a `¿Prefill?`).
- **Nada de texto de cliente en git**: los exports de n8n **no se commitean** (llevan `X-CCMCK-Secret` hardcodeado); se commitean solo los scripts de parche y los arneses. Los fixtures de los arneses usan datos inventados.
- **Commits solo de rutas propias** (`git add <ruta>`), nunca `git add -A`: el working tree lleva WIP del dueño con flips CRLF.
- Metas del pedido, nombres exactos: `_ccm_origen` (sin cambios, `chatwoot_venta`), `_ccm_canal_venta` ∈ {`asesor`,`bot`}, `_ccm_agente_chatwoot` (correo), `_ccm_alegra_seller_id`, `_ccm_alegra_seller_nombre`, `_ccm_alegra_cost_center_id`, `_ccm_alegra_cost_center_nombre`.
- Mapa agente → Alegra (único sitio: nodo `Agente → vendedor`): `heider@ccmtiendadelsonido.com`→3 «Heider Arrieta» · `farid@ccmtiendadelsonido.com`→4 «Farid Sanchez» · `gerencia@ccmtiendadelsonido.com`→6 «Camilo Caraballo Avendaño»; centro de costo **3 «Ventas Virtuales Personas CCM»** para los tres. Bot = vendedor **9 «Bot CCM IA»**, centro **10 «IA CCM»**. **Nunca** el vendedor 5 (es otro Camilo).
- Repo del plugin: `/Users/sarasoto/Documents/Filtro - buscador /_public_html/wp-content/mu-plugins/ccm-checkout` (la ruta lleva espacios y un espacio final en `Filtro - buscador `; verificar con `git remote -v` que se está en `ccmtiendadelsonidoguias-hub/ccm-checkout` antes de cualquier commit). Rama: `feat/ventas-asesores`.

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `includes/class-ccmck-reports.php` | Filtro del informe clásico, scopes, totales, pestañas bot/asesores, markup puro del resumen | Modificar |
| `tests/ReportsTest.php` | Tests de las funciones puras del informe | Modificar |
| `docs/n8n/patches/2026-09-03-ventas-asesores-api.py` | Cirugía sobre el export de `cwVentaApi01` (nodo nuevo, 3 nodos editados, 1 IF nuevo, cables) | Crear |
| `docs/n8n/patches/2026-09-03-ventas-asesores-popup.py` | Cirugía sobre el HTML del nodo `HTML` de `cwVentaPage01` | Crear |
| `docs/n8n/harness/ventas_asesores_api.js` | Arnés viejo-vs-nuevo de `Agente → vendedor`, `Order payload`, `Resultado` | Crear |
| `docs/n8n/harness/ventas_asesores_popup.html` | Arnés del popup: iframe + `appContext` falso + asserts en DOM | Crear |
| `docs/n8n/cwVentaApi01.ADDENDUM.md` | Qué cambió en el workflow y por qué (sin secretos) | Crear |

---

### Task 1: `$scope` de tres valores y subqueries bot/asesor

**Files:**
- Modify: `includes/class-ccmck-reports.php` (líneas 27-33 constantes/prop, 99-131 subquery y filtro)
- Test: `tests/ReportsTest.php`

**Interfaces:**
- Produces:
  - `const META_CANAL = '_ccm_canal_venta'`, `const CANAL_ASESOR = 'asesor'`, `const SCOPE_EXCLUDE_ALL = 'exclude_all'`, `const SCOPE_ONLY_BOT = 'only_bot'`, `const SCOPE_ONLY_ASESOR = 'only_asesor'`
  - `public static function set_scope( string $scope, string $vendedor = '' ): void`
  - `public static function chat_orders_subquery(): string` — SQL preparado (IDs con `_ccm_origen=chatwoot_venta`)
  - `public static function asesor_orders_subquery( string $vendedor = '' ): string` — SQL preparado (IDs con `_ccm_canal_venta=asesor`, opcionalmente filtrado por `_ccm_alegra_seller_id`)
  - `public static function bot_orders_subquery(): string` — chat **y no** asesor
  - `public static function filter_report_query( $query )` — ya existe, cambia su lógica interna

- [ ] **Step 1: Sustituir el helper de reflexión en los tests y escribir los tests nuevos**

En `tests/ReportsTest.php`, reemplazar el método `set_scope_only_bot` y los dos tests que lo usan por esto (mantener el resto del archivo intacto):

```php
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
```

- [ ] **Step 2: Correr y ver fallar**

```bash
cd "/Users/sarasoto/Documents/Filtro - buscador /_public_html/wp-content/mu-plugins/ccm-checkout" && php phpunit.phar --filter ReportsTest --no-coverage
```
Esperado: errores `Undefined constant CCMCK_Reports::SCOPE_ONLY_BOT` / `Call to undefined method CCMCK_Reports::set_scope()`.

- [ ] **Step 3: Implementar constantes, `$scope`, `set_scope`, subqueries y el filtro**

En `includes/class-ccmck-reports.php`, sustituir el bloque de constantes + `$scope_only_bot` (líneas 27-36) por:

```php
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
```

Sustituir `bot_orders_subquery()` (líneas 99-108) y `filter_report_query()` (119-124) por:

```php
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
```

Y en `render_bot_report()` (línea ~266) sustituir las dos líneas `self::$scope_only_bot = true;` / `= false;` por `self::set_scope( self::SCOPE_ONLY_BOT );` y `self::set_scope( self::SCOPE_EXCLUDE_ALL );`.

- [ ] **Step 4: Correr y ver pasar**

```bash
php -l includes/class-ccmck-reports.php && php phpunit.phar --filter ReportsTest --no-coverage
```
Esperado: `OK (14 tests, …)` y **sin** el aviso de `setAccessible()` deprecado (se fue con la reflexión).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-reports.php tests/ReportsTest.php
git commit -m "feat(informes): scope de tres valores y subqueries bot/asesor por _ccm_canal_venta"
```

---

### Task 2: totales bot + asesor y aviso con dos cifras

**Files:**
- Modify: `includes/class-ccmck-reports.php` (`bot_totals` → `chat_totals`, `render_notice`)
- Test: `tests/ReportsTest.php` (ampliar `CCMCK_Fake_Wpdb` con `get_row`/`get_results`)

**Interfaces:**
- Consumes: `bot_orders_subquery()`, `asesor_orders_subquery()`, `order_statuses()` (Task 1 / existente)
- Produces: `public static function chat_totals( string $desde, string $hasta ): array` → `array('bot'=>array('n'=>int,'total'=>float), 'asesor'=>array('n'=>int,'total'=>float))`

- [ ] **Step 1: Ampliar el wpdb falso y escribir los tests**

En `tests/ReportsTest.php`, dentro de `CCMCK_Fake_Wpdb`, añadir tras `prepare()`:

```php
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
```

Y al principio de `tests/bootstrap.php` (si no existe ya) la constante que WordPress define: `if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }`.

Añadir estos tests a `ReportsTest`:

```php
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
```

- [ ] **Step 2: Correr y ver fallar**

```bash
php phpunit.phar --filter test_chat_totals --no-coverage
```
Esperado: `Call to undefined method CCMCK_Reports::chat_totals()`.

- [ ] **Step 3: Implementar `chat_totals` y actualizar el aviso**

Sustituir `bot_totals()` completo por:

```php
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
```

En `render_notice()`, sustituir desde `$bot = self::bot_totals( $desde, $hasta );` hasta el final del `printf(...)` por:

```php
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
```

- [ ] **Step 4: Correr y ver pasar**

```bash
php -l includes/class-ccmck-reports.php && php phpunit.phar --filter ReportsTest --no-coverage
```
Esperado: `OK (15 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-reports.php tests/ReportsTest.php tests/bootstrap.php
git commit -m "feat(informes): totales bot y asesores separados; el aviso de Ventas muestra ambos"
```

---

### Task 3: pestaña «Ventas asesores» con filtro y resumen por vendedor

**Files:**
- Modify: `includes/class-ccmck-reports.php` (`register_tab`, nuevo `render_asesores_report` y helpers puros)
- Test: `tests/ReportsTest.php`

**Interfaces:**
- Consumes: `set_scope()`, `asesor_orders_subquery()`, `order_statuses()`, `range_dates()`
- Produces:
  - `public static function vendedor_param( array $get ): string` — solo dígitos de `$get['vendedor']`, '' si no hay
  - `public static function resumen_por_vendedor( string $desde, string $hasta ): array` — filas `array('vendedor_id'=>string,'vendedor'=>string,'n'=>int,'total'=>float)` ordenadas por total desc
  - `public static function resumen_markup( array $filas ): string` — tabla HTML con fila de suma
  - `public static function vendedor_select_markup( array $filas, string $actual, array $hidden ): string` — `<form method="get">` con `<select name="vendedor">` y los `hidden` dados
  - `public static function render_asesores_report(): void`

- [ ] **Step 1: Escribir los tests**

```php
    // --- pestaña "Ventas asesores" ---

    public function test_vendedor_param_solo_digitos(): void {
        $this->assertSame( '3', CCMCK_Reports::vendedor_param( array( 'vendedor' => '3' ) ) );
        $this->assertSame( '3', CCMCK_Reports::vendedor_param( array( 'vendedor' => ' 3; DROP ' ) ) );
        $this->assertSame( '', CCMCK_Reports::vendedor_param( array( 'vendedor' => 'todos' ) ) );
        $this->assertSame( '', CCMCK_Reports::vendedor_param( array() ) );
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
```

Añadir a `tests/bootstrap.php`, si no existen: `function wc_price( $v ) { return '$' . number_format( (float) $v, 0, ',', '.' ); }`, `function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', '.' ); }` y `function current_time( $type ) { return time(); }` (todas dentro de `if ( ! function_exists(...) )`).

- [ ] **Step 2: Correr y ver fallar**

```bash
php phpunit.phar --filter ReportsTest --no-coverage
```
Esperado: 5 errores por métodos indefinidos (`vendedor_param`, `resumen_por_vendedor`, `resumen_markup`, `vendedor_select_markup`) y la aserción de `ccmck_asesores`.

- [ ] **Step 3: Implementar**

En `register_tab()`, después del bloque `$reports['ccmck_bot'] = …;` añadir:

```php
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
```

Antes de `init()` añadir:

```php
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
```

- [ ] **Step 4: Correr toda la suite**

```bash
php -l includes/class-ccmck-reports.php && php phpunit.phar --no-coverage
```
Esperado: `OK (371 tests, …)` (366 antes + 5 nuevos de esta tarea; el número exacto puede variar si hubo merges — lo que no puede haber es ningún fallo).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-reports.php tests/ReportsTest.php tests/bootstrap.php
git commit -m "feat(informes): pestaña Ventas asesores con filtro y resumen por vendedor"
```

---

### Task 4: desplegar el plugin a producción y dev — **REQUIERE GO**

**Files:**
- Deploy: `includes/class-ccmck-reports.php` → `ccm-web:~/public_html/wp-content/mu-plugins/ccm-checkout/includes/` y `~/public_html/dev/wp-content/mu-plugins/ccm-checkout/includes/`

- [ ] **Step 1: Comprobar que el servidor no va por delante del repo**

```bash
cd "/Users/sarasoto/Documents/Filtro - buscador /_public_html/wp-content/mu-plugins/ccm-checkout"
git show main:includes/class-ccmck-reports.php | shasum -a 256
ssh ccm-web 'for d in public_html public_html/dev; do sha256sum ~/$d/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-reports.php; done'
```
Esperado: los **tres** hashes iguales. Si prod o dev difieren de `main`, **parar** y traer primero esa diferencia al repo (precedente: `ccmck_release_arreglo_fuera_de_main`).

- [ ] **Step 2: Pedir el GO al dueño**

Mostrar: diff estructural (`git diff main --stat`, funciones nuevas), resultado de la suite, y los tres hashes. Esperar un **GO explícito**. Sin GO, terminar aquí.

- [ ] **Step 3: Backup remoto y copia**

```bash
ssh ccm-web 'for d in public_html public_html/dev; do cp ~/$d/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-reports.php ~/$d/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-reports.php.bak-20260903; done'
scp includes/class-ccmck-reports.php ccm-web:public_html/wp-content/mu-plugins/ccm-checkout/includes/
scp includes/class-ccmck-reports.php ccm-web:public_html/dev/wp-content/mu-plugins/ccm-checkout/includes/
shasum -a 256 includes/class-ccmck-reports.php
ssh ccm-web 'for d in public_html public_html/dev; do sha256sum ~/$d/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-reports.php; done; cd ~/public_html && wp litespeed-purge all 2>/dev/null | tail -1'
```
Esperado: hashes iguales al local en prod y dev.

- [ ] **Step 4: Smoke en producción con wp-cli**

```bash
ssh ccm-web 'cd ~/public_html && wp eval "print_r(array_keys(apply_filters(\"woocommerce_admin_reports\", array()))); print_r(CCMCK_Reports::chat_totals(\"2026-08-01\",\"2026-09-03\"));" 2>/dev/null'
```
Esperado: entre las pestañas aparecen `ccmck_bot` y `ccmck_asesores`; `chat_totals` devuelve `bot` con n > 0 y `asesor` con n = 0 (todavía no hay pedidos con la meta).

- [ ] **Step 5: Anotar en el commit de despliegue**

```bash
git commit --allow-empty -m "chore(deploy): informes asesores desplegados a prod+dev (GO del dueño 2026-09-03)"
```

---

### Task 5: API `cwVentaApi01` — script de parche + arnés viejo-vs-nuevo

**Files:**
- Create: `docs/n8n/patches/2026-09-03-ventas-asesores-api.py`
- Create: `docs/n8n/harness/ventas_asesores_api.js`
- Input (no commiteado): export del workflow en `/tmp/cwVentaApi01.json` (se obtiene en el Step 1)

**Interfaces:**
- Consumes: nodos existentes `WH Venta`, `¿Prefill?`, `Prefill build`, `Order payload`, `WC crear pedido`, `Resultado`, `Crear parse` con el código leído el 2026-09-03 (anclas exactas abajo).
- Produces: nodo `Agente → vendedor` (Code) que emite `$json.agente_resuelto = { email, name, conocido, vendedor_id, vendedor_nombre, ccosto_id, ccosto_nombre }`; `prefill` devuelve además `vendedor_id, vendedor_nombre, ccosto_id, ccosto_nombre, agente_email`; `crear` acepta `body.agente {email,name}` y `form.es_bot`; error nuevo `sin_vendedor`; nodo IF `¿Payload OK?`; `Resultado` en error devuelve `conv`.

- [ ] **Step 1: Traer el export y verificar draft == publicado**

```bash
ssh root@2.24.202.75 "docker exec n8n-n8n-1 sh -c 'n8n export:workflow --id=cwVentaApi01 --output=/tmp/cwVentaApi01.json >/dev/null 2>&1; cat /tmp/cwVentaApi01.json'" > /tmp/cwVentaApi01.json
ssh root@2.24.202.75 "docker exec -i n8n-n8n-1 node -e \"
const sqlite3=require('/usr/local/lib/node_modules/n8n/node_modules/sqlite3'); const crypto=require('crypto');
const db=new sqlite3.Database('/home/node/.n8n/database.sqlite', sqlite3.OPEN_READONLY);
const d=JSON.parse(require('fs').readFileSync('/tmp/cwVentaApi01.json','utf8')); const w0=Array.isArray(d)?d[0]:d;
db.get('select activeVersionId from workflow_entity where id=?',['cwVentaApi01'],(e,w)=>{ db.get('select nodes from workflow_history where workflowId=? and versionId=?',['cwVentaApi01',w.activeVersionId],(e2,h)=>{
 const H=x=>crypto.createHash('sha256').update(JSON.stringify(x.map(n=>[n.name,n.parameters]).sort())).digest('hex').slice(0,12);
 console.log('publicado',JSON.parse(h.nodes).length,H(JSON.parse(h.nodes)),'| draft',w0.nodes.length,H(w0.nodes)); db.close(); }); });
\""
```
Esperado: `publicado 44 <hash> | draft 44 <mismo hash>`. Si difieren, **parar**.

- [ ] **Step 2: Escribir el arnés (falla contra el código viejo)**

`docs/n8n/harness/ventas_asesores_api.js`:

```js
// Arnés viejo-vs-nuevo para cwVentaApi01. Uso:
//   node docs/n8n/harness/ventas_asesores_api.js /tmp/cwVentaApi01.json            -> debe FALLAR (codigo viejo)
//   node docs/n8n/harness/ventas_asesores_api.js /tmp/cwVentaApi01.new.json        -> debe pasar
// Simula el CABLEADO REAL: $json es lo que emite el nodo anterior; lo demas se lee por nombre.
const fs = require('fs');
const wf = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const w = Array.isArray(wf) ? wf[0] : wf;
const code = n => { const x = w.nodes.find(k => k.name === n); if (!x) throw new Error('no existe nodo ' + n); return x.parameters.jsCode; };
const run = (src, $json, nodes, input) => new Function('$json', '$', '$input', src + '\n')($json, name => {
  if (!(name in nodes)) throw new Error('nodo no simulado: ' + name);
  return { first: () => ({ json: nodes[name] }), item: { json: nodes[name] } };
}, input || { all: () => [] });

let fallos = 0;
const chk = (c, m) => { console.log((c ? 'ok   ' : 'FALLA') + ' ' + m); if (!c) fallos++; };

// ---- fixtures inventados (nada de clientes reales) ----
const PROD = { id: 4601, sku: 'CCM1119', name: 'Parlante de prueba', price: '100000', stock_status: 'instock', stock_quantity: 5 };
const cp = (f) => ({ conv: '99001', f, items: [{ sku: 'CCM1119', qty: 1 }], sku_query: 'CCM1119' });
const FORM_BASE = { nombre: 'Prueba', apellido: 'Arnes', documento: '1', telefono: '3000000000', ciudad: 'BARRANQUILLA (ATL) (08001000)',
  departamento: 'ATLANTICO', direccion: 'x', metodo_pago: 'Transferencia', entrega: 'recogida', items: [{ sku: 'CCM1119', qty: 1 }] };
const meta = (out, k) => (((out || {}).order_body || {}).meta_data || []).find(m => m.key === k)?.value;

// ---- Agente → vendedor ----
let ag;
try {
  const agentSrc = code('Agente → vendedor');
  ag = (email) => run(agentSrc, { body: { action: 'prefill', conv: '99001', agente: { email, name: 'X' } }, query: {} }, {})[0].json.agente_resuelto;
  chk(ag('heider@ccmtiendadelsonido.com').vendedor_id === 3 && ag('heider@ccmtiendadelsonido.com').ccosto_id === 3, 'heider -> vendedor 3, ccosto 3');
  chk(ag('FARID@ccmtiendadelsonido.com').vendedor_id === 4, 'farid (mayusculas) -> 4');
  chk(ag('gerencia@ccmtiendadelsonido.com').vendedor_id === 6, 'gerencia -> 6 (nunca el 5)');
  chk(ag('nadie@otro.com').vendedor_id === null && ag('nadie@otro.com').conocido === false, 'desconocido -> null, conocido=false');
} catch (e) { chk(false, 'nodo Agente → vendedor existe (' + e.message + ')'); ag = () => ({}); }

// ---- Order payload ----
const opSrc = code('Order payload');
const op = (form, agente) => run(opSrc, {}, { 'Crear parse': cp(form), 'Agente → vendedor': { agente_resuelto: agente } }, { all: () => [{ json: PROD }] });

const outAsesor = op(Object.assign({}, FORM_BASE, { vendedor_alegra_id: '3', vendedor_nombre: 'Heider Arrieta', centro_costo_id: '3', centro_costo_nombre: 'Ventas Virtuales Personas CCM' }), ag('heider@ccmtiendadelsonido.com'));
chk(meta(outAsesor, '_ccm_canal_venta') === 'asesor', 'vendedor 3 -> canal asesor');
chk(meta(outAsesor, '_ccm_agente_chatwoot') === 'heider@ccmtiendadelsonido.com', 'guarda el agente de Chatwoot');
chk(meta(outAsesor, '_ccm_alegra_seller_id') === '3' && meta(outAsesor, '_ccm_alegra_cost_center_id') === '3', 'metas Alegra del asesor');
chk(meta(outAsesor, '_ccm_origen') === 'chatwoot_venta', '_ccm_origen intacto');

const outBot = op(Object.assign({}, FORM_BASE, { es_bot: true, vendedor_alegra_id: '3', vendedor_nombre: 'Heider Arrieta' }), ag('heider@ccmtiendadelsonido.com'));
chk(meta(outBot, '_ccm_canal_venta') === 'bot' && meta(outBot, '_ccm_alegra_seller_id') === '9' && meta(outBot, '_ccm_alegra_cost_center_id') === '10', 'casilla es_bot gana: 9 / IA CCM / canal bot');

const outSinVend = op(Object.assign({}, FORM_BASE, { vendedor_alegra_id: '', centro_costo_id: '' }), ag('nadie@otro.com'));
chk(!!outSinVend.error && /sin_vendedor/.test(outSinVend.error), 'sin vendedor ni agente -> error sin_vendedor (ANTES: pedido al bot en silencio)');

const outSoloAgente = op(Object.assign({}, FORM_BASE, { vendedor_alegra_id: '', centro_costo_id: '' }), ag('farid@ccmtiendadelsonido.com'));
chk(meta(outSoloAgente, '_ccm_alegra_seller_id') === '4' && meta(outSoloAgente, '_ccm_alegra_seller_nombre') === 'Farid Sanchez', 'popup vacio + agente conocido -> vendedor del agente');

// ---- Resultado en rama de error debe llevar conv ----
const resSrc = code('Resultado');
const res = run(resSrc, { error: 'sin_stock: x' }, { 'Order payload': { error: 'sin_stock: x' }, 'Crear parse': cp(FORM_BASE), 'Agente → vendedor': { agente_resuelto: ag('heider@ccmtiendadelsonido.com') } });
chk(res.ok === false && res.conv === '99001', 'Resultado en error lleva conv (ANTES: undefined -> nota perdida)');

// ---- cableado: el error NO llega a WC crear pedido ----
const to = (from) => (w.connections[from]?.main || []).map(b => b.map(c => c.node));
chk(JSON.stringify(to('Order payload')) === JSON.stringify([['¿Payload OK?']]), 'Order payload -> ¿Payload OK? (no directo a WC)');
chk(JSON.stringify(to('¿Payload OK?')) === JSON.stringify([['WC crear pedido'], ['Resultado']]), '¿Payload OK?: true -> WC crear pedido, false -> Resultado');
chk(JSON.stringify(to('WH Venta')) === JSON.stringify([['Agente → vendedor']]) && JSON.stringify(to('Agente → vendedor')) === JSON.stringify([['¿Prefill?']]), 'Agente → vendedor va entre WH Venta y ¿Prefill?');

console.log(fallos ? '\n>>> ' + fallos + ' FALLOS' : '\n>>> todo verde');
process.exit(fallos ? 1 : 0);
```

- [ ] **Step 3: Correrlo contra el export viejo — debe fallar**

```bash
node docs/n8n/harness/ventas_asesores_api.js /tmp/cwVentaApi01.json
```
Esperado: `FALLA nodo Agente → vendedor existe`, `FALLA sin vendedor…`, `FALLA Resultado en error lleva conv`, los tres `FALLA` de cableado; `>>> N FALLOS`. Si sale verde contra el viejo, el arnés está mal: parar.

- [ ] **Step 4: Escribir el script de parche**

`docs/n8n/patches/2026-09-03-ventas-asesores-api.py`:

```python
# -*- coding: utf-8 -*-
"""Cirugia sobre el export de cwVentaApi01 (ventas de asesores, 2026-09-03).
Uso: python3 docs/n8n/patches/2026-09-03-ventas-asesores-api.py /tmp/cwVentaApi01.json /tmp/cwVentaApi01.new.json
Cada ancla debe aparecer EXACTAMENTE una vez; si no, aborta sin escribir."""
import json, sys

src, dst = sys.argv[1], sys.argv[2]
raw = json.load(open(src))
w = raw[0] if isinstance(raw, list) else raw

def node(name):
    for n in w['nodes']:
        if n['name'] == name:
            return n
    raise SystemExit('no existe nodo ' + name)

def sub(code, old, new, label):
    if code.count(old) != 1:
        raise SystemExit('ANCLA no unica (%d) en %s' % (code.count(old), label))
    return code.replace(old, new)

if any(n['name'] in ('Agente → vendedor', '¿Payload OK?') for n in w['nodes']):
    raise SystemExit('ya parchado')

# ---------- 1. nodo nuevo: Agente → vendedor ----------
AGENTE_CODE = r"""// Unico mapa agente de Chatwoot -> vendedor / centro de costo de Alegra (2026-09-03).
// Corre en TODAS las acciones; Prefill build y Order payload lo leen por nombre.
// OJO: el vendedor 5 es OTRO Camilo. Camilo Caraballo Avendaño = 6.
const MAPA = {
  'heider@ccmtiendadelsonido.com':   { vendedor_id: 3, vendedor_nombre: 'Heider Arrieta' },
  'farid@ccmtiendadelsonido.com':    { vendedor_id: 4, vendedor_nombre: 'Farid Sanchez' },
  'gerencia@ccmtiendadelsonido.com': { vendedor_id: 6, vendedor_nombre: 'Camilo Caraballo Avendaño' },
};
const CCOSTO = { ccosto_id: 3, ccosto_nombre: 'Ventas Virtuales Personas CCM' };
const b = $json.body || {};
const ag = b.agente || {};
const email = String(ag.email || '').trim().toLowerCase();
const hit = MAPA[email] || null;
const agente_resuelto = Object.assign({ email, name: String(ag.name || ''), conocido: !!hit },
  hit ? Object.assign({}, hit, CCOSTO) : { vendedor_id: null, vendedor_nombre: '', ccosto_id: null, ccosto_nombre: '' });
// el item del webhook sigue intacto: los IF de accion leen $json.body.action como siempre
return [{ json: Object.assign({}, $json, { agente_resuelto }) }];"""

wh = node('WH Venta'); pf = node('¿Prefill?')
w['nodes'].append({
    "parameters": {"jsCode": AGENTE_CODE},
    "id": "d1a2b3c4-0001-4000-8000-000000000001", "name": "Agente → vendedor",
    "type": "n8n-nodes-base.code", "typeVersion": 2,
    "position": [wh['position'][0] + 180, wh['position'][1]]})
assert [c['node'] for c in w['connections']['WH Venta']['main'][0]] == ['¿Prefill?']
w['connections']['WH Venta']['main'][0] = [{"node": "Agente → vendedor", "type": "main", "index": 0}]
w['connections']['Agente → vendedor'] = {"main": [[{"node": "¿Prefill?", "type": "main", "index": 0}]]}

# ---------- 2. Prefill build devuelve el vendedor del agente ----------
n = node('Prefill build'); c = n['parameters']['jsCode']
c = sub(c,
"return [{ json: { ok: true, cliente: { nombre: base.nombre, apellido: base.apellido, telefono: base.telefono, email: base.email, ciudad: base.ciudad, documento: '', direccion: '' }, items: out, monto } }];",
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}\n"
"return [{ json: { ok: true, cliente: { nombre: base.nombre, apellido: base.apellido, telefono: base.telefono, email: base.email, ciudad: base.ciudad, documento: '', direccion: '' }, items: out, monto,\n"
"  vendedor_id: ag.vendedor_id || null, vendedor_nombre: ag.vendedor_nombre || '', ccosto_id: ag.ccosto_id || null, ccosto_nombre: ag.ccosto_nombre || '', agente_email: ag.email || '' } }];",
'Prefill build/return')
n['parameters']['jsCode'] = c

# ---------- 3. Order payload: sin default al bot, canal + agente ----------
n = node('Order payload'); c = n['parameters']['jsCode']
c = sub(c, "const line_items = cp.items.map(i => {",
"// v14 (2026-09-03): vendedor = casilla es_bot > lo elegido en el popup > agente de Chatwoot. SIN default al bot.\n"
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}\n"
"const vend = (function () {\n"
"  if (f.es_bot === true) return { id: 9, nombre: 'Bot CCM IA', ccosto_id: 10, ccosto_nombre: 'IA CCM', agente_email: String(ag.email || '') };\n"
"  const id = Number(f.vendedor_alegra_id) || Number(ag.vendedor_id) || 0;\n"
"  if (!id) return null;\n"
"  const delPopup = Number(f.vendedor_alegra_id) === id;\n"
"  return { id,\n"
"    nombre: delPopup ? String(f.vendedor_nombre || '') : String(ag.vendedor_nombre || ''),\n"
"    ccosto_id: Number(f.centro_costo_id) || Number(ag.ccosto_id) || (id === 9 ? 10 : 3),\n"
"    ccosto_nombre: String(f.centro_costo_nombre || ag.ccosto_nombre || (id === 9 ? 'IA CCM' : 'Ventas Virtuales Personas CCM')),\n"
"    agente_email: String(ag.email || '') };\n"
"})();\n"
"if (!vend) return { error: 'sin_vendedor: elige el vendedor en el popup', sin_vendedor: true };\n"
"const line_items = cp.items.map(i => {", 'Order payload/vend')
c = sub(c,
"    { key: '_ccm_alegra_seller_id', value: String(f.vendedor_alegra_id || '9') },\n"
"    { key: '_ccm_alegra_seller_nombre', value: String(f.vendedor_nombre || 'Bot CCM IA') },\n"
"    { key: '_ccm_alegra_cost_center_id', value: String(f.centro_costo_id || '10') },\n"
"    { key: '_ccm_alegra_cost_center_nombre', value: String(f.centro_costo_nombre || 'IA CCM') },",
"    { key: '_ccm_alegra_seller_id', value: String(vend.id) },\n"
"    { key: '_ccm_alegra_seller_nombre', value: vend.nombre },\n"
"    { key: '_ccm_alegra_cost_center_id', value: String(vend.ccosto_id) },\n"
"    { key: '_ccm_alegra_cost_center_nombre', value: vend.ccosto_nombre },\n"
"    { key: '_ccm_canal_venta', value: vend.id === 9 ? 'bot' : 'asesor' },\n"
"    { key: '_ccm_agente_chatwoot', value: vend.agente_email },", 'Order payload/metas')
n['parameters']['jsCode'] = c

# ---------- 4. Resultado: conv en la rama de error + aviso de agente sin mapa ----------
n = node('Resultado'); c = n['parameters']['jsCode']
c = sub(c, "if (op.error) return { ok: false, error: op.error };",
"if (op.error) return { ok: false, error: op.error, conv: $('Crear parse').first().json.conv };", 'Resultado/error conv')
c = sub(c, "return { ok: true, order_id: o.id, order_number: num, total_wc: o.total, conv: op.conv, entrega, msg_cliente,",
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}\n"
"if (ag.email && ag.conocido === false) nota += ' ⚠️ Agente ' + ag.email + ' sin vendedor en el mapa (nodo Agente → vendedor de cwVentaApi01): la venta se atribuyo a lo elegido en el popup.';\n"
"return { ok: true, order_id: o.id, order_number: num, total_wc: o.total, conv: op.conv, entrega, msg_cliente,", 'Resultado/aviso agente')
n['parameters']['jsCode'] = c

# ---------- 5. IF ¿Payload OK? entre Order payload y WC crear pedido ----------
op_node = node('Order payload')
w['nodes'].append({
    "parameters": {"conditions": {"options": {"caseSensitive": True, "leftValue": "", "typeValidation": "loose", "version": 2},
        "conditions": [{"id": "pok1", "leftValue": "={{ !$json.error }}", "rightValue": "true",
            "operator": {"type": "boolean", "operation": "true", "singleValue": True}}], "combinator": "and"}, "options": {}},
    "id": "d1a2b3c4-0002-4000-8000-000000000002", "name": "¿Payload OK?",
    "type": "n8n-nodes-base.if", "typeVersion": 2.2,
    "position": [op_node['position'][0] + 160, op_node['position'][1]]})
assert [c['node'] for c in w['connections']['Order payload']['main'][0]] == ['WC crear pedido']
w['connections']['Order payload']['main'][0] = [{"node": "¿Payload OK?", "type": "main", "index": 0}]
w['connections']['¿Payload OK?'] = {"main": [[{"node": "WC crear pedido", "type": "main", "index": 0}],
                                              [{"node": "Resultado", "type": "main", "index": 0}]]}

json.dump(w, open(dst, 'w'), ensure_ascii=False, indent=1)
print('OK nodos', len(w['nodes']))
```

- [ ] **Step 5: Aplicar el parche en local y correr el arnés — debe pasar**

```bash
python3 docs/n8n/patches/2026-09-03-ventas-asesores-api.py /tmp/cwVentaApi01.json /tmp/cwVentaApi01.new.json
node docs/n8n/harness/ventas_asesores_api.js /tmp/cwVentaApi01.new.json
```
Esperado: `OK nodos 46` y `>>> todo verde` (15 checks).

- [ ] **Step 6: Diff estructural**

```bash
python3 - <<'EOF'
import json
a=json.load(open('/tmp/cwVentaApi01.json')); a=a[0] if isinstance(a,list) else a
b=json.load(open('/tmp/cwVentaApi01.new.json'))
A={n['name']:n for n in a['nodes']}; B={n['name']:n for n in b['nodes']}
print('nodos',len(A),'->',len(B))
print('NUEVOS    ',[k for k in B if k not in A])
print('BORRADOS  ',[k for k in A if k not in B])
print('MODIFICADOS',[k for k in A if k in B and json.dumps(A[k],sort_keys=True)!=json.dumps(B[k],sort_keys=True)])
ca={k:[[c['node'] for c in br] for br in v['main']] for k,v in a['connections'].items()}
cb={k:[[c['node'] for c in br] for br in v['main']] for k,v in b['connections'].items()}
print('CABLES cambiados',[k for k in set(ca)|set(cb) if ca.get(k)!=cb.get(k)])
EOF
```
Esperado: `44 -> 46`; NUEVOS `['Agente → vendedor', '¿Payload OK?']`; BORRADOS `[]`; MODIFICADOS `['Prefill build', 'Order payload', 'Resultado']`; CABLES `WH Venta, Agente → vendedor, Order payload, ¿Payload OK?`.

- [ ] **Step 7: Commit (script + arnés; el export NO)**

```bash
git add docs/n8n/patches/2026-09-03-ventas-asesores-api.py docs/n8n/harness/ventas_asesores_api.js
git commit -m "feat(n8n): parche y arnés de cwVentaApi01 — vendedor por agente, _ccm_canal_venta, guard anti-fantasma"
```

---

### Task 6: desplegar `cwVentaApi01` — **REQUIERE GO**

**Files:**
- Deploy: `/tmp/cwVentaApi01.new.json` → n8n
- Create: `docs/n8n/cwVentaApi01.ADDENDUM.md`

- [ ] **Step 1: Pedir el GO**

Mostrar al dueño el diff estructural del Task 5 Step 6 y el resultado del arnés (viejo falla / nuevo verde). Esperar **GO explícito**.

- [ ] **Step 2: Backup, import, publish, reactivar, verificar**

```bash
scp /tmp/cwVentaApi01.new.json root@2.24.202.75:/root/cwVentaApi01.new.json
ssh root@2.24.202.75 "cp /tmp/cwVentaApi01.json /root/backups/cwVentaApi01_PRE_ASESORES_20260903.json 2>/dev/null || docker exec n8n-n8n-1 cat /tmp/cwVentaApi01.json > /root/backups/cwVentaApi01_PRE_ASESORES_20260903.json; ls -l /root/backups/cwVentaApi01_PRE_ASESORES_20260903.json
docker cp /root/cwVentaApi01.new.json n8n-n8n-1:/tmp/venta.new.json
docker exec n8n-n8n-1 sh -c 'n8n import:workflow --input=/tmp/venta.new.json && n8n publish:workflow --id=cwVentaApi01' 2>&1 | grep -iE 'imported|Publishing'
python3 - <<'EOF'
import json, urllib.request
H={'X-N8N-API-KEY': open('/root/.n8n_api_key').read().strip(),'Content-Type':'application/json'}
for a in ['deactivate','activate']:
    print(a, urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:5678/api/v1/workflows/cwVentaApi01/'+a,method='POST',headers=H,data=b'{}'),timeout=30).status)
EOF
docker exec -i n8n-n8n-1 node -e \"
const sqlite3=require('/usr/local/lib/node_modules/n8n/node_modules/sqlite3');
const db=new sqlite3.Database('/home/node/.n8n/database.sqlite', sqlite3.OPEN_READONLY);
db.get('select active,activeVersionId from workflow_entity where id=?',['cwVentaApi01'],(e,w)=>{ db.get('select nodes from workflow_history where workflowId=? and versionId=?',['cwVentaApi01',w.activeVersionId],(e2,h)=>{ const pub=JSON.parse(h.nodes); console.log('publicado: active',w.active,'nodos',pub.length,'| nuevos presentes:',['Agente → vendedor','¿Payload OK?'].every(x=>pub.some(n=>n.name===x)));
 db.all(\\\"select webhookPath from webhook_entity where workflowId='cwVentaApi01'\\\",[],(e3,r)=>{console.log('webhook registrado:',JSON.stringify(r));db.close();}); }); });
\"
rm -f /root/cwVentaApi01.new.json; docker exec -u 0 n8n-n8n-1 rm -f /tmp/venta.new.json"
```
Esperado: `publicado: active 1 nodos 46 | nuevos presentes: true` y `webhook registrado: [{"webhookPath":"ccm-venta-api-<token>"}]`. Si el webhook sale `[]`, repetir deactivate/activate.

- [ ] **Step 3: Smoke real de `prefill` (no crea nada)**

```bash
ssh root@2.24.202.75 "curl -s -X POST 'http://127.0.0.1:5678/webhook/ccm-venta-api-<token>' -H 'Content-Type: application/json' -d '{\"action\":\"prefill\",\"conv\":\"35979\",\"agente\":{\"email\":\"heider@ccmtiendadelsonido.com\",\"name\":\"Heider\"}}' | python3 -c 'import sys,json; d=json.load(sys.stdin); print({k:d.get(k) for k in (\"ok\",\"vendedor_id\",\"vendedor_nombre\",\"ccosto_id\",\"ccosto_nombre\",\"agente_email\")})'"
```
Esperado: `{'ok': True, 'vendedor_id': 3, 'vendedor_nombre': 'Heider Arrieta', 'ccosto_id': 3, 'ccosto_nombre': 'Ventas Virtuales Personas CCM', 'agente_email': 'heider@ccmtiendadelsonido.com'}`.

- [ ] **Step 4: Smoke del guard anti-fantasma (no debe crear pedido)**

```bash
ssh root@2.24.202.75 "curl -s -X POST 'http://127.0.0.1:5678/webhook/ccm-venta-api-<token>' -H 'Content-Type: application/json' -d '{\"action\":\"crear\",\"conv\":\"35979\",\"agente\":{\"email\":\"nadie@otro.com\"},\"form\":{\"nombre\":\"Prueba guard\",\"items\":[{\"sku\":\"CCM1119\",\"qty\":1}],\"entrega\":\"recogida\",\"vendedor_alegra_id\":\"\",\"centro_costo_id\":\"\"}}'"
ssh ccm-web 'cd ~/public_html && wp db query "select ID,post_status,post_date from wp_posts where post_type=\"shop_order\" and post_date >= date_sub(now(), interval 3 minute)" 2>/dev/null'
```
Esperado: respuesta `{"ok":false,"error":"sin_vendedor: …"}`, **cero** pedidos nuevos en WooCommerce, y en la conversación 35979 una nota privada `Venta: sin_vendedor…` (comprobar en Chatwoot o con `select content from messages where conversation_id=(select id from conversations where display_id=35979) and private order by id desc limit 1`).

- [ ] **Step 5: Addendum y commit**

`docs/n8n/cwVentaApi01.ADDENDUM.md`:

```markdown
# cwVentaApi01 — addendum 2026-09-03 (ventas de asesores)

44 → 46 nodos. Backup: `/root/backups/cwVentaApi01_PRE_ASESORES_20260903.json`.

- **`Agente → vendedor`** (nuevo, entre `WH Venta` y `¿Prefill?`): único mapa correo de Chatwoot → vendedor/centro de costo de Alegra. Emite `agente_resuelto`. Desconocido → `vendedor_id: null`.
- **`Prefill build`**: devuelve `vendedor_id/nombre`, `ccosto_id/nombre`, `agente_email` para precargar el popup.
- **`Order payload`** (v14): sin default al bot. `es_bot` > popup > agente. Sin vendedor → `sin_vendedor`. Metas nuevas `_ccm_canal_venta` (`bot` si vendedor 9, si no `asesor`) y `_ccm_agente_chatwoot`. `_ccm_origen` intacto.
- **`¿Payload OK?`** (nuevo): corta el camino a `WC crear pedido` cuando `Order payload` devuelve `error`. Antes, el error viajaba como `order_body || {}` y **creaba un pedido vacío en processing** (#34114, #34158, #34449).
- **`Resultado`**: en error devuelve `conv` (la nota privada iba a `/conversations/undefined`); en éxito avisa si el agente no está en el mapa.

Arnés: `docs/n8n/harness/ventas_asesores_api.js` (falla contra el export anterior, verde contra el nuevo). Parche: `docs/n8n/patches/2026-09-03-ventas-asesores-api.py`.
```

```bash
git add docs/n8n/cwVentaApi01.ADDENDUM.md
git commit -m "docs(n8n): addendum cwVentaApi01 ventas de asesores (desplegado con GO 2026-09-03)"
```

---

### Task 7: popup `cwVentaPage01` — parche del HTML + arnés en navegador

**Files:**
- Create: `docs/n8n/patches/2026-09-03-ventas-asesores-popup.py`
- Create: `docs/n8n/harness/ventas_asesores_popup.html`
- Input (no commiteado): `/tmp/cwVentaPage01.json`

**Interfaces:**
- Consumes: respuesta de `prefill` con `vendedor_id, ccosto_id` (Task 5); acepta `agente` en `prefill`/`crear`; `form.es_bot`.
- Produces: HTML con `<input type="checkbox" id="es_bot">`, selects `vendedor`/`ccosto` sin default y con opción vacía, escucha de `appContext`, envío de `agente` y `es_bot`.

- [ ] **Step 1: Traer el export y verificar draft == publicado**

Igual que Task 5 Step 1 con `cwVentaPage01` (2 nodos). Guardar en `/tmp/cwVentaPage01.json`.

- [ ] **Step 2: Escribir el arnés de navegador**

`docs/n8n/harness/ventas_asesores_popup.html`:

```html
<!doctype html><meta charset="utf-8"><title>Arnés popup Venta</title>
<style>body{font:13px system-ui;margin:12px} #log div{padding:2px 0} .ok{color:#166534} .bad{color:#991b1b} iframe{width:100%;height:420px;border:1px solid #ccc;margin-top:12px}</style>
<h3>Arnés popup Venta — simula el appContext de Chatwoot</h3>
<p>Uso: <code>python3 -m http.server 8765</code> en la carpeta con <code>popup.html</code> (el HTML parchado) y abrir
<code>http://localhost:8765/ventas_asesores_popup.html</code>. La API se sustituye por un stub local (no toca producción).</p>
<div id="log"></div>
<iframe id="f" src="popup.html?conv=1&__stub=1"></iframe>
<script>
const log = (ok, m) => { const d = document.createElement('div'); d.className = ok ? 'ok' : 'bad'; d.textContent = (ok ? 'ok   ' : 'FALLA') + ' ' + m; document.getElementById('log').appendChild(d); };
let pedidos = 0;
window.addEventListener('message', ev => {
  if (ev.data === 'chatwoot-dashboard-app:fetch-info') {
    log(true, 'el popup pide contexto (fetch-info)');
    ev.source.postMessage(JSON.stringify({ event: 'appContext', data: { conversation: { id: 1 }, contact: {}, currentAgent: { id: 12, name: 'Heider', email: 'heider@ccmtiendadelsonido.com' } } }), '*');
    pedidos++;
  }
});
const f = document.getElementById('f');
f.addEventListener('load', () => setTimeout(() => {
  const d = f.contentDocument, w = f.contentWindow;
  const v = d.getElementById('vendedor'), c = d.getElementById('ccosto'), b = d.getElementById('es_bot');
  log(!!b, 'existe la casilla es_bot');
  log(v && v.options[0].value === '' && ![...v.options].some(o => o.defaultSelected && o.value === '9'), 'vendedor: opción vacía primero y el bot ya no es default');
  log(c && c.options[0].value === '' && ![...c.options].some(o => o.defaultSelected && o.value === '10'), 'ccosto: opción vacía primero y IA CCM ya no es default');
  log(w.__ultimoPrefill && w.__ultimoPrefill.agente && w.__ultimoPrefill.agente.email === 'heider@ccmtiendadelsonido.com', 'prefill manda el agente del appContext');
  log(v.value === '3' && c.value === '3', 'precarga vendedor 3 / ccosto 3 desde la respuesta del stub');
  b.checked = true; b.dispatchEvent(new Event('change'));
  log(v.value === '9' && c.value === '10' && v.disabled && c.disabled, 'casilla marcada: 9 / 10 y selects bloqueados');
  b.checked = false; b.dispatchEvent(new Event('change'));
  log(v.value === '3' && c.value === '3' && !v.disabled, 'casilla desmarcada: vuelve al agente y desbloquea');
  v.value = '';
  d.getElementById('f').dispatchEvent(new Event('submit', { cancelable: true }));
  log(/Elige el vendedor/.test(d.getElementById('err').textContent), 'sin vendedor no envía y avisa');
}, 800));
</script>
```

El stub de API vive en el propio popup cuando la URL trae `__stub=1` (lo añade el parche del Step 4): responde `prefill` con `{ok:true, cliente:{}, items:[], vendedor_id:3, vendedor_nombre:'Heider Arrieta', ccosto_id:3, ccosto_nombre:'Ventas Virtuales Personas CCM'}` y `scan` con `{ok:false}`; guarda el último body de `prefill` en `window.__ultimoPrefill`. Fuera del arnés (`__stub` ausente) no existe.

- [ ] **Step 3: Extraer el HTML viejo y comprobar que el arnés falla contra él**

```bash
python3 - <<'EOF'
import json
d=json.load(open('/tmp/cwVentaPage01.json')); w=d[0] if isinstance(d,list) else d
html=[n for n in w['nodes'] if n['name']=='HTML'][0]['parameters']['responseBody']
open('/tmp/harness_popup/popup.html','w').write(html) if __import__('os').makedirs('/tmp/harness_popup',exist_ok=True) is None else None
EOF
cp docs/n8n/harness/ventas_asesores_popup.html /tmp/harness_popup/
cd /tmp/harness_popup && python3 -m http.server 8765 &
```
Abrir `http://localhost:8765/ventas_asesores_popup.html` con la herramienta de navegador (`preview_start url`) y leer `#log`. Esperado con el HTML viejo: **FALLA** en `existe la casilla es_bot`, en los defaults y en `prefill manda el agente` (el viejo ni pide contexto). Si sale todo verde, el arnés no prueba nada: parar.

- [ ] **Step 4: Escribir el parche del HTML**

`docs/n8n/patches/2026-09-03-ventas-asesores-popup.py`:

```python
# -*- coding: utf-8 -*-
"""Cirugia sobre el HTML del nodo `HTML` de cwVentaPage01 (ventas de asesores, 2026-09-03).
Uso: python3 docs/n8n/patches/2026-09-03-ventas-asesores-popup.py /tmp/cwVentaPage01.json /tmp/cwVentaPage01.new.json
Cada ancla debe aparecer EXACTAMENTE una vez."""
import json, sys
src, dst = sys.argv[1], sys.argv[2]
raw = json.load(open(src)); w = raw[0] if isinstance(raw, list) else raw
node = [n for n in w['nodes'] if n['name'] == 'HTML'][0]
h = node['parameters']['responseBody']

def sub(old, new, label):
    global h
    if h.count(old) != 1:
        raise SystemExit('ANCLA no unica (%d): %s' % (h.count(old), label))
    h = h.replace(old, new)

if 'id="es_bot"' in h:
    raise SystemExit('ya parchado')

# 1. selects sin default al bot + opcion vacia + casilla
sub('<select id="vendedor">\n<option value="9" selected>🤖 Bot CCM IA</option>',
    '<select id="vendedor">\n<option value="">— Elegir —</option>\n<option value="9">🤖 Bot CCM IA</option>', 'select vendedor')
sub('<select id="ccosto">\n<option value="10" selected>IA CCM</option>',
    '<select id="ccosto">\n<option value="">— Elegir —</option>\n<option value="10">IA CCM</option>', 'select ccosto')
sub('<label>Vendedor</label>',
    '<label>Vendedor <label style="font-weight:400;margin-left:8px"><input type="checkbox" id="es_bot"> 🤖 Venta cerrada por el bot</label></label>', 'casilla es_bot')

# 2. estado del agente + escucha de appContext + stub para el arnes
sub('var DKEY = "ccm_venta_draft_" + CONV;',
    'var DKEY = "ccm_venta_draft_" + CONV;\n'
    '// 2026-09-03: agente de Chatwoot (appContext) -> vendedor precargado por la API\n'
    'var AGENTE = null, AGENTE_VEND = null;\n'
    'function aplicarVendedor(v){ if (!v) return; if (v.vendedor_id) document.getElementById("vendedor").value = String(v.vendedor_id); if (v.ccosto_id) document.getElementById("ccosto").value = String(v.ccosto_id); }\n'
    'function toggleBot(){ var on = document.getElementById("es_bot").checked; var vs = document.getElementById("vendedor"), cs = document.getElementById("ccosto");\n'
    '  if (on) { vs.value = "9"; cs.value = "10"; } else { vs.value = ""; cs.value = ""; aplicarVendedor(AGENTE_VEND); } vs.disabled = on; cs.disabled = on; }\n'
    'window.addEventListener("message", function(ev){ var d; try { d = JSON.parse(ev.data); } catch(e){ return; }\n'
    '  if (d && d.event === "appContext" && d.data && d.data.currentAgent) { AGENTE = { email: String(d.data.currentAgent.email || ""), name: String(d.data.currentAgent.name || "") }; } });\n'
    'window.parent.postMessage("chatwoot-dashboard-app:fetch-info", "*");\n'
    '// stub SOLO para el arnes local (?__stub=1): no toca la API real\n'
    'if (new URLSearchParams(location.search).get("__stub") === "1") { var __f = window.fetch; window.fetch = function(u, o){ var b = {}; try { b = JSON.parse(o.body); } catch(e){}\n'
    '  if (b.action === "prefill") { window.__ultimoPrefill = b; return Promise.resolve({ json: function(){ return { ok: true, cliente: {}, items: [], vendedor_id: 3, vendedor_nombre: "Heider Arrieta", ccosto_id: 3, ccosto_nombre: "Ventas Virtuales Personas CCM" }; } }); }\n'
    '  return Promise.resolve({ json: function(){ return { ok: false }; } }); }; }', 'estado agente')

# 3. fill(): precarga vendedor/ccosto de la respuesta
sub('  if (c.tipo_documento) document.getElementById("tipodoc").value = c.tipo_documento;',
    '  if (c.tipo_documento) document.getElementById("tipodoc").value = c.tipo_documento;\n'
    '  if (d.vendedor_id || d.ccosto_id) { AGENTE_VEND = { vendedor_id: d.vendedor_id, ccosto_id: d.ccosto_id }; if (!document.getElementById("es_bot").checked) aplicarVendedor(AGENTE_VEND); }', 'fill vendedor')

# 4. arranque: esperar el appContext (max 1,5 s) y mandar agente en prefill
sub('if (!draftRaw) {\n  fetch(API, {method:"POST", headers:{"Content-Type":"application/json"}, body: JSON.stringify({action:"prefill", conv:CONV})})',
    'function arrancar(){\n  fetch(API, {method:"POST", headers:{"Content-Type":"application/json"}, body: JSON.stringify({action:"prefill", conv:CONV, agente: AGENTE})})', 'arranque prefill')
sub('    .catch(function(){ document.getElementById("sub").textContent = "No se pudo precargar — llena manual."; fill({}); runScan(true); });\n}',
    '    .catch(function(){ document.getElementById("sub").textContent = "No se pudo precargar — llena manual."; fill({}); runScan(true); });\n}\n'
    'if (!draftRaw) { var __esperas = 0; (function esperarAgente(){ if (AGENTE || __esperas >= 15) return arrancar(); __esperas++; setTimeout(esperarAgente, 100); })(); }\n'
    'document.getElementById("es_bot").onchange = toggleBot;', 'arranque espera agente')

# 5. onsubmit: exigir vendedor y mandar es_bot + agente
sub('  if (!items.length) { err.textContent = "Agrega al menos un producto con SKU."; return; }',
    '  if (!items.length) { err.textContent = "Agrega al menos un producto con SKU."; return; }\n'
    '  if (!document.getElementById("vendedor").value) { err.textContent = "Elige el vendedor (o marca que la venta la cerró el bot)."; return; }', 'submit exige vendedor')
sub('  form.vendedor_alegra_id = vsel.value;',
    '  form.vendedor_alegra_id = vsel.value;\n  form.es_bot = document.getElementById("es_bot").checked;', 'form es_bot')
sub('body: JSON.stringify({action:"crear", conv:CONV, form:form})',
    'body: JSON.stringify({action:"crear", conv:CONV, form:form, agente: AGENTE})', 'crear agente')

node['parameters']['responseBody'] = h
json.dump(w, open(dst, 'w'), ensure_ascii=False, indent=1)
print('OK bytes', len(h))
```

- [ ] **Step 5: Aplicar, servir el HTML nuevo y correr el arnés — debe pasar**

```bash
python3 docs/n8n/patches/2026-09-03-ventas-asesores-popup.py /tmp/cwVentaPage01.json /tmp/cwVentaPage01.new.json
python3 - <<'EOF'
import json
w=json.load(open('/tmp/cwVentaPage01.new.json'))
open('/tmp/harness_popup/popup.html','w').write([n for n in w['nodes'] if n['name']=='HTML'][0]['parameters']['responseBody'])
EOF
```
Recargar `http://localhost:8765/ventas_asesores_popup.html` y leer `#log`. Esperado: **12 líneas `ok` (11 checks + fetch-info)**, ninguna `FALLA`. Además comprobar en la consola del iframe que no hay errores JS. Parar el `http.server` al terminar (`kill %1`).

- [ ] **Step 6: Commit**

```bash
git add docs/n8n/patches/2026-09-03-ventas-asesores-popup.py docs/n8n/harness/ventas_asesores_popup.html
git commit -m "feat(n8n): popup Venta lee el agente de Chatwoot, casilla 'venta del bot', sin default al bot"
```

---

### Task 8: desplegar `cwVentaPage01` — **REQUIERE GO**

- [ ] **Step 1: Pedir el GO** mostrando el resultado del arnés (viejo falla / nuevo 8 ok) y el tamaño del HTML antes/después.

- [ ] **Step 2: Backup, import, publish, reactivar, verificar**

Mismo bloque que Task 6 Step 2 sustituyendo `cwVentaApi01` → `cwVentaPage01`, `venta.new.json` → `page.new.json`, backup `cwVentaPage01_PRE_ASESORES_20260903.json`, y el check de nodos: `nodos 2 | HTML contiene es_bot: true` (`pub.find(n=>n.name==='HTML').parameters.responseBody.includes('id="es_bot"')`). Webhook esperado: `ccm-venta-page-<token>`.

- [ ] **Step 3: Smoke**

```bash
ssh root@2.24.202.75 "curl -s 'http://127.0.0.1:5678/webhook/ccm-venta-page-<token>?conv=1' | grep -c 'id=\"es_bot\"\|chatwoot-dashboard-app:fetch-info\|— Elegir —'"
```
Esperado: `4` (casilla, fetch-info, y dos «— Elegir —»).

- [ ] **Step 4: Commit de despliegue**

```bash
git commit --allow-empty -m "chore(deploy): popup Venta con atribución por agente desplegado (GO del dueño 2026-09-03)"
```

---

### Task 9: verificación real, PR y espejo

- [ ] **Step 1: Venta de prueba desde Chatwoot (la hace el dueño o un asesor)**

Pedir que Heider abra 💰 Venta en una conversación de prueba y compruebe: vendedor precargado «Heider Arrieta», centro «Ventas Virtuales Personas CCM»; crear un pedido de prueba con `recogida`.

- [ ] **Step 2: Comprobar metas, Alegra e informes**

```bash
ssh ccm-web 'cd ~/public_html && ID=$(wp db query "select max(ID) from wp_posts where post_type=\"shop_order\"" --skip-column-names) && wp post meta list $ID --keys=_ccm_origen,_ccm_canal_venta,_ccm_agente_chatwoot,_ccm_alegra_seller_id,_ccm_alegra_seller_nombre,_ccm_alegra_cost_center_id,_ccm_alegra_invoice_id --format=table 2>/dev/null; wp eval "print_r(CCMCK_Reports::chat_totals(date(\"Y-m-01\"), date(\"Y-m-d\")));" 2>/dev/null'
```
Esperado: `_ccm_canal_venta=asesor`, `_ccm_agente_chatwoot=heider@…`, seller 3, cost center 3; `chat_totals` con `asesor.n ≥ 1`. En Alegra (`invoice_getInvoiceById` del `_ccm_alegra_invoice_id`): `seller.id = 3`, `costCenter.id = 3` («Ventas Virtuales Personas CCM», código 02), `status = draft`. En WooCommerce → Informes → Ventas asesores aparece el pedido; en Ventas no.

- [ ] **Step 3: Cancelar el pedido de prueba y anular su borrador en Alegra** (lo hace el dueño; anotar los ids en el PR).

- [ ] **Step 4: PR**

```bash
git push -u origin feat/ventas-asesores
gh pr create --title "Ventas de asesores por el botón Venta" --body "$(cat <<'EOF'
Spec: docs/superpowers/specs/2026-09-03-ventas-asesores-design.md
Plan: docs/superpowers/plans/2026-09-03-ventas-asesores.md

- Informes: scope de tres valores, pestaña «Ventas asesores» con filtro y resumen por vendedor, aviso con dos cifras.
- n8n cwVentaApi01: nodo Agente → vendedor, _ccm_canal_venta, guard anti-fantasma (¿Payload OK?), conv en la rama de error.
- n8n cwVentaPage01: lee appContext.currentAgent, casilla «venta del bot», sin default al bot.

Desplegado con GO del dueño el 2026-09-03 (plugin prod+dev, API, popup). Backups en /root/backups/*_PRE_ASESORES_20260903.json.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 5: Memoria y espejo**

Actualizar la memoria `project_ccm_ia_e12_v2` / crear `ventas_asesores_boton_venta.md` con: meta `_ccm_canal_venta`, mapa en `Agente → vendedor`, «los históricos cuentan como bot», y el guard anti-fantasma. Regenerar el espejo `01_WORKFLOWS_LIVE` / `02_IMPORT_READY` **solo si el dueño dice «espeja y push»**.

---

## Auto-revisión

**Cobertura del spec.** §1 popup → Task 7/8. §2.1 nodo mapa → Task 5 (script §1). §2.2 prefill → Task 5 (§2). §2.3 Order payload y metas → Task 5 (§3). §2.4 guard → Task 5 (§4-5) + smoke Task 6 Step 4. §3.1-3.3 scope/subqueries → Task 1. §3.4 totales/aviso → Task 2. §3.5 pestañas → Task 3. §3.6 tests → Tasks 1-3. §5 agente desconocido con nota → Task 5 (§4 Resultado). §6 despliegue en tres pasos con GO → Tasks 4, 6, 8. §6.4 verificación real → Task 9.

**Placeholders.** Ninguno: cada paso lleva código o comando y resultado esperado.

**Consistencia de nombres.** `set_scope` / `SCOPE_*` / `chat_orders_subquery` / `asesor_orders_subquery` / `bot_orders_subquery` / `chat_totals` / `vendedor_param` / `resumen_por_vendedor` / `resumen_markup` / `vendedor_select_markup` / `render_asesores_report` iguales en tests e implementación. Nodo `Agente → vendedor` (con flecha → U+2192) igual en parche, arnés y Order payload. Campo `agente_resuelto` igual en los tres nodos que lo leen. `es_bot` igual en popup, arnés y Order payload.

**Corrección hecha al revisar:** en Task 9 Step 2 el centro de costo esperado en Alegra es **3** («Ventas Virtuales Personas CCM»), no 4 («Pagina Web CCM»).
