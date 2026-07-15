# Cotización Coordinadora con armado de cajas — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cotizar el flete de Coordinadora directo desde el mu-plugin agrupando el carrito en cajas según reglas por tipo de producto, para que combos de productos dejen de dar fletes inválidos.

**Architecture:** Un módulo nuevo `CCMCK_Coordinadora` engancha el filtro `woocommerce_package_rates` (patrón de `CCMCK_Pickup`). Un motor de empaque **puro** reparte el carrito en cajas, arma el `detalle[]` JSON-RPC, llama a `Cotizador.cotizar` y **reemplaza** la tarifa `coordinadora:N` del plugin viejo por la propia (`ccmck_coordinadora`), conservando la vieja como fallback si la API falla. Los días de entrega viajan en meta de la rate y se pintan en la card.

**Tech Stack:** PHP (WordPress mu-plugin + WooCommerce), PHPUnit 11 vía `phpunit.phar` (tests **sin WordPress**, con stubs en `tests/bootstrap.php`), JSON-RPC 2.0 sobre `wp_remote_post`.

**Spec:** `docs/superpowers/specs/2026-07-08-coordinadora-cotizacion-cajas-design.md`

## Global Constraints

- **PHP 7.4+**, estilo del repo: una clase por archivo, prefijo `CCMCK_`, cabecera `defined( 'ABSPATH' ) || exit;`, tipos en firmas.
- **Métodos PUROS siempre que se pueda** (sin globals, sin WP) → testeables. Solo `quote()` y `rates()` tocan WC/HTTP.
- **Tests sin WordPress:** corren con stubs de `tests/bootstrap.php`. Toda función WP nueva que use un test debe tener stub en el bootstrap.
- **Sanitize por whitelist** en `CCMCK_Settings::sanitize()` (lo no listado se descarta). **Escapar toda salida** (`esc_html`/`esc_attr`).
- **Rate id propio:** `ccmck_coordinadora`. **Label:** `Coordinadora` (debe coincidir con `CCMCK_Shipping::placeholder_labels()`).
- **Endpoint:** `https://ws.coordinadora.com/ags/1.5/server.php`. **Método:** `Cotizador.cotizar`. Body params verificados: `div:"01"`, `cuenta:2`, `producto:0`, `nivel_servicio:[{item:1}]`, `detalle[].ubl:0`.
- **Regla de error de la API:** HTTP siempre 200; falló si `error !== null` o si el body no es JSON. `error.code` no discrimina (siempre 0).
- **Comando de tests** (cwd = raíz del plugin): todo `php phpunit.phar`; un archivo `php phpunit.phar tests/CoordinadoraTest.php`; un test `php phpunit.phar --filter test_name`.
- **No romper el checkout en vivo:** el toggle `coordinadora_enabled` arranca en `false`; con él apagado, `rates()` devuelve las tarifas sin tocar.
- **Commits frecuentes**, uno por tarea. Trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `includes/class-ccmck-coordinadora.php` (crear) | Motor de cajas (puro) + request/response (puro) + `apply_quote` (puro) + `quote`/`rates` (WC/HTTP) + `init` |
| `tests/CoordinadoraTest.php` (crear) | Tests de los métodos puros |
| `tests/bootstrap.php` (modificar) | `require` de la clase nueva; stubs `_n`, meta en `WC_Shipping_Rate` |
| `includes/class-ccmck-settings.php` (modificar) | 7 defaults + sanitize + `sanitize_box_rules` |
| `tests/SettingsTest.php` (modificar) | Tests de las keys nuevas |
| `includes/class-ccmck-shipping.php` (modificar) | `eta` en `build_methods` + `render_cards` |
| `tests/ShippingTest.php` (modificar) | Tests de `eta` |
| `ccm-checkout.php` (modificar) | `require_once` + `CCMCK_Coordinadora::init()` |
| `includes/views/settings-page.php` (modificar) | Pestaña "Coordinadora" + repeater de reglas |
| `assets/ccmck-checkout.css` (modificar) | Estilo `.ccmck-ship-eta` |
| `docs/CHANGELOG.md` (modificar) | Entrada en *[Sin publicar] → Añadido* |

---

## Task 1: Settings — credenciales, umbral y reglas de caja

**Files:**
- Modify: `includes/class-ccmck-settings.php` (`defaults()` ~línea 13-52; `sanitize()` ~54-103; helper nuevo junto a `sanitize_cards` ~133)
- Test: `tests/SettingsTest.php`

**Interfaces:**
- Produces: `CCMCK_Settings::defaults()` incluye las 7 keys; `CCMCK_Settings::sanitize($input)` las normaliza. Forma de `coordinadora_box_rules`: `array<int,array{cat:int,n:int}>`.

- [ ] **Step 1: Write the failing tests**

En `tests/SettingsTest.php`, antes del `}` final de la clase:

```php
    public function test_defaults_include_coordinadora_keys(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertFalse( $d['coordinadora_enabled'] );
        $this->assertSame( '901677789', $d['coordinadora_nit'] );
        $this->assertSame( '08001000', $d['coordinadora_origin'] );
        $this->assertSame( 5.0, $d['coordinadora_weight_threshold'] );
        $this->assertSame( array(), $d['coordinadora_box_rules'] );
    }

    public function test_sanitize_coordinadora_enabled_boolean(): void {
        $this->assertTrue( CCMCK_Settings::sanitize( array( 'coordinadora_enabled' => '1' ) )['coordinadora_enabled'] );
        $this->assertFalse( CCMCK_Settings::sanitize( array() )['coordinadora_enabled'] );
    }

    public function test_sanitize_nit_and_origin_keep_only_digits(): void {
        $out = CCMCK_Settings::sanitize( array( 'coordinadora_nit' => '901.677.789-0', 'coordinadora_origin' => 'DANE 08001000' ) );
        $this->assertSame( '9016777890', $out['coordinadora_nit'] );
        $this->assertSame( '08001000', $out['coordinadora_origin'] );
    }

    public function test_sanitize_weight_threshold_comma_and_floor(): void {
        $this->assertSame( 7.5, CCMCK_Settings::sanitize( array( 'coordinadora_weight_threshold' => '7,5' ) )['coordinadora_weight_threshold'] );
        $this->assertSame( 0.0, CCMCK_Settings::sanitize( array( 'coordinadora_weight_threshold' => '-2' ) )['coordinadora_weight_threshold'] );
        $this->assertSame( 5.0, CCMCK_Settings::sanitize( array() )['coordinadora_weight_threshold'] );
    }

    public function test_sanitize_box_rules_drops_invalid_and_dedupes_category(): void {
        $out = CCMCK_Settings::sanitize( array( 'coordinadora_box_rules' => array(
            array( 'cat' => '1253', 'n' => '2' ),
            array( 'cat' => '0',    'n' => '3' ),   // cat inválida
            array( 'cat' => '1400', 'n' => '0' ),   // n inválido
            array( 'cat' => '1253', 'n' => '9' ),   // duplicada -> se ignora
        ) ) );
        $this->assertSame( array( array( 'cat' => 1253, 'n' => 2 ) ), $out['coordinadora_box_rules'] );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar --filter coordinadora`
