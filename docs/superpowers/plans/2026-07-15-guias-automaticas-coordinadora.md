# Generación automática de guías Coordinadora — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Al pasar un pedido a "Procesando", generar la guía de Coordinadora automáticamente (mismas cajas del cotizador), guardar nº + rastreo en el pedido, botón de rótulo PDF en el admin, y webhook a n8n para avisar al cliente por WhatsApp.

**Architecture:** Módulo nuevo `CCMCK_Guias` con métodos puros testeables (armado de params según las observaciones de Coordinadora, parseo, payload webhook, guard de generación) + capa fina acoplada (hook de estado, RPC con SHA-256, metabox, AJAX del rótulo). Reutiliza `CCMCK_Coordinadora::pack/build_detalle/dane_from_city` (públicos). Workflow n8n aparte (JSON import-ready).

**Tech Stack:** PHP 7.4+ (WordPress mu-plugin + WooCommerce), PHPUnit 11 vía `php phpunit.phar` (tests sin WP, stubs en `tests/bootstrap.php`), JSON-RPC 2.0 sobre `wp_remote_post`, n8n (webhook + cwSendWa01).

**Spec:** `docs/superpowers/specs/2026-07-15-guias-automaticas-coordinadora-design.md`

## Global Constraints

- Estilo del repo: una clase por archivo, prefijo `CCMCK_`, `defined( 'ABSPATH' ) || exit;`, métodos PUROS donde sea posible.
- **Observaciones de Coordinadora (obligatorias):** `fecha:''`, `nit_remitente:''`, `nombre_remitente` = razón social del ajuste, `ciudad_remitente` = `coordinadora_origin`.
- Endpoints guías: prod `https://guias.coordinadora.com/ws/guias/1.6/server.php`, sandbox `https://sandbox.coordinadora.com/agw/ws/guias/1.6/server.php`. Auth: `usuario` + `clave` = `hash('sha256', $clave_plana)`.
- Metas del pedido: `_coordinadora_tracking_number` (nº guía, compatible plugin viejo), `_coordinadora_tracking_url`, `_ccmck_guia_id_remision`.
- Rate id de pickup a excluir: `ccmck_local_pickup`. Log: `WC_Logger` canal `ccmck-coordinadora`.
- Tests: `php phpunit.phar` (cwd = raíz del plugin). Toda función WP nueva usada en tests necesita stub en `tests/bootstrap.php`.
- Commits acotados por tarea (solo archivos propios; NUNCA `git add -A`). Trailer: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Toggle `guias_enabled` default `false` → deploy inocuo.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `includes/class-ccmck-guias.php` (crear) | Todo el módulo de guías |
| `tests/GuiasTest.php` (crear) | Tests de los métodos puros |
| `tests/bootstrap.php` (modificar) | require de la clase + stubs nuevos |
| `includes/class-ccmck-settings.php` (modificar) | 9 keys nuevas |
| `tests/SettingsTest.php` (modificar) | Tests de las keys |
| `includes/views/settings-page.php` (modificar) | Sección "Generación de guías" |
| `ccm-checkout.php` (modificar) | require + init |
| `docs/n8n/wf-guia-whatsapp.json` (crear) | Workflow n8n import-ready |
| `docs/CHANGELOG.md` (modificar) | Entrada |

---

## Task 1: Settings — sección de guías (9 keys)

**Files:**
- Modify: `includes/class-ccmck-settings.php` (defaults tras el bloque coordinadora; sanitize antes de `return $out;`)
- Test: `tests/SettingsTest.php`

**Interfaces:**
- Produces: keys `guias_enabled` (bool), `guias_env` ('sandbox'|'production'), `guias_usuario`, `guias_clave` (plana), `guias_id_cliente` (int), `guias_remitente_nombre`, `guias_remitente_direccion`, `guias_remitente_telefono` (dígitos), `guias_webhook_url` — vía `CCMCK_Settings::get()`.

- [ ] **Step 1: Write the failing tests**

En `tests/SettingsTest.php`, antes del `}` final:

```php
    public function test_defaults_include_guias_keys(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertFalse( $d['guias_enabled'] );
        $this->assertSame( 'sandbox', $d['guias_env'] );
        $this->assertSame( 'ccmtienda.ws', $d['guias_usuario'] );
        $this->assertSame( '', $d['guias_clave'] );
        $this->assertSame( 49444, $d['guias_id_cliente'] );
        $this->assertSame( 'CCM Tienda del Sonido', $d['guias_remitente_nombre'] );
        $this->assertSame( '', $d['guias_webhook_url'] );
    }

    public function test_sanitize_guias_env_whitelist(): void {
        $this->assertSame( 'production', CCMCK_Settings::sanitize( array( 'guias_env' => 'production' ) )['guias_env'] );
        $this->assertSame( 'sandbox', CCMCK_Settings::sanitize( array( 'guias_env' => 'otro' ) )['guias_env'] );
        $this->assertSame( 'sandbox', CCMCK_Settings::sanitize( array() )['guias_env'] );
    }

    public function test_sanitize_guias_id_cliente_and_phone(): void {
        $out = CCMCK_Settings::sanitize( array( 'guias_id_cliente' => '49444x', 'guias_remitente_telefono' => '+57 317-811' ) );
        $this->assertSame( 49444, $out['guias_id_cliente'] );
        $this->assertSame( '57317811', $out['guias_remitente_telefono'] );
    }

    public function test_sanitize_guias_webhook_url(): void {
        $this->assertSame( 'https://n8n.x.co/webhook/abc', CCMCK_Settings::sanitize( array( 'guias_webhook_url' => 'https://n8n.x.co/webhook/abc' ) )['guias_webhook_url'] );
        $this->assertSame( '', CCMCK_Settings::sanitize( array( 'guias_webhook_url' => 'javascript:x' ) )['guias_webhook_url'] );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar tests/SettingsTest.php`