Expected: FAIL (keys inexistentes → `Undefined array key`).

- [ ] **Step 3: Add defaults**

En `CCMCK_Settings::defaults()`, dentro del `return array( ... )`, antes del `);` de cierre:

```php
            // Coordinadora — cotización directa del flete (ver spec 2026-07-08).
            'coordinadora_enabled'          => false,
            'coordinadora_apikey'           => '',
            'coordinadora_clave'            => '',
            'coordinadora_nit'              => '901677789',
            'coordinadora_origin'           => '08001000',
            'coordinadora_weight_threshold' => 5.0,
            'coordinadora_box_rules'        => array(),
```

- [ ] **Step 4: Add sanitize**

En `CCMCK_Settings::sanitize()`, antes de `return $out;`:

```php
        $out['coordinadora_enabled'] = ! empty( $input['coordinadora_enabled'] );
        $out['coordinadora_apikey']  = sanitize_text_field( $input['coordinadora_apikey'] ?? '' );
        $out['coordinadora_clave']   = sanitize_text_field( $input['coordinadora_clave'] ?? '' );
        $out['coordinadora_nit']     = preg_replace( '/[^0-9]/', '', (string) ( $input['coordinadora_nit'] ?? '' ) );
        $out['coordinadora_origin']  = preg_replace( '/[^0-9]/', '', (string) ( $input['coordinadora_origin'] ?? '' ) );

        $thr = isset( $input['coordinadora_weight_threshold'] )
            ? str_replace( ',', '.', (string) $input['coordinadora_weight_threshold'] )
            : (string) $d['coordinadora_weight_threshold'];
        $out['coordinadora_weight_threshold'] = max( 0.0, round( (float) $thr, 2 ) );

        $out['coordinadora_box_rules'] = self::sanitize_box_rules( $input['coordinadora_box_rules'] ?? array() );
```

- [ ] **Step 5: Add the `sanitize_box_rules` helper**

Junto a `sanitize_cards()` (~línea 133), añadir:

```php
    /**
     * Reglas de empaque: filas {cat, n}. Descarta cat<=0 o n<1 y deduplica por
     * categoría (la primera fila de cada categoría gana). PURO.
     */
    private static function sanitize_box_rules( $rows ): array {
        $clean = array();
        $seen  = array();
        foreach ( (array) $rows as $row ) {
            $cat = absint( $row['cat'] ?? 0 );
            $n   = absint( $row['n'] ?? 0 );
            if ( $cat > 0 && $n > 0 && ! isset( $seen[ $cat ] ) ) {
                $seen[ $cat ] = true;
                $clean[]      = array( 'cat' => $cat, 'n' => $n );
            }
        }
        return $clean;
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php phpunit.phar --filter coordinadora`
Expected: PASS (4 tests nuevos).
Luego `php phpunit.phar` → **OK** (sin regresiones).

- [ ] **Step 7: Commit**

```bash
git add includes/class-ccmck-settings.php tests/SettingsTest.php
git commit -m "feat(settings): credenciales, umbral y reglas de caja de Coordinadora

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Scaffold `CCMCK_Coordinadora` + `dane_from_city` + `classify_item`

**Files:**
- Create: `includes/class-ccmck-coordinadora.php`
- Modify: `tests/bootstrap.php` (añadir `require_once` de la clase, ~después de la línea 121)
- Test: `tests/CoordinadoraTest.php` (crear)

**Interfaces:**
- Produces:
  - `const RATE_ID = 'ccmck_coordinadora'`, `const LABEL = 'Coordinadora'`.
  - `CCMCK_Coordinadora::dane_from_city( string $city ): string`
  - `CCMCK_Coordinadora::classify_item( array $item, float $threshold, array $rules ): array` → `array{kind:'rule'|'heavy'|'small', units_per_box:int}`. `$item['cat_ids']` = `int[]`, `$item['weight']` = float. `$rules` = `array<int,int>` (cat_id → N).

- [ ] **Step 1: Write the failing tests**

Crear `tests/CoordinadoraTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class CoordinadoraTest extends TestCase {
    // --- dane_from_city ---
    public function test_dane_from_plain_code(): void {
        $this->assertSame( '05001000', CCMCK_Coordinadora::dane_from_city( '05001000' ) );
    }
    public function test_dane_from_labeled_city(): void {
        $this->assertSame( '05001000', CCMCK_Coordinadora::dane_from_city( 'MEDELLIN (ANT) (05001000)' ) );
    }
    public function test_dane_from_garbage_is_empty(): void {
        $this->assertSame( '', CCMCK_Coordinadora::dane_from_city( 'Bogota' ) );
    }

    // --- classify_item ---
    public function test_classify_rule_category_wins_over_weight(): void {
        $item = array( 'cat_ids' => array( 1253 ), 'weight' => 12.0 );
        $c = CCMCK_Coordinadora::classify_item( $item, 5.0, array( 1253 => 2 ) );
        $this->assertSame( 'rule', $c['kind'] );
        $this->assertSame( 2, $c['units_per_box'] );
    }
    public function test_classify_heavy_when_no_rule_and_over_threshold(): void {
        $item = array( 'cat_ids' => array( 99 ), 'weight' => 8.0 );
        $c = CCMCK_Coordinadora::classify_item( $item, 5.0, array( 1253 => 2 ) );
        $this->assertSame( 'heavy', $c['kind'] );
        $this->assertSame( 1, $c['units_per_box'] );
    }
    public function test_classify_small_when_under_threshold(): void {
        $item = array( 'cat_ids' => array(), 'weight' => 0.5 );
        $c = CCMCK_Coordinadora::classify_item( $item, 5.0, array() );
        $this->assertSame( 'small', $c['kind'] );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: FAIL — `Error: Class "CCMCK_Coordinadora" not found`.

- [ ] **Step 3: Create the class file with the two methods**

Crear `includes/class-ccmck-coordinadora.php`:

```php
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
     * @param array            $item      {cat_ids:int[], weight:float}
     * @param float            $threshold Umbral de "pesado" (kg).
     * @param array<int,int>   $rules     cat_id => unidades por caja.
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
}
```

- [ ] **Step 4: Register the class in the test bootstrap**

En `tests/bootstrap.php`, tras la última línea `require_once` (~línea 123), añadir:

```php
require_once dirname( __DIR__ ) . '/includes/class-ccmck-coordinadora.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-ccmck-coordinadora.php tests/CoordinadoraTest.php tests/bootstrap.php
git commit -m "feat(coordinadora): scaffold + dane_from_city + classify_item

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: `stack_box` + `build_detalle`

**Files:**
- Modify: `includes/class-ccmck-coordinadora.php`
- Test: `tests/CoordinadoraTest.php`

**Interfaces:**
- Consumes: nada externo.
- Produces:
  - `stack_box( array $units ): array` → `array{largo:float,ancho:float,alto:float,peso:float}`. `$units` = lista de `{largo,ancho,alto,peso}`. `largo`/`ancho` = máximo; `alto`/`peso` = suma.
  - `build_detalle( array $boxes ): array` → lista de `array{ubl:0,alto,ancho,largo,peso,unidades:int}`; agrupa cajas idénticas (dims+peso redondeados) sumando `unidades`.

- [ ] **Step 1: Write the failing tests**

En `tests/CoordinadoraTest.php`, antes del `}` final:

```php
    // --- stack_box ---
    public function test_stack_box_max_footprint_sum_height_and_weight(): void {
        $units = array(
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 50, 'peso' => 15 ),
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 50, 'peso' => 15 ),
        );
        $box = CCMCK_Coordinadora::stack_box( $units );
        $this->assertSame( 60.0, $box['largo'] );
        $this->assertSame( 40.0, $box['ancho'] );
        $this->assertSame( 100.0, $box['alto'] );
        $this->assertSame( 30.0, $box['peso'] );
    }

    // --- build_detalle ---
    public function test_build_detalle_groups_identical_boxes(): void {
        $boxes = array(
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 100, 'peso' => 30 ),
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 100, 'peso' => 30 ),
        );
        $det = CCMCK_Coordinadora::build_detalle( $boxes );
        $this->assertCount( 1, $det );
        $this->assertSame( 2, $det[0]['unidades'] );
        $this->assertSame( 0, $det[0]['ubl'] );
        $this->assertSame( 100.0, $det[0]['alto'] );
    }

    public function test_build_detalle_separates_different_boxes(): void {
        $boxes = array(
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 100, 'peso' => 30 ),
            array( 'largo' => 60, 'ancho' => 40, 'alto' => 50,  'peso' => 15 ),
        );
        $det = CCMCK_Coordinadora::build_detalle( $boxes );
        $this->assertCount( 2, $det );
        $this->assertSame( 1, $det[0]['unidades'] );
        $this->assertSame( 1, $det[1]['unidades'] );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: FAIL — `Call to undefined method CCMCK_Coordinadora::stack_box()`.

- [ ] **Step 3: Implement both methods**

En `includes/class-ccmck-coordinadora.php`, antes del `}` de cierre de la clase:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-coordinadora.php tests/CoordinadoraTest.php
git commit -m "feat(coordinadora): stack_box y build_detalle

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: `pack` — reparto del carrito en cajas

**Files:**
- Modify: `includes/class-ccmck-coordinadora.php`
- Test: `tests/CoordinadoraTest.php`

**Interfaces:**
- Consumes: `classify_item`, `stack_box`.
- Produces: `pack( array $items, float $threshold, array $rules ): array` → lista de cajas `{largo,ancho,alto,peso}`. `$items` = lista de `{qty:int, weight:float, largo:float, ancho:float, alto:float, cat_ids:int[]}`. Reglas: `rule`/`heavy` → `ceil(qty/N)` cajas homogéneas de hasta N unidades del mismo producto; `small` → **una** caja consolidada con todas las unidades <umbral.

- [ ] **Step 1: Write the failing tests**

En `tests/CoordinadoraTest.php`, antes del `}` final:

```php
    // --- pack ---
    private function speaker( int $qty ): array {
        return array( 'qty' => $qty, 'weight' => 10.0, 'largo' => 40, 'ancho' => 40, 'alto' => 60, 'cat_ids' => array( 1253 ) );
    }

    public function test_pack_speakers_two_per_box(): void {
        // 4 parlantes, N=2 -> 2 cajas.
        $boxes = CCMCK_Coordinadora::pack( array( $this->speaker( 4 ) ), 5.0, array( 1253 => 2 ) );
        $this->assertCount( 2, $boxes );
        $this->assertSame( 20.0, $boxes[0]['peso'] ); // 2 x 10 kg
    }

    public function test_pack_speakers_odd_leaves_half_box(): void {
        // 5 parlantes, N=2 -> cajas 2,2,1.
        $boxes = CCMCK_Coordinadora::pack( array( $this->speaker( 5 ) ), 5.0, array( 1253 => 2 ) );
        $this->assertCount( 3, $boxes );
        $det = CCMCK_Coordinadora::build_detalle( $boxes );
        $this->assertCount( 2, $det );                 // caja llena + media
        $this->assertSame( 2, $det[0]['unidades'] );
        $this->assertSame( 1, $det[1]['unidades'] );
    }

    public function test_pack_heavy_non_rule_one_per_box(): void {
        $heavy = array( 'qty' => 3, 'weight' => 8.0, 'largo' => 30, 'ancho' => 30, 'alto' => 30, 'cat_ids' => array( 99 ) );
        $boxes = CCMCK_Coordinadora::pack( array( $heavy ), 5.0, array() );
        $this->assertCount( 3, $boxes );
    }

    public function test_pack_small_items_consolidate_into_one_box(): void {
        $acc = array( 'qty' => 6, 'weight' => 0.5, 'largo' => 10, 'ancho' => 10, 'alto' => 5, 'cat_ids' => array() );
        $boxes = CCMCK_Coordinadora::pack( array( $acc ), 5.0, array() );
        $this->assertCount( 1, $boxes );
        $this->assertSame( 3.0, $boxes[0]['peso'] );   // 6 x 0.5 kg
        $this->assertSame( 30.0, $boxes[0]['alto'] );  // 6 x 5 cm (apilado)
    }

    public function test_pack_mixed_cart(): void {
        // 2 parlantes (1 caja) + 1 pesado (1 caja) + 3 accesorios (1 caja) = 3 cajas.
        $heavy = array( 'qty' => 1, 'weight' => 8.0, 'largo' => 30, 'ancho' => 30, 'alto' => 30, 'cat_ids' => array( 99 ) );
        $acc   = array( 'qty' => 3, 'weight' => 0.5, 'largo' => 10, 'ancho' => 10, 'alto' => 5, 'cat_ids' => array() );
        $boxes = CCMCK_Coordinadora::pack( array( $this->speaker( 2 ), $heavy, $acc ), 5.0, array( 1253 => 2 ) );
        $this->assertCount( 3, $boxes );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: FAIL — `Call to undefined method CCMCK_Coordinadora::pack()`.

- [ ] **Step 3: Implement `pack`**

En `includes/class-ccmck-coordinadora.php`, antes del `}` de cierre:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: PASS (14 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-coordinadora.php tests/CoordinadoraTest.php
git commit -m "feat(coordinadora): pack — reparto del carrito en cajas por tipo

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: `build_request` + `parse_response`

**Files:**
- Modify: `includes/class-ccmck-coordinadora.php`
- Test: `tests/CoordinadoraTest.php`

**Interfaces:**
- Produces:
  - `build_request( array $args ): array` → array JSON-RPC. `$args` = `{nit,origen,destino,valoracion,detalle,apikey,clave}`.
  - `parse_response( string $body, $http_code ): array` → `array{ok:bool, flete_total:int, dias:int, error:string}`.

- [ ] **Step 1: Write the failing tests**

En `tests/CoordinadoraTest.php`, antes del `}` final:

```php
    // --- build_request ---
    public function test_build_request_shape(): void {
        $req = CCMCK_Coordinadora::build_request( array(
            'nit' => '901677789', 'origen' => '08001000', 'destino' => '11001000',
            'valoracion' => 50000, 'apikey' => 'K', 'clave' => 'C',
            'detalle' => array( array( 'ubl' => 0, 'alto' => 10, 'ancho' => 10, 'largo' => 10, 'peso' => 2, 'unidades' => 1 ) ),
        ) );
        $this->assertSame( '2.0', $req['jsonrpc'] );
        $this->assertSame( 'Cotizador.cotizar', $req['method'] );
        $this->assertSame( '08001000', $req['params']['origen'] );
        $this->assertSame( 2, $req['params']['cuenta'] );
        $this->assertSame( 0, $req['params']['producto'] );
        $this->assertSame( array( array( 'item' => 1 ) ), $req['params']['nivel_servicio'] );
        $this->assertSame( 50000, $req['params']['valoracion'] );
        $this->assertCount( 1, $req['params']['detalle'] );
    }

    // --- parse_response ---
    public function test_parse_response_success(): void {
        $body = '{"jsonrpc":"2.0","id":0,"error":null,"result":{"flete_total":15700,"dias_entrega":"2"}}';
        $r = CCMCK_Coordinadora::parse_response( $body, 200 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 15700, $r['flete_total'] );
        $this->assertSame( 2, $r['dias'] );
    }

    public function test_parse_response_business_error(): void {
        $body = '{"jsonrpc":"2.0","id":0,"error":{"code":0,"message":"Error, apikey no valido"}}';
        $r = CCMCK_Coordinadora::parse_response( $body, 200 );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'apikey', $r['error'] );
    }

    public function test_parse_response_non_json_html(): void {
        $r = CCMCK_Coordinadora::parse_response( '<b>Fatal error</b>', 200 );
        $this->assertFalse( $r['ok'] );
    }

    public function test_parse_response_missing_flete(): void {
        $r = CCMCK_Coordinadora::parse_response( '{"jsonrpc":"2.0","result":{}}', 200 );
        $this->assertFalse( $r['ok'] );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: FAIL — `Call to undefined method CCMCK_Coordinadora::build_request()`.

- [ ] **Step 3: Implement both methods**

En `includes/class-ccmck-coordinadora.php`, antes del `}` de cierre:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: PASS (19 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-coordinadora.php tests/CoordinadoraTest.php
git commit -m "feat(coordinadora): build_request y parse_response JSON-RPC

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: `apply_quote` (puro) + `quote`/`rates`/`init` (WC/HTTP) + registro

**Files:**
- Modify: `includes/class-ccmck-coordinadora.php`
- Modify: `tests/bootstrap.php` (meta en el stub `WC_Shipping_Rate`, ~línea 101-112)
- Modify: `ccm-checkout.php` (require + init, líneas 15-31 y 44-59)
- Test: `tests/CoordinadoraTest.php`

**Interfaces:**
- Consumes: `pack`, `build_detalle`, `dane_from_city`, `build_request`, `parse_response`; `CCMCK_Settings::get()`.
- Produces:
  - `apply_quote( array $rates, array $quote ): array` — si `$quote['ok']`, quita toda rate cuyo id empiece por `coordinadora` y añade una `WC_Shipping_Rate` `ccmck_coordinadora` con meta `dias_entrega`; si no, devuelve `$rates` intacto.
  - `quote( array $args ): array` (HTTP; devuelve la forma de `parse_response`).
  - `rates( $rates, $package ): array` (filtro).
  - `init(): void`.

- [ ] **Step 1: Enhance the `WC_Shipping_Rate` stub with meta**

En `tests/bootstrap.php`, dentro de `class WC_Shipping_Rate` (bloque ~101-112), añadir la propiedad y los dos métodos (justo después de `public $id; ...` y de los getters existentes):

```php
        public $meta_data = array();
        public function add_meta_data( $key, $value ) { $this->meta_data[ $key ] = $value; }
        public function get_meta_data() { return $this->meta_data; }
```

- [ ] **Step 2: Write the failing tests for `apply_quote`**

En `tests/CoordinadoraTest.php`, antes del `}` final:

```php
    // --- apply_quote ---
    public function test_apply_quote_replaces_coordinadora_rate(): void {
        $rates = array(
            'coordinadora:3'   => new WC_Shipping_Rate( 'coordinadora:3', 'Coordinadora', 99000 ),
            'ccmck_local_pickup' => new WC_Shipping_Rate( 'ccmck_local_pickup', 'Recogida local', 0 ),
        );
        $out = CCMCK_Coordinadora::apply_quote( $rates, array( 'ok' => true, 'flete_total' => 15700, 'dias' => 2, 'error' => '' ) );
        $this->assertArrayNotHasKey( 'coordinadora:3', $out );
        $this->assertArrayHasKey( 'ccmck_coordinadora', $out );
        $this->assertArrayHasKey( 'ccmck_local_pickup', $out );
        $this->assertSame( 15700.0, $out['ccmck_coordinadora']->get_cost() );
        $this->assertSame( 2, $out['ccmck_coordinadora']->get_meta_data()['dias_entrega'] );
    }

    public function test_apply_quote_failure_keeps_rates_intact(): void {
        $rates = array( 'coordinadora:3' => new WC_Shipping_Rate( 'coordinadora:3', 'Coordinadora', 99000 ) );
        $out = CCMCK_Coordinadora::apply_quote( $rates, array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => 'x' ) );
        $this->assertArrayHasKey( 'coordinadora:3', $out );
        $this->assertArrayNotHasKey( 'ccmck_coordinadora', $out );
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: FAIL — `Call to undefined method CCMCK_Coordinadora::apply_quote()`.

- [ ] **Step 4: Implement `apply_quote` (puro)**

En `includes/class-ccmck-coordinadora.php`, antes del `}` de cierre:

```php
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
```

- [ ] **Step 5: Run apply_quote tests to verify they pass**

Run: `php phpunit.phar tests/CoordinadoraTest.php`
Expected: PASS (21 tests).

- [ ] **Step 6: Implement the WC/HTTP glue (no unit test — verificación manual en deploy)**

En `includes/class-ccmck-coordinadora.php`, antes del `}` de cierre. Estos métodos tocan WC/HTTP y no se testean con PHPUnit; se verifican en el checkout real (Task 9 / despliegue en dos pasos):

```php
    /** Log al canal de WooCommerce. */
    private static function log( string $msg ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning( $msg, array( 'source' => 'ccmck-coordinadora' ) );
        }
    }

    /** Reglas de caja como mapa cat_id => N desde los ajustes. */
    private static function rules_map(): array {
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

    /** Llama a Cotizador.cotizar (timeout 5 s). Devuelve la forma de parse_response. */
    public static function quote( array $args ): array {
        $body     = wp_json_encode( self::build_request( $args ) );
        $response = wp_remote_post( 'https://ws.coordinadora.com/ags/1.5/server.php', array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $body,
        ) );
        if ( is_wp_error( $response ) ) {
            self::log( 'HTTP: ' . $response->get_error_message() );
            return array( 'ok' => false, 'flete_total' => 0, 'dias' => 0, 'error' => $response->get_error_message() );
        }
        $parsed = self::parse_response(
            (string) wp_remote_retrieve_body( $response ),
            wp_remote_retrieve_response_code( $response )
        );
        if ( ! $parsed['ok'] ) {
            self::log( 'API: ' . $parsed['error'] );
        }
        return $parsed;
    }

    /**
     * Filtro woocommerce_package_rates: cotiza y reemplaza la tarifa de
     * Coordinadora. Aborta (devuelve $rates intacto) si el toggle está off, faltan
     * credenciales, algún producto no tiene peso/dimensiones, o no hay DANE destino.
     */
    public static function rates( $rates, $package = array() ): array {
        $rates = is_array( $rates ) ? $rates : array();
        if ( ! CCMCK_Settings::get( 'coordinadora_enabled', false ) ) {
            return $rates;
        }
        $apikey = (string) CCMCK_Settings::get( 'coordinadora_apikey', '' );
        $clave  = (string) CCMCK_Settings::get( 'coordinadora_clave', '' );
        if ( '' === $apikey || '' === $clave ) {
            return $rates;
        }
        $package = is_array( $package ) ? $package : array();
        $items   = self::items_from_package( $package );
        if ( ! $items ) {
            return $rates;
        }
        foreach ( $items as $it ) {
            if ( $it['weight'] <= 0 || $it['largo'] <= 0 || $it['ancho'] <= 0 || $it['alto'] <= 0 ) {
                self::log( 'Producto sin peso/dimensiones; se usa el fallback del plugin viejo.' );
                return $rates;
            }
        }
        $destino = self::dane_from_city( (string) ( $package['destination']['city'] ?? '' ) );
        if ( '' === $destino ) {
            return $rates;
        }
        $threshold = (float) CCMCK_Settings::get( 'coordinadora_weight_threshold', 5.0 );
        $boxes     = self::pack( $items, $threshold, self::rules_map() );
        $detalle   = self::build_detalle( $boxes );

        $quote = self::quote( array(
            'nit'        => (string) CCMCK_Settings::get( 'coordinadora_nit', '' ),
            'origen'     => (string) CCMCK_Settings::get( 'coordinadora_origin', '08001000' ),
            'destino'    => $destino,
            'valoracion' => self::valoracion_from_package( $package ),
            'detalle'    => $detalle,
            'apikey'     => $apikey,
            'clave'      => $clave,
        ) );
        return self::apply_quote( $rates, $quote );
    }

    public static function init(): void {
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'rates' ), 20, 2 );
    }