Expected: FAIL (`Undefined array key "guias_enabled"`).

- [ ] **Step 3: Add defaults**

En `defaults()`, tras el bloque `'coordinadora_box_rules' => array(),`:

```php
            // Generación de guías (ver spec 2026-07-15). Clave se guarda plana; se
            // hashea SHA-256 al llamar el WS.
            'guias_enabled'             => false,
            'guias_env'                 => 'sandbox',
            'guias_usuario'             => 'ccmtienda.ws',
            'guias_clave'               => '',
            'guias_id_cliente'          => 49444,
            'guias_remitente_nombre'    => 'CCM Tienda del Sonido',
            'guias_remitente_direccion' => '',
            'guias_remitente_telefono'  => '',
            'guias_webhook_url'         => '',
```

- [ ] **Step 4: Add sanitize**

En `sanitize()`, tras el bloque de `coordinadora_box_rules` y antes de `return $out;`:

```php
        $out['guias_enabled'] = ! empty( $input['guias_enabled'] );
        $env                  = (string) ( $input['guias_env'] ?? 'sandbox' );
        $out['guias_env']     = in_array( $env, array( 'sandbox', 'production' ), true ) ? $env : 'sandbox';
        $out['guias_usuario'] = sanitize_text_field( $input['guias_usuario'] ?? $d['guias_usuario'] );
        $out['guias_clave']   = sanitize_text_field( $input['guias_clave'] ?? '' );
        $out['guias_id_cliente'] = absint( $input['guias_id_cliente'] ?? $d['guias_id_cliente'] );
        $out['guias_remitente_nombre']    = sanitize_text_field( $input['guias_remitente_nombre'] ?? $d['guias_remitente_nombre'] );
        $out['guias_remitente_direccion'] = sanitize_text_field( $input['guias_remitente_direccion'] ?? '' );
        $out['guias_remitente_telefono']  = preg_replace( '/[^0-9]/', '', (string) ( $input['guias_remitente_telefono'] ?? '' ) );
        $out['guias_webhook_url']         = esc_url_raw( $input['guias_webhook_url'] ?? '' );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php phpunit.phar tests/SettingsTest.php` → PASS. Luego `php phpunit.phar` → **OK** total.

- [ ] **Step 6: Commit**

```bash
git add includes/class-ccmck-settings.php tests/SettingsTest.php
git commit -m "feat(settings): ajustes de generación de guías Coordinadora

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 2: Scaffold `CCMCK_Guias` + `build_guia_params` + `should_generate`

**Files:**
- Create: `includes/class-ccmck-guias.php`
- Modify: `tests/bootstrap.php` (require de la clase, tras el de coordinadora)
- Test: `tests/GuiasTest.php` (crear)

**Interfaces:**
- Produces:
  - `CCMCK_Guias::build_guia_params( array $args ): array` — `$args = {usuario, clave_sha256, id_cliente, remitente:{nombre,direccion,telefono,ciudad}, destinatario:{nombre,direccion,ciudad_dane,telefono,documento}, valor_declarado, contenido, referencia, observaciones, detalle}`.
  - `CCMCK_Guias::should_generate( array $ctx ): array{ok:bool, reason:string}` — `$ctx = {enabled:bool, usuario:string, clave:string, shipping_ids:string[], existing_guia:string, has_lock:bool}`.
  - `const META_GUIA = '_coordinadora_tracking_number'`, `const META_URL = '_coordinadora_tracking_url'`, `const META_ID = '_ccmck_guia_id_remision'`.

- [ ] **Step 1: Write the failing tests**

Crear `tests/GuiasTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class GuiasTest extends TestCase {
    private function args(): array {
        return array(
            'usuario'      => 'ccmtienda.ws',
            'clave_sha256' => str_repeat( 'a', 64 ),
            'id_cliente'   => 49444,
            'remitente'    => array( 'nombre' => 'CCM Tienda del Sonido', 'direccion' => 'Calle 45 30-50', 'telefono' => '3178119077', 'ciudad' => '08001000' ),
            'destinatario' => array( 'nombre' => 'Cliente Prueba', 'direccion' => 'Cra 1J 45-31 apto 2', 'ciudad_dane' => '05001000', 'telefono' => '3014373975', 'documento' => '1002157685' ),
            'valor_declarado' => 675000,
            'contenido'    => 'Equipos de sonido',
            'referencia'   => '1234',
            'observaciones'=> '',
            'detalle'      => array( array( 'ubl' => 0, 'alto' => 50.0, 'ancho' => 50.0, 'largo' => 50.0, 'peso' => 20.0, 'unidades' => 1 ) ),
        );
    }

    // --- build_guia_params: observaciones obligatorias de Coordinadora ---
    public function test_params_meet_coordinadora_observations(): void {
        $p = CCMCK_Guias::build_guia_params( $this->args() );
        $this->assertSame( '', $p['fecha'] );                    // obs 1
        $this->assertSame( '', $p['nit_remitente'] );            // obs 2
        $this->assertSame( 'CCM Tienda del Sonido', $p['nombre_remitente'] ); // obs 3
        $this->assertSame( '08001000', $p['ciudad_remitente'] ); // obs 4
        $this->assertSame( 'IMPRESO', $p['estado'] );
        $this->assertSame( 0, $p['id_remitente'] );
        $this->assertSame( array(), $p['recaudos'] );
        $this->assertSame( 2, $p['codigo_cuenta'] );
        $this->assertSame( 0, $p['codigo_producto'] );
        $this->assertSame( 1, $p['nivel_servicio'] );
        $this->assertSame( 49444, $p['id_cliente'] );
    }

    public function test_params_map_destinatario_and_detail(): void {
        $p = CCMCK_Guias::build_guia_params( $this->args() );
        $this->assertSame( 'Cliente Prueba', $p['nombre_destinatario'] );
        $this->assertSame( '05001000', $p['ciudad_destinatario'] );
        $this->assertSame( '1002157685', $p['nit_destinatario'] );
        $this->assertSame( '', $p['div_destinatario'] );
        $this->assertSame( 675000, $p['valor_declarado'] );
        $this->assertSame( '1234', $p['referencia'] );
        $this->assertCount( 1, $p['detalle'] );
        $this->assertSame( 'Caja', $p['detalle'][0]['nombre_empaque'] );
        $this->assertSame( '', $p['detalle'][0]['referencia'] );
        $this->assertSame( str_repeat( 'a', 64 ), $p['clave'] );
    }

    // --- should_generate ---
    private function ctx( array $over = array() ): array {
        return array_merge( array(
            'enabled'       => true,
            'usuario'       => 'ccmtienda.ws',
            'clave'         => 'x',
            'shipping_ids'  => array( 'ccmck_coordinadora' ),
            'existing_guia' => '',
            'has_lock'      => false,
        ), $over );
    }

    public function test_should_generate_ok(): void {
        $this->assertTrue( CCMCK_Guias::should_generate( $this->ctx() )['ok'] );
    }
    public function test_should_generate_blocks_disabled(): void {
        $r = CCMCK_Guias::should_generate( $this->ctx( array( 'enabled' => false ) ) );
        $this->assertFalse( $r['ok'] );
    }
    public function test_should_generate_blocks_pickup(): void {
        $r = CCMCK_Guias::should_generate( $this->ctx( array( 'shipping_ids' => array( 'ccmck_local_pickup' ) ) ) );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'recogida', strtolower( $r['reason'] ) );
    }
    public function test_should_generate_blocks_existing_guia(): void {
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'existing_guia' => '33042000009' ) ) )['ok'] );
    }
    public function test_should_generate_blocks_lock_and_missing_creds(): void {
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'has_lock' => true ) ) )['ok'] );
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'clave' => '' ) ) )['ok'] );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar tests/GuiasTest.php`
Expected: FAIL — `Class "CCMCK_Guias" not found`.

- [ ] **Step 3: Create the class with both pure methods**

Crear `includes/class-ccmck-guias.php`:

```php
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
```

- [ ] **Step 4: Register in bootstrap**

En `tests/bootstrap.php`, tras `require_once ... class-ccmck-coordinadora.php;`:

```php
require_once dirname( __DIR__ ) . '/includes/class-ccmck-guias.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php phpunit.phar tests/GuiasTest.php` → PASS (8 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-ccmck-guias.php tests/GuiasTest.php tests/bootstrap.php
git commit -m "feat(guias): scaffold CCMCK_Guias + build_guia_params + should_generate

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 3: `parse_guia_response` + `build_webhook_payload`