```

- [ ] **Step 7: Register the module in the bootstrap**

En `ccm-checkout.php`, en el bloque de `require_once` (líneas 15-31), tras la línea de `class-ccmck-surcharge.php`:

```php
require_once CCMCK_DIR . 'includes/class-ccmck-coordinadora.php';
```

Y en `ccmck_boot()` (líneas 44-59), tras `CCMCK_Surcharge::init();`:

```php
    CCMCK_Coordinadora::init();
```

- [ ] **Step 8: Run the full suite + lint the new file**

Run: `php phpunit.phar`
Expected: **OK** (21 tests nuevos en CoordinadoraTest, sin regresiones).
Run: `php -l includes/class-ccmck-coordinadora.php`
Expected: `No syntax errors detected`.

- [ ] **Step 9: Commit**

```bash
git add includes/class-ccmck-coordinadora.php tests/CoordinadoraTest.php tests/bootstrap.php ccm-checkout.php
git commit -m "feat(coordinadora): apply_quote, cliente HTTP, filtro de tarifas y registro

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Días de entrega en la card de envío

**Files:**
- Modify: `includes/class-ccmck-shipping.php` (`build_methods` ~32-42; `render_cards` ~80-83)
- Modify: `tests/bootstrap.php` (stub `_n`)
- Test: `tests/ShippingTest.php` (helper `rate()` con meta; tests nuevos)

**Interfaces:**
- Consumes: meta `dias_entrega` en la `WC_Shipping_Rate` (la pone `apply_quote`).
- Produces: cada método de `build_methods()` incluye `'eta' => int`; `render_cards()` pinta `<span class="ccmck-ship-eta">` cuando `eta > 0`.

- [ ] **Step 1: Add the `_n` stub to the bootstrap**

En `tests/bootstrap.php`, junto a los otros stubs de i18n (tras el bloque de `__` ~línea 41):

```php
if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) {
        return 1 === (int) $number ? $single : $plural;
    }
}
```

- [ ] **Step 2: Write the failing tests**

En `tests/ShippingTest.php`: (a) extender el helper `rate()` para aceptar meta opcional; (b) añadir tests. Reemplazar el método `rate()` (líneas 6-14) por:

```php
    private function rate( string $id, string $label, float $cost, array $meta = array() ): object {
        return new class( $id, $label, $cost, $meta ) {
            public $id; public $label; public $cost; public $meta;
            public function __construct( $i, $l, $c, $m ) { $this->id = $i; $this->label = $l; $this->cost = $c; $this->meta = $m; }
            public function get_id() { return $this->id; }
            public function get_label() { return $this->label; }
            public function get_cost() { return $this->cost; }
            public function get_meta_data() { return $this->meta; }
        };
    }
```

Y antes del `}` final de la clase:

```php
    public function test_build_methods_reads_eta_from_meta(): void {
        $packages = array( array( 'rates' => array(
            $this->rate( 'ccmck_coordinadora', 'Coordinadora', 15700, array( 'dias_entrega' => 2 ) ),
        ) ) );
        $out = CCMCK_Shipping::build_methods( $packages, array() );
        $this->assertSame( 2, $out[0]['rates'][0]['eta'] );
    }

    public function test_build_methods_eta_zero_when_no_meta(): void {
        $packages = array( array( 'rates' => array( $this->rate( 'a', 'A', 100 ) ) ) );
        $out = CCMCK_Shipping::build_methods( $packages, array() );
        $this->assertSame( 0, $out[0]['rates'][0]['eta'] );
    }

    public function test_render_cards_prints_eta_when_present(): void {
        $methods = array( array( 'index' => 0, 'rates' => array(
            array( 'id' => 'ccmck_coordinadora', 'label' => 'Coordinadora', 'cost' => 15700.0, 'checked' => true, 'eta' => 2 ),
        ) ) );
        $html = CCMCK_Shipping::render_cards( $methods );
        $this->assertStringContainsString( 'ccmck-ship-eta', $html );
        $this->assertStringContainsString( '2 días hábiles', $html );
    }

    public function test_render_cards_no_eta_when_zero(): void {
        $methods = array( array( 'index' => 0, 'rates' => array(
            array( 'id' => 'a', 'label' => 'A', 'cost' => 100.0, 'checked' => true, 'eta' => 0 ),
        ) ) );
        $this->assertStringNotContainsString( 'ccmck-ship-eta', CCMCK_Shipping::render_cards( $methods ) );
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php phpunit.phar tests/ShippingTest.php`
Expected: FAIL (`eta` no existe en la salida; no se pinta el span).

- [ ] **Step 4: Read the eta in `build_methods`**

En `includes/class-ccmck-shipping.php`, dentro del `foreach ( $rates as $rate )` de `build_methods()`, tras la línea que calcula `$cost` (línea 35) y antes de `$methods[] = array(`:

```php
                $eta = 0;
                if ( is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ) {
                    $meta = (array) $rate->get_meta_data();
                    if ( isset( $meta['dias_entrega'] ) ) {
                        $eta = (int) $meta['dias_entrega'];
                    }
                }
```

Y añadir `'eta' => $eta,` al array `$methods[] = array( ... )`:

```php
                $methods[] = array(
                    'id'      => $id,
                    'label'   => $label,
                    'cost'    => $cost,
                    'checked' => ( '' !== $id && $id === $chosen_id ),
                    'eta'     => $eta,
                );
```

- [ ] **Step 5: Print the eta in `render_cards`**