**Files:**
- Modify: `includes/class-ccmck-guias.php`
- Test: `tests/GuiasTest.php`

**Interfaces:**
- Produces:
  - `parse_guia_response( string $body, $http_code ): array{ok, codigo_remision, id_remision, tracking_url, error}`
  - `build_webhook_payload( array $args ): array{order_id, order_number, guia, tracking_url, customer:{name, phone}, total}`

- [ ] **Step 1: Write the failing tests**

En `tests/GuiasTest.php`, antes del `}` final:

```php
    // --- parse_guia_response ---
    public function test_parse_guia_success(): void {
        $body = '{"jsonrpc":2,"id":0,"error":null,"result":{"id_remision":48454758,"codigo_remision":"33042000009","pdf_guia":"","url_terceros":"http://x.co/vmi/?guia=330","referencia":"1234"}}';
        $r = CCMCK_Guias::parse_guia_response( $body, 200 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( '33042000009', $r['codigo_remision'] );
        $this->assertSame( 48454758, $r['id_remision'] );
        $this->assertSame( 'http://x.co/vmi/?guia=330', $r['tracking_url'] );
    }

    public function test_parse_guia_business_error(): void {
        $r = CCMCK_Guias::parse_guia_response( '{"jsonrpc":2,"id":0,"error":{"code":"-1","message":"Exception: Usuario o clave invalido"}}', 200 );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'invalido', $r['error'] );
    }

    public function test_parse_guia_non_json_and_missing_code(): void {
        $this->assertFalse( CCMCK_Guias::parse_guia_response( '<b>Fatal</b>', 200 )['ok'] );
        $this->assertFalse( CCMCK_Guias::parse_guia_response( '{"jsonrpc":2,"result":{"codigo_remision":""}}', 200 )['ok'] );
    }

    // --- build_webhook_payload ---
    public function test_webhook_payload_shape(): void {
        $p = CCMCK_Guias::build_webhook_payload( array(
            'order_id' => 55, 'order_number' => '1234', 'guia' => '33042000009',
            'tracking_url' => 'http://x.co/t', 'name' => 'Cliente Prueba',
            'phone' => '3014373975', 'total' => 693290.0,
        ) );
        $this->assertSame( 55, $p['order_id'] );
        $this->assertSame( '1234', $p['order_number'] );
        $this->assertSame( '33042000009', $p['guia'] );
        $this->assertSame( 'http://x.co/t', $p['tracking_url'] );
        $this->assertSame( 'Cliente Prueba', $p['customer']['name'] );
        $this->assertSame( '3014373975', $p['customer']['phone'] );
        $this->assertSame( 693290.0, $p['total'] );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar tests/GuiasTest.php` → FAIL (`undefined method parse_guia_response`).

- [ ] **Step 3: Implement both methods**

En `includes/class-ccmck-guias.php`, antes del `}` de cierre de la clase:

```php
    /**
     * Parsea la respuesta de Guias.generarGuia. HTTP siempre 200: falló si el
     * body no es JSON, error !== null o no llega codigo_remision. PURO.
     *
     * @return array{ok:bool, codigo_remision:string, id_remision:int, tracking_url:string, error:string}
     */
    public static function parse_guia_response( string $body, $http_code ): array {
        $fail = array( 'ok' => false, 'codigo_remision' => '', 'id_remision' => 0, 'tracking_url' => '', 'error' => '' );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            $fail['error'] = 'Respuesta no-JSON del WS de guías (HTTP ' . (int) $http_code . ')';
            return $fail;
        }
        if ( isset( $data['error'] ) && null !== $data['error'] ) {
            $msg = is_array( $data['error'] ) ? ( $data['error']['message'] ?? 'error' ) : (string) $data['error'];
            $fail['error'] = (string) $msg;
            return $fail;
        }
        $result = $data['result'] ?? null;
        $codigo = is_array( $result ) ? (string) ( $result['codigo_remision'] ?? '' ) : '';
        if ( '' === $codigo ) {
            $fail['error'] = 'Respuesta sin codigo_remision';
            return $fail;
        }
        return array(
            'ok'              => true,
            'codigo_remision' => $codigo,
            'id_remision'     => (int) ( $result['id_remision'] ?? 0 ),
            'tracking_url'    => (string) ( $result['url_terceros'] ?? '' ),
            'error'           => '',
        );
    }

    /** Payload del webhook a n8n (aviso WhatsApp). PURO. */
    public static function build_webhook_payload( array $args ): array {
        return array(
            'order_id'     => (int) ( $args['order_id'] ?? 0 ),
            'order_number' => (string) ( $args['order_number'] ?? '' ),
            'guia'         => (string) ( $args['guia'] ?? '' ),
            'tracking_url' => (string) ( $args['tracking_url'] ?? '' ),
            'customer'     => array(
                'name'  => (string) ( $args['name'] ?? '' ),
                'phone' => (string) ( $args['phone'] ?? '' ),
            ),
            'total'        => (float) ( $args['total'] ?? 0 ),
        );
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php phpunit.phar tests/GuiasTest.php` → PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-guias.php tests/GuiasTest.php
git commit -m "feat(guias): parse_guia_response y build_webhook_payload

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 4: Capa acoplada — RPC, items del pedido, hook `on_processing`, webhook, registro

**Files:**
- Modify: `includes/class-ccmck-guias.php`
- Modify: `ccm-checkout.php` (require tras coordinadora + `CCMCK_Guias::init()` tras `CCMCK_Coordinadora::init()`)

**Interfaces:**
- Consumes: `CCMCK_Coordinadora::pack/build_detalle/dane_from_city` (públicos); `CCMCK_Settings::get()`; keys de Task 1 + `coordinadora_origin`, `coordinadora_weight_threshold`, `coordinadora_box_rules`.
- Produces: `CCMCK_Guias::init()`; guía generada al pasar a Procesando; metas + nota + webhook.
- Sin tests unitarios (WC/HTTP); la lógica decisoria ya está cubierta por `should_generate` (Task 2). Verificación manual en sandbox (Task 7).

- [ ] **Step 1: Implement the coupled layer**

En `includes/class-ccmck-guias.php`, antes del `}` de cierre:

```php
    /** Log al canal de WooCommerce. */
    private static function log( string $msg ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning( $msg, array( 'source' => 'ccmck-coordinadora' ) );
        }
    }

    /** Endpoint según el ambiente configurado. */
    private static function endpoint(): string {
        return 'production' === CCMCK_Settings::get( 'guias_env', 'sandbox' )
            ? self::ENDPOINT_PROD
            : self::ENDPOINT_SANDBOX;
    }

    /** Llama un método del WS de guías. Devuelve el body crudo o WP_Error. */
    private static function rpc( string $method, array $params, int $timeout = 15 ) {
        return wp_remote_post( self::endpoint(), array(
            'timeout' => $timeout,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 0, 'method' => $method, 'params' => $params ) ),
        ) );
    }

    /** Mapa cat_id => N de las reglas de caja (mismo formato que el cotizador). */
    private static function rules_map(): array {
        $map = array();
        foreach ( (array) CCMCK_Settings::get( 'coordinadora_box_rules', array() ) as $row ) {
            $cat = (int) ( $row['cat'] ?? 0 );
            $n   = (int) ( $row['n'] ?? 0 );
            if ( $cat > 0 && $n > 0 ) {
                $map[ $cat ] = $n;
            }
        }
        return $map;
    }

    /**
     * Normaliza los items del pedido a la forma de CCMCK_Coordinadora::pack().
     * Devuelve array{items:array, missing:string} (missing = primer SKU/nombre
     * sin peso o dimensiones, '' si todo bien).
     */
    private static function items_from_order( $order ): array {
        $items   = array();
        $missing = '';
        foreach ( $order->get_items() as $line ) {
            $product = is_callable( array( $line, 'get_product' ) ) ? $line->get_product() : null;
            if ( ! $product ) {
                continue;
            }
            $it = array(
                'qty'     => (int) $line->get_quantity(),
                'weight'  => (float) $product->get_weight(),
                'largo'   => (float) $product->get_length(),
                'ancho'   => (float) $product->get_width(),
                'alto'    => (float) $product->get_height(),
                'cat_ids' => array_map( 'intval', (array) ( function_exists( 'wc_get_product_cat_ids' ) ? wc_get_product_cat_ids( $product->get_id() ) : array() ) ),
            );
            if ( '' === $missing && ( $it['weight'] <= 0 || $it['largo'] <= 0 || $it['ancho'] <= 0 || $it['alto'] <= 0 ) ) {
                $missing = $product->get_sku() ? $product->get_sku() : $product->get_name();
            }
            $items[] = $it;
        }
        return array( 'items' => $items, 'missing' => $missing );
    }

    /** Hook woocommerce_order_status_processing: genera la guía. */
    public static function on_processing( $order_id ): void {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            return;
        }

        $shipping_ids = array();
        foreach ( $order->get_shipping_methods() as $sm ) {
            $shipping_ids[] = (string) $sm->get_method_id();
        }

        $check = self::should_generate( array(
            'enabled'       => (bool) CCMCK_Settings::get( 'guias_enabled', false ),
            'usuario'       => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'         => (string) CCMCK_Settings::get( 'guias_clave', '' ),
            'shipping_ids'  => $shipping_ids,
            'existing_guia' => (string) $order->get_meta( self::META_GUIA ),
            'has_lock'      => false !== get_transient( 'ccmck_guia_lock_' . $order_id ),
        ) );
        if ( ! $check['ok'] ) {
            // Silencioso para off/pickup/duplicado; son casos normales.
            return;
        }
        set_transient( 'ccmck_guia_lock_' . $order_id, 1, 60 );

        $extracted = self::items_from_order( $order );
        if ( ! $extracted['items'] || '' !== $extracted['missing'] ) {
            $order->add_order_note( 'Guía Coordinadora NO generada: producto sin peso/medidas (' . $extracted['missing'] . '). Generar manualmente.' );
            self::log( 'Guía pedido #' . $order_id . ': producto sin peso/medidas (' . $extracted['missing'] . ')' );
            return;
        }

        $destino = CCMCK_Coordinadora::dane_from_city( (string) $order->get_billing_city() );
        if ( '' === $destino ) {
            $order->add_order_note( 'Guía Coordinadora NO generada: no se pudo extraer el código DANE de la ciudad. Generar manualmente.' );
            self::log( 'Guía pedido #' . $order_id . ': ciudad sin DANE' );
            return;
        }

        $threshold = (float) CCMCK_Settings::get( 'coordinadora_weight_threshold', 5.0 );
        $boxes     = CCMCK_Coordinadora::pack( $extracted['items'], $threshold, self::rules_map() );
        $detalle   = CCMCK_Coordinadora::build_detalle( $boxes );

        $total_lineas = 0.0;
        foreach ( $order->get_items() as $line ) {
            $total_lineas += (float) $line->get_total();
        }

        $direccion = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );

        $params = self::build_guia_params( array(
            'usuario'      => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave_sha256' => hash( 'sha256', (string) CCMCK_Settings::get( 'guias_clave', '' ) ),
            'id_cliente'   => (int) CCMCK_Settings::get( 'guias_id_cliente', 49444 ),
            'remitente'    => array(
                'nombre'    => (string) CCMCK_Settings::get( 'guias_remitente_nombre', '' ),
                'direccion' => (string) CCMCK_Settings::get( 'guias_remitente_direccion', '' ),
                'telefono'  => (string) CCMCK_Settings::get( 'guias_remitente_telefono', '' ),
                'ciudad'    => (string) CCMCK_Settings::get( 'coordinadora_origin', '08001000' ),
            ),
            'destinatario' => array(
                'nombre'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'direccion'   => $direccion,
                'ciudad_dane' => $destino,
                'telefono'    => (string) $order->get_billing_phone(),
                'documento'   => (string) $order->get_meta( '_billing_document_number' ),
            ),
            'valor_declarado' => (int) round( $total_lineas ),
            'contenido'    => 'Equipos de sonido',
            'referencia'   => (string) $order->get_order_number(),
            'observaciones'=> (string) $order->get_customer_note(),
            'detalle'      => $detalle,
        ) );

        $response = self::rpc( 'Guias.generarGuia', $params );
        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'Guía Coordinadora NO generada (HTTP): ' . $response->get_error_message() );
            self::log( 'Guía pedido #' . $order_id . ' HTTP: ' . $response->get_error_message() );
            return;
        }
        $parsed = self::parse_guia_response( (string) wp_remote_retrieve_body( $response ), wp_remote_retrieve_response_code( $response ) );
        if ( ! $parsed['ok'] ) {
            $order->add_order_note( 'Guía Coordinadora NO generada: ' . $parsed['error'] );
            self::log( 'Guía pedido #' . $order_id . ' API: ' . $parsed['error'] );
            return;
        }

        $order->update_meta_data( self::META_GUIA, $parsed['codigo_remision'] );
        $order->update_meta_data( self::META_URL, $parsed['tracking_url'] );
        $order->update_meta_data( self::META_ID, (string) $parsed['id_remision'] );
        $order->save();
        $order->add_order_note( 'Guía Coordinadora generada: ' . $parsed['codigo_remision'] );

        self::send_webhook( self::build_webhook_payload( array(
            'order_id'     => (int) $order_id,
            'order_number' => (string) $order->get_order_number(),
            'guia'         => $parsed['codigo_remision'],
            'tracking_url' => $parsed['tracking_url'],
            'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'phone'        => (string) $order->get_billing_phone(),
            'total'        => (float) $order->get_total(),
        ) ) );
    }

    /** Webhook a n8n (aviso WhatsApp). Fire-and-forget: el fallo solo se loguea. */
    private static function send_webhook( array $payload ): void {
        $url = (string) CCMCK_Settings::get( 'guias_webhook_url', '' );
        if ( '' === $url ) {
            return;
        }
        $response = wp_remote_post( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $response ) ) {
            self::log( 'Webhook n8n falló (pedido #' . $payload['order_id'] . '): ' . $response->get_error_message() );
        }
    }

    public static function init(): void {
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_processing' ), 20 );
    }
```

- [ ] **Step 2: Register the module**

En `ccm-checkout.php`: tras `require_once CCMCK_DIR . 'includes/class-ccmck-coordinadora.php';`:

```php
require_once CCMCK_DIR . 'includes/class-ccmck-guias.php';
```

Y en `ccmck_boot()`, tras `CCMCK_Coordinadora::init();`:

```php
    CCMCK_Guias::init();
```

- [ ] **Step 3: Full suite + lint**

Run: `php phpunit.phar` → **OK** (sin regresiones).
Run: `php -l includes/class-ccmck-guias.php && php -l ccm-checkout.php` → sin errores.

- [ ] **Step 4: Commit**

```bash
git add includes/class-ccmck-guias.php ccm-checkout.php
git commit -m "feat(guias): hook de generación al pasar a Procesando + webhook n8n

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 5: Rótulo PDF — caja en el pedido + AJAX de descarga

**Files:**
- Modify: `includes/class-ccmck-guias.php`
- Test: `tests/GuiasTest.php` (solo el markup puro)

**Interfaces:**
- Produces: `guia_box_markup( string $guia, string $tracking_url, string $label_url ): string` (PURO); acción AJAX `ccmck_guia_label`; render vía hook `woocommerce_admin_order_data_after_billing_address` (mismo patrón que `CCMCK_Document::render_admin`).

- [ ] **Step 1: Write the failing test**

En `tests/GuiasTest.php`, antes del `}` final:

```php
    // --- guia_box_markup ---
    public function test_guia_box_markup_renders_links(): void {
        $html = CCMCK_Guias::guia_box_markup( '33042000009', 'http://x.co/t', 'http://admin.x/label' );
        $this->assertStringContainsString( '33042000009', $html );
        $this->assertStringContainsString( 'http://x.co/t', $html );
        $this->assertStringContainsString( 'http://admin.x/label', $html );
        $this->assertStringContainsString( 'Descargar rótulo', $html );
    }

    public function test_guia_box_markup_empty_when_no_guia(): void {
        $this->assertSame( '', CCMCK_Guias::guia_box_markup( '', 'x', 'y' ) );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php phpunit.phar tests/GuiasTest.php` → FAIL (`undefined method guia_box_markup`).

- [ ] **Step 3: Implement markup + render + AJAX**

En `includes/class-ccmck-guias.php`, antes del `}` de cierre:

```php
    /** Markup de la caja de guía en el pedido del admin. PURO. */
    public static function guia_box_markup( string $guia, string $tracking_url, string $label_url ): string {
        if ( '' === trim( $guia ) ) {
            return '';
        }
        $html  = '<div class="ccmck-guia-box" style="margin-top:12px;padding:10px;border:1px solid #c3c4c7;border-radius:4px;">';
        $html .= '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Guía Coordinadora:', 'ccm-checkout' ) . '</strong> ' . esc_html( $guia ) . '</p>';
        if ( '' !== $tracking_url ) {
            $html .= '<p style="margin:0 0 6px;"><a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver rastreo', 'ccm-checkout' ) . '</a></p>';
        }
        $html .= '<a class="button" href="' . esc_url( $label_url ) . '">' . esc_html__( 'Descargar rótulo', 'ccm-checkout' ) . '</a>';
        $html .= '</div>';
        return $html;
    }

    /** Render en el pedido del admin (hook woocommerce_admin_order_data_after_billing_address). */
    public static function render_admin( $order ): void {
        $guia = (string) $order->get_meta( self::META_GUIA );
        if ( '' === $guia ) {
            return;
        }
        $label_url = wp_nonce_url(
            admin_url( 'admin-ajax.php?action=ccmck_guia_label&order_id=' . (int) $order->get_id() ),
            'ccmck_guia_label'
        );
        echo self::guia_box_markup( $guia, (string) $order->get_meta( self::META_URL ), $label_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro del método.
    }

    /** AJAX: descarga el rótulo PDF al vuelo vía Guias.reimprimirGuia. */
    public static function ajax_label(): void {
        check_ajax_referer( 'ccmck_guia_label' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'ccm-checkout' ) );
        }
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        $guia     = $order ? (string) $order->get_meta( self::META_GUIA ) : '';
        if ( '' === $guia ) {
            wp_die( esc_html__( 'El pedido no tiene guía.', 'ccm-checkout' ) );
        }
        $response = self::rpc( 'Guias.reimprimirGuia', array(
            'usuario'          => (string) CCMCK_Settings::get( 'guias_usuario', '' ),
            'clave'            => hash( 'sha256', (string) CCMCK_Settings::get( 'guias_clave', '' ) ),
            'codigo_remision'  => $guia,
            'formato_impresion' => '1',
        ) );
        if ( is_wp_error( $response ) ) {
            wp_die( esc_html( $response->get_error_message() ) );
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $pdf  = is_array( $data ) && isset( $data['result']['pdf'] ) ? base64_decode( (string) $data['result']['pdf'] ) : '';
        if ( '' === $pdf || '%PDF' !== substr( $pdf, 0, 4 ) ) {
            $msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Rótulo no disponible.';
            wp_die( esc_html( $msg ) );
        }
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="guia-' . rawurlencode( $guia ) . '.pdf"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario PDF.
        exit;
    }
```

Y en `init()`, añadir tras el `add_action` existente:

```php
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin' ) );
        add_action( 'wp_ajax_ccmck_guia_label', array( __CLASS__, 'ajax_label' ) );