En `render_cards()`, tras la línea del `<span class="ccmck-ship-cost">` (línea 82), añadir:

```php
                if ( ! empty( $rate['eta'] ) && (int) $rate['eta'] > 0 ) {
                    $eta   = (int) $rate['eta'];
                    $html .= '<span class="ccmck-ship-eta">' . esc_html( sprintf(
                        _n( 'Llega en %d día hábil', 'Llega en %d días hábiles', $eta, 'ccm-checkout' ),
                        $eta
                    ) ) . '</span>';
                }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php phpunit.phar tests/ShippingTest.php`
Expected: PASS. Luego `php phpunit.phar` → **OK** (sin regresiones; los tests viejos de `render_cards` no incluyen `eta` → no pintan el span).

- [ ] **Step 7: Commit**

```bash
git add includes/class-ccmck-shipping.php tests/ShippingTest.php tests/bootstrap.php
git commit -m "feat(shipping): días de entrega (eta) en la card desde la meta de la rate

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: UI de ajustes (pestaña Coordinadora + repeater) y estilo de la card

**Files:**
- Modify: `includes/views/settings-page.php` (nueva pestaña en el `nav-tab-wrapper` ~24-29; nuevo `.ccmck-tab-panel` tras el panel `pagos` que cierra ~antes del final)
- Modify: `assets/ccmck-checkout.css` (estilo `.ccmck-ship-eta`)

**Interfaces:**
- Consumes: `$s` (array de settings ya cargado en la vista, p. ej. `$s['coordinadora_enabled']`), keys de Task 1.
- Sin tests unitarios (las vistas no se testean en este repo). Verificación manual en `wp-admin`.

- [ ] **Step 1: Add the tab anchor**

En `includes/views/settings-page.php`, en `<h2 class="nav-tab-wrapper ccmck-tabs">` (~24-29), tras el anchor de `pagos`:

```php
	<a href="#coordinadora" class="nav-tab" data-tab="coordinadora"><?php esc_html_e( 'Coordinadora', 'ccm-checkout' ); ?></a>