```

- [ ] **Step 4: Run tests + lint**

Run: `php phpunit.phar` → **OK**. `php -l includes/class-ccmck-guias.php` → sin errores.
(Nota: los tests puros no ejecutan `render_admin`/`ajax_label`; `esc_url`/`esc_html__` ya tienen stub en bootstrap; `guia_box_markup` solo usa esos.)

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-guias.php tests/GuiasTest.php
git commit -m "feat(guias): caja de guía en el pedido + descarga de rótulo PDF

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 6: UI de ajustes — sección "Generación de guías"

**Files:**
- Modify: `includes/views/settings-page.php` (dentro del panel `data-tab="coordinadora"`, tras el repeater de reglas y antes de `</div><?php /* /panel coordinadora */ ?>`)

**Interfaces:**
- Consumes: `$s['guias_*']` (Task 1).
- Sin tests (vista); verificación manual.

- [ ] **Step 1: Add the section**

```php
<h3><?php esc_html_e( 'Generación de guías', 'ccm-checkout' ); ?></h3>
<p class="description">
	<?php esc_html_e( 'Genera la guía automáticamente cuando el pedido pasa a "Procesando" (pago aprobado). Prueba primero en sandbox; Coordinadora debe revisar la liquidación de los primeros despachos en producción.', 'ccm-checkout' ); ?>
</p>

<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'Activar', 'ccm-checkout' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="ccmck_settings[guias_enabled]" value="1" <?php checked( ! empty( $s['guias_enabled'] ) ); ?>>
				<?php esc_html_e( 'Generar guías automáticamente', 'ccm-checkout' ); ?>
			</label>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_env"><?php esc_html_e( 'Ambiente', 'ccm-checkout' ); ?></label></th>
		<td>
			<select id="ccmck_guias_env" name="ccmck_settings[guias_env]">
				<option value="sandbox" <?php selected( $s['guias_env'], 'sandbox' ); ?>><?php esc_html_e( 'Sandbox (pruebas)', 'ccm-checkout' ); ?></option>
				<option value="production" <?php selected( $s['guias_env'], 'production' ); ?>><?php esc_html_e( 'Producción', 'ccm-checkout' ); ?></option>
			</select>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_usuario"><?php esc_html_e( 'Usuario WS guías', 'ccm-checkout' ); ?></label></th>
		<td><input type="text" id="ccmck_guias_usuario" class="regular-text" name="ccmck_settings[guias_usuario]" value="<?php echo esc_attr( $s['guias_usuario'] ); ?>" autocomplete="off"></td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_clave"><?php esc_html_e( 'Clave WS guías', 'ccm-checkout' ); ?></label></th>
		<td>
			<input type="password" id="ccmck_guias_clave" class="regular-text" name="ccmck_settings[guias_clave]" value="<?php echo esc_attr( $s['guias_clave'] ); ?>" autocomplete="new-password">
			<p class="description"><?php esc_html_e( 'Se envía cifrada (SHA-256) al web service.', 'ccm-checkout' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_idc"><?php esc_html_e( 'ID de cliente (acuerdo)', 'ccm-checkout' ); ?></label></th>
		<td><input type="number" id="ccmck_guias_idc" name="ccmck_settings[guias_id_cliente]" value="<?php echo esc_attr( (string) $s['guias_id_cliente'] ); ?>"></td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_rn"><?php esc_html_e( 'Remitente — razón social', 'ccm-checkout' ); ?></label></th>
		<td><input type="text" id="ccmck_guias_rn" class="regular-text" name="ccmck_settings[guias_remitente_nombre]" value="<?php echo esc_attr( $s['guias_remitente_nombre'] ); ?>"></td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_rd"><?php esc_html_e( 'Remitente — dirección', 'ccm-checkout' ); ?></label></th>
		<td>
			<input type="text" id="ccmck_guias_rd" class="regular-text" name="ccmck_settings[guias_remitente_direccion]" value="<?php echo esc_attr( $s['guias_remitente_direccion'] ); ?>">
			<p class="description"><?php esc_html_e( 'Dirección física de donde Coordinadora recoge el despacho. La ciudad usa el mismo código DANE de origen de la cotización.', 'ccm-checkout' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_rt"><?php esc_html_e( 'Remitente — teléfono', 'ccm-checkout' ); ?></label></th>
		<td><input type="text" id="ccmck_guias_rt" class="regular-text" name="ccmck_settings[guias_remitente_telefono]" value="<?php echo esc_attr( $s['guias_remitente_telefono'] ); ?>"></td>
	</tr>
	<tr>
		<th scope="row"><label for="ccmck_guias_wh"><?php esc_html_e( 'Webhook n8n (aviso WhatsApp)', 'ccm-checkout' ); ?></label></th>
		<td>
			<input type="text" id="ccmck_guias_wh" class="regular-text ccmck-url-input" name="ccmck_settings[guias_webhook_url]" value="<?php echo esc_attr( $s['guias_webhook_url'] ); ?>" placeholder="https://">
			<p class="description"><?php esc_html_e( 'Opcional. Al generarse la guía se envía un POST con pedido, guía, rastreo y cliente. Vacío = no se envía.', 'ccm-checkout' ); ?></p>
		</td>
	</tr>
</table>
```

- [ ] **Step 2: Lint + suite**

Run: `php -l includes/views/settings-page.php` → sin errores. `php phpunit.phar` → OK.

- [ ] **Step 3: Commit**

```bash
git add includes/views/settings-page.php
git commit -m "feat(settings-ui): sección Generación de guías en la pestaña Coordinadora

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 7: Workflow n8n + CHANGELOG + verificación sandbox

**Files:**
- Create: `docs/n8n/wf-guia-whatsapp.json`
- Modify: `docs/CHANGELOG.md`

- [ ] **Step 1: Create the n8n workflow JSON (import-ready)**

Crear `docs/n8n/wf-guia-whatsapp.json` — Webhook → normalización → Execute Workflow (cwSendWa01). El ID del sub-workflow `cwSendWa01` se selecciona al importar (parámetro visible del nodo):

```json
{
  "name": "wfGuiaWhatsApp01",
  "nodes": [
    {
      "parameters": {
        "httpMethod": "POST",
        "path": "ccm-guia-generada",
        "options": {}
      },
      "id": "wh-guia-1",
      "name": "Webhook Guía",
      "type": "n8n-nodes-base.webhook",
      "typeVersion": 2,
      "position": [0, 0],
      "webhookId": "ccm-guia-generada"
    },
    {
      "parameters": {
        "jsCode": "const b = $input.first().json.body || $input.first().json;\nlet phone = String(b.customer?.phone || '').replace(/[^0-9]/g, '');\nif (phone.length === 10 && phone.startsWith('3')) phone = '57' + phone;\nconst msg = `¡Hola ${b.customer?.name || ''}! Tu pedido #${b.order_number} ya va en camino 🚚\\n\\nGuía Coordinadora: *${b.guia}*\\nSíguelo aquí: ${b.tracking_url}`;\nreturn [{ json: { phone, message: msg, order_number: b.order_number, guia: b.guia } }];"
      },
      "id": "fn-guia-1",
      "name": "Normalizar y armar mensaje",
      "type": "n8n-nodes-base.code",
      "typeVersion": 2,
      "position": [220, 0]
    },
    {
      "parameters": {
        "workflowId": "",
        "workflowInputs": {
          "mappingMode": "defineBelow",
          "value": {
            "phone": "={{ $json.phone }}",
            "message": "={{ $json.message }}"
          }
        }
      },
      "id": "ex-guia-1",
      "name": "Enviar WhatsApp (cwSendWa01)",
      "type": "n8n-nodes-base.executeWorkflow",
      "typeVersion": 1.2,
      "position": [440, 0],
      "notes": "Seleccionar aquí el sub-workflow cwSendWa01 al importar. Ajustar los nombres de inputs al contrato real de cwSendWa01 (sesión → fallback plantilla WABA)."
    }
  ],
  "connections": {
    "Webhook Guía": { "main": [[{ "node": "Normalizar y armar mensaje", "type": "main", "index": 0 }]] },
    "Normalizar y armar mensaje": { "main": [[{ "node": "Enviar WhatsApp (cwSendWa01)", "type": "main", "index": 0 }]] }
  },
  "settings": { "executionOrder": "v1" }
}
```

> Al importar en n8n: (1) seleccionar el sub-workflow `cwSendWa01` en el nodo Execute Workflow y **ajustar los inputs a su contrato real** (verificarlo en el propio cwSendWa01); (2) copiar la URL del webhook de producción y pegarla en *Ajustes → Checkout CCM → Coordinadora → Webhook n8n*; (3) verificar/crear la plantilla WABA para comprador sin sesión abierta.

- [ ] **Step 2: Changelog entry**

En `docs/CHANGELOG.md`, bajo `## [Sin publicar]` → `### Añadido`, como primer ítem:

```markdown
- **Generación automática de guías de Coordinadora**: al pasar un pedido a "Procesando"
  (pago aprobado), el módulo nuevo `CCMCK_Guias` genera la guía vía `Guias.generarGuia`
  con las **mismas cajas** que cotizó el checkout (`CCMCK_Coordinadora::pack`), cumpliendo
  las observaciones de go-live de Coordinadora (fecha y `nit_remitente` vacíos, razón
  social como remitente, DANE real de recogida). Guarda el nº de guía en
  `_coordinadora_tracking_number` (compatible con el plugin de terceros) + URL de rastreo,
  deja nota en el pedido, muestra la guía en el admin con botón **"Descargar rótulo"**
  (PDF al vuelo vía `reimprimirGuia`) y dispara un **webhook a n8n** para avisar al
  cliente por WhatsApp (workflow `wfGuiaWhatsApp01`, patrón `cwSendWa01`). Se excluyen
  pedidos de recogida local y hay guard de idempotencia (nunca dos guías por pedido).
  Ajustes en *Checkout CCM → Coordinadora → Generación de guías* (toggle off por defecto,
  selector sandbox/producción, credenciales del WS de guías con clave SHA-256 en runtime,
  datos del remitente, webhook). Tests en `GuiasTest` y `SettingsTest`.
```

- [ ] **Step 3: Full suite one last time**

Run: `php phpunit.phar` → **OK** (todas las suites).

- [ ] **Step 4: Commit**

```bash
git add docs/n8n/wf-guia-whatsapp.json docs/CHANGELOG.md
git commit -m "docs: workflow n8n de aviso WhatsApp y changelog de guías automáticas

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Verificación en sandbox (manual, tras deploy)**

1. Subir PHP + vista por File Manager, **purgar OPcache**. Con `guias_enabled = false` nada cambia.
2. En *Ajustes → Coordinadora → Generación de guías*: clave `Ccm!83s`, dirección y teléfono del remitente, **ambiente = Sandbox**, activar.
3. Hacer un pedido de prueba (producto con medidas, envío Coordinadora, pago que pase a Procesando — o cambiar el estado a mano en el admin).
4. Verificar: nota "Guía Coordinadora generada: NNN" + caja con la guía en el pedido + "Descargar rótulo" responde (en sandbox el PDF sale en blanco: normal) + webhook llegó a n8n (si está configurado).
5. Pedido con **Recogida local** → NO genera guía. Re-guardar Procesando en un pedido con guía → NO duplica.
6. Cambiar ambiente a **Producción** solo cuando se acuerde el go-live con Coordinadora (avisar a Geraldine para revisión de los primeros despachos).

---

## Notas de ejecución

- **TDD estricto** en Tasks 1-3 y el markup de Task 5; Tasks 4/6/7 son capa WC/vista/config con verificación manual en sandbox.
- La ruta del proyecto tiene espacios: ejecutar comandos con el cwd dentro del plugin.
- `guias_clave` se guarda plana en `ccmck_settings` (misma BD que el resto de credenciales del plugin) y se hashea al llamar el WS; no se loguea nunca.