```

- [ ] **Step 2: Add the tab panel**

Tras el cierre `</div>` del panel `data-tab="pagos"` (y antes del cierre del `<form>`/contenedor general), añadir el panel completo:

```php
<div class="ccmck-tab-panel" data-tab="coordinadora">

	<h2><?php esc_html_e( 'Cotización de envío (Coordinadora)', 'ccm-checkout' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Cotiza el flete directo con tus credenciales agrupando el carrito en cajas. Con el interruptor apagado, el checkout sigue usando el método anterior.', 'ccm-checkout' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Activar', 'ccm-checkout' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="ccmck_settings[coordinadora_enabled]" value="1" <?php checked( ! empty( $s['coordinadora_enabled'] ) ); ?> />
					<?php esc_html_e( 'Cotizar el flete de Coordinadora desde este plugin', 'ccm-checkout' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ccmck_coord_apikey">API key</label></th>
			<td><input type="text" id="ccmck_coord_apikey" class="regular-text" name="ccmck_settings[coordinadora_apikey]" value="<?php echo esc_attr( $s['coordinadora_apikey'] ); ?>" autocomplete="off" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="ccmck_coord_clave"><?php esc_html_e( 'Clave', 'ccm-checkout' ); ?></label></th>
			<td><input type="password" id="ccmck_coord_clave" class="regular-text" name="ccmck_settings[coordinadora_clave]" value="<?php echo esc_attr( $s['coordinadora_clave'] ); ?>" autocomplete="new-password" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="ccmck_coord_nit">NIT</label></th>
			<td><input type="text" id="ccmck_coord_nit" class="regular-text" name="ccmck_settings[coordinadora_nit]" value="<?php echo esc_attr( $s['coordinadora_nit'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="ccmck_coord_origin"><?php esc_html_e( 'Ciudad origen (DANE)', 'ccm-checkout' ); ?></label></th>
			<td>
				<input type="text" id="ccmck_coord_origin" class="regular-text" name="ccmck_settings[coordinadora_origin]" value="<?php echo esc_attr( $s['coordinadora_origin'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Código DANE de 8 dígitos. Barranquilla = 08001000.', 'ccm-checkout' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ccmck_coord_thr"><?php esc_html_e( 'Umbral de peso (kg)', 'ccm-checkout' ); ?></label></th>
			<td>
				<input type="number" step="0.1" min="0" id="ccmck_coord_thr" name="ccmck_settings[coordinadora_weight_threshold]" value="<?php echo esc_attr( (string) $s['coordinadora_weight_threshold'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Productos con peso ≥ este valor van en cajas separadas; por debajo se consolidan.', 'ccm-checkout' ); ?></p>
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Reglas de caja por categoría', 'ccm-checkout' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Cuántas unidades de una categoría caben por caja (ej.: Parlantes → 2). Las categorías sin regla se resuelven por peso.', 'ccm-checkout' ); ?></p>

	<?php
	$cats = function_exists( 'get_terms' ) ? get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) : array();
	$cats = is_array( $cats ) ? $cats : array();
	$render_rule_row = static function ( $index, $cat_id, $n ) use ( $cats ) {
		ob_start(); ?>
		<div class="ccmck-row">
			<select name="ccmck_settings[coordinadora_box_rules][<?php echo esc_attr( (string) $index ); ?>][cat]">
				<option value="0"><?php esc_html_e( '— Categoría —', 'ccm-checkout' ); ?></option>
				<?php foreach ( $cats as $cat ) : ?>
					<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( (int) $cat_id, (int) $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="number" min="1" step="1" placeholder="N"
			       name="ccmck_settings[coordinadora_box_rules][<?php echo esc_attr( (string) $index ); ?>][n]"
			       value="<?php echo esc_attr( $n > 0 ? (string) $n : '' ); ?>" />
			<button type="button" class="button-link ccmck-remove-row"><?php esc_html_e( 'Quitar', 'ccm-checkout' ); ?></button>
		</div>
		<?php return ob_get_clean();
	};
	?>

	<div class="ccmck-repeater" data-field="coordinadora_box_rules" id="ccmck-repeater-coordinadora_box_rules">
		<?php foreach ( (array) $s['coordinadora_box_rules'] as $i => $row ) : ?>
			<?php echo $render_rule_row( (int) $i, (int) ( $row['cat'] ?? 0 ), (int) ( $row['n'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endforeach; ?>
		<div class="ccmck-row ccmck-row-template" style="display:none">
			<select name="ccmck_settings[coordinadora_box_rules][__i__][cat]">
				<option value="0"><?php esc_html_e( '— Categoría —', 'ccm-checkout' ); ?></option>
				<?php foreach ( $cats as $cat ) : ?>
					<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="number" min="1" step="1" placeholder="N" name="ccmck_settings[coordinadora_box_rules][__i__][n]" value="" />
			<button type="button" class="button-link ccmck-remove-row"><?php esc_html_e( 'Quitar', 'ccm-checkout' ); ?></button>
		</div>
	</div>
	<button type="button" class="button ccmck-add-row" data-repeater="ccmck-repeater-coordinadora_box_rules">
		<?php esc_html_e( 'Añadir regla', 'ccm-checkout' ); ?>
	</button>

</div>
```

> El JS del repeater (`assets/ccmck-admin.js`) es genérico: clona `.ccmck-row-template` y reemplaza `__i__`. **No requiere cambios.** Verificar que "Añadir regla" / "Quitar" funcionan igual que en los otros repeaters.

- [ ] **Step 3: Add the card style**

En `assets/ccmck-checkout.css`, al final:

```css
.ccmck-ship-eta {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    color: #6b7280;
}
```

- [ ] **Step 4: Manual verification in wp-admin (staging)**

- Ir a *Ajustes → Checkout CCM → Coordinadora*: la pestaña aparece y cambia al hacer clic.
- Añadir una regla, elegir la categoría **Parlantes** y `N = 2`, guardar. Recargar: la fila persiste.
- Dejar `coordinadora_enabled` **apagado** por ahora (se activa en el despliegue de Task 9).

- [ ] **Step 5: Commit**

```bash
git add includes/views/settings-page.php assets/ccmck-checkout.css
git commit -m "feat(settings-ui): pestaña Coordinadora, repeater de reglas y estilo de eta

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: CHANGELOG + verificación en vivo (despliegue en dos pasos)

**Files:**
- Modify: `docs/CHANGELOG.md` (sección *[Sin publicar] → Añadido*)

- [ ] **Step 1: Add the changelog entry**

En `docs/CHANGELOG.md`, bajo `## [Sin publicar]` → `### Añadido`, como primer ítem:

```markdown
- **Cotización de Coordinadora con armado de cajas por tipo de producto**: nuevo módulo
  `CCMCK_Coordinadora` que cotiza el flete directo contra `Cotizador.cotizar` (JSON-RPC)
  con las credenciales de la tienda, agrupando el carrito en cajas (parlantes N por caja
  configurable por categoría, productos ≥umbral en cajas separadas, <umbral consolidados)
  y sumando dimensiones por bulto para reflejar el peso volumétrico real. Reemplaza la
  tarifa del plugin de terceros (`coordinadora:N`) por la propia (`ccmck_coordinadora`) y,
  si la API falla o falta peso/dimensiones en algún producto, conserva la tarifa anterior
  como *fallback*. Muestra los días de entrega en la card. Configurable en
  *Ajustes → Checkout CCM → Coordinadora* (toggle, credenciales, ciudad origen, umbral y
  tabla de reglas por categoría). Enganchado a `woocommerce_package_rates` (patrón de
  `CCMCK_Pickup`). Tests en `CoordinadoraTest`, `ShippingTest` y `SettingsTest`.
```

- [ ] **Step 2: Run the full suite one last time**

Run: `php phpunit.phar`
Expected: **OK** (todos los tests, incluidos los nuevos de las 3 suites).

- [ ] **Step 3: Commit**

```bash
git add docs/CHANGELOG.md
git commit -m "docs: changelog de la cotización de Coordinadora por cajas

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

- [ ] **Step 4: Deploy paso 1 — código apagado**

- Subir por File Manager los PHP nuevos/modificados + assets (ver `docs/DEPLOY.md`), **purgar OPcache**.
- Con `coordinadora_enabled = false`, el checkout debe comportarse **igual que hoy** (sigue el plugin viejo). Verificar un checkout normal.

- [ ] **Step 5: Deploy paso 2 — activar y verificar en vivo**

- En *Ajustes → Checkout CCM → Coordinadora*: cargar API key, clave, NIT, origen `08001000`, regla **Parlantes → 2**, y activar el toggle. Guardar.
- En el checkout, con dirección de destino real (ej. Bogotá), probar estos carritos y comparar el flete contra una cotización manual en la web de Coordinadora:
  1. **1 accesorio pequeño** (<5 kg) → debe salir tarifa de paquetería (~$15.700 a Bogotá).
  2. **4 parlantes** → 2 cajas; el flete no debe multiplicarse por bulto suelto.
  3. **Combo parlante + 2 accesorios** → los accesorios se consolidan; comparar contra el precio inflado anterior.
  4. **Ciudad no cubierta / dirección incompleta** → debe caer al fallback sin romper el checkout.
- Confirmar que la card muestra "Coordinadora — $… · Llega en N días hábiles".
- Revisar el log `WooCommerce → Estado → Logs`, canal `ccmck-coordinadora`, por si hubo fallbacks.

---

## Notas de ejecución

- **TDD estricto** en Tasks 1-7 (rojo → verde → commit). Tasks 8-9 son UI/deploy sin test unitario; su red de seguridad es el motor puro ya cubierto + la verificación manual del despliegue en dos pasos.
- **La ruta del proyecto tiene espacios** (`Filtro - buscador `): ejecutar los comandos con el cwd ya dentro de la carpeta del plugin; no hace falta pasar rutas absolutas.
- Si un test de `pack`/`build_detalle` falla por orden de las cajas, recordar que `pack` procesa los ítems en el orden recibido y **la caja consolidada de accesorios va al final**.
```
