# Barredor de guías sin generar — Plan de implementación

> **Para trabajadores agénticos:** SUB-SKILL OBLIGATORIA: usa `superpowers:subagent-driven-development` (recomendado) o `superpowers:executing-plans` para implementar este plan tarea por tarea. Los pasos usan casillas (`- [ ]`) para seguimiento.

**Objetivo:** Que ningún pedido pagado con envío Coordinadora se quede sin guía en silencio: un barredor cada 15 min reintenta los que faltan, y avisa cuando un pedido agota los reintentos.

**Arquitectura:** El plugin expone un endpoint nuevo `/ccmck/v1/barrer-guia` cuyo núcleo de decisión es una función **pura** (`sweep_decision`) testeable sin WordPress; reutiliza `generate_for_order()`, que ya existe. Un workflow de n8n copia la estructura exacta de `cwFacturaSweep01` (el barredor de facturas que ya funciona) y llama a ese endpoint. **El cron vive en n8n, no en WordPress** — ver Restricciones.

**Stack:** PHP 8.3 · WooCommerce (almacenamiento clásico) · PHPUnit 12.5 (phar en la raíz del repo) · n8n (scheduleTrigger)

## Restricciones globales

- **`DISABLE_WP_CRON` está en `true`** en `wp-config.php`. Un barredor por `wp_schedule_event` **nunca se ejecutaría**. Verificado el 01-sep-2026.
- **Action Scheduler está saturado**: 745.133 acciones `failed` frente a 984.708 `complete`. No apoyarse en él. Verificado el 01-sep-2026.
- El cron va en **n8n**, siguiendo `cwFacturaSweep01` ("CW Factura - Barredor processing sin factura (15min)").
- **No tocar `/ccmck/v1/generar-guia`.** Lo usa el bot en producción; se añade un endpoint aparte para no arriesgar regresión.
- Autenticación de los endpoints del plugin: cabecera `x-ccmck-secret` contra `CCMCK_Settings::get('guias_api_secret')`.
- Todo nodo nuevo de n8n lleva `onError: "continueRegularOutput"` y, si es observabilidad, va en **rama lateral** (CLAUDE.md §4).
- Desplegar a n8n es **un solo paso**: `backup → import:workflow → publish_workflow`. Nunca entregar un `import` suelto (CLAUDE.md §1).
- Producción exige **GO explícito**, uno por despliegue (CLAUDE.md §2).
- Ningún texto de cliente entra a git: ni en fixtures, ni en tests, ni en mensajes de commit (CLAUDE.md §7).

## Contexto del fallo que esto corrige

Tres modos de fallo observados, **todos terminan igual: pedido pagado, stock descontado, sin guía y sin aviso**.

| Pedido | Fecha | Causa | ¿Dejó rastro? |
|---|---|---|---|
| #34378 | 27-ago | `cURL error 28` timeout 15 s | Nota en el pedido + log |
| #34423 | 30-ago | `cURL error 28` timeout 15 s | Nota en el pedido + log |
| **#34487** | 31-ago | **Descarte silencioso** | **Nada, en ningún sitio** |

El #34487 no aparece en ningún log. En `on_processing()`, cuando `should_generate()` devuelve `ok:false`, el código hace `return` sin escribir nada — el comentario lo dice: *"Silencioso para off/duplicado/etc.; son casos normales"*. Cinco de sus seis motivos no dejan rastro, incluido `generación en curso (lock)`.

El candado (`ccmck_guia_lock_<id>`, 60 s) se pone **antes** de generar. Si la petición que lo tomó muere a mitad, el candado sobrevive, el reintento inmediato se descarta en silencio y **nadie vuelve a intentarlo nunca**. Ese pedido tuvo dos avisos concurrentes de Mercado Pago en el mismo minuto, lo que encaja con esa carrera — aunque no está probado que el proceso muriera: no hay error fatal registrado a esa hora.

**Este plan no intenta adivinar la causa exacta.** Cierra el agujero por abajo: da igual por qué no se generó, el barredor lo detecta y lo reintenta.

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---|---|
| `includes/class-ccmck-guias.php` (modificar) | Añadir la decisión pura `sweep_decision()`, las constantes del barredor y el endpoint `/barrer-guia`. Nada más. |
| `tests/GuiasSweepTest.php` (crear) | Pruebas de `sweep_decision()`. Archivo aparte: `GuiasTest.php` ya tiene 30+ casos y crece sin control. |
| `docs/n8n/cwGuiaSweep01.import-ready.json` (crear) | Workflow de n8n listo para `import:workflow`, sin credenciales embebidas. |
| `docs/CHANGELOG.md` (modificar) | Una línea por el cambio. |

---

### Tarea 1: Decisión pura del barredor

**Archivos:**
- Modificar: `includes/class-ccmck-guias.php` (constantes junto a las demás, ~línea 24; método junto a `should_generate`, ~línea 63)
- Test: `tests/GuiasSweepTest.php` (crear)

**Interfaces:**
- Consume: `self::is_coordinadora( array $shipping_ids ): bool` — ya existe en la clase.
- Produce: `CCMCK_Guias::sweep_decision( array $ctx ): array{ok:bool, reason:string}` y las constantes `SWEEP_GRACIA_MIN`, `SWEEP_MAX_INTENTOS`, `META_INTENTOS`. Las usan las tareas 2 y 3.

- [ ] **Paso 1: Escribir la prueba que falla**

Crear `tests/GuiasSweepTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class GuiasSweepTest extends TestCase {

	/** Contexto de un pedido que SÍ debe barrerse; cada prueba cambia una cosa. */
	private function ctx( array $over = array() ): array {
		return array_merge( array(
			'status'        => 'processing',
			'shipping_ids'  => array( 'ccmck_coordinadora' ),
			'existing_guia' => '',
			'minutos'       => 30,
			'intentos'      => 0,
		), $over );
	}

	public function test_pedido_pagado_sin_guia_se_barre(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['reason'] );
	}

	public function test_no_toca_pedidos_fuera_de_processing(): void {
		foreach ( array( 'pending', 'failed', 'cancelled', 'completed', '' ) as $st ) {
			$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'status' => $st ) ) );
			$this->assertFalse( $r['ok'], "estado $st no debe barrerse" );
			$this->assertSame( 'no está en processing', $r['reason'] );
		}
	}

	public function test_no_crea_guias_de_coordinadora_para_otra_transportadora(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'shipping_ids' => array( 'flat_rate:3' ) ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'envío con otra transportadora', $r['reason'] );
	}

	public function test_no_duplica_si_ya_tiene_guia(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'existing_guia' => '33042000490' ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'el pedido ya tiene guía', $r['reason'] );
	}

	/** Un espacio no es una guía: si no, el barredor se saltaría el pedido para siempre. */
	public function test_guia_en_blanco_no_cuenta_como_guia(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'existing_guia' => '   ' ) ) );
		$this->assertTrue( $r['ok'] );
	}

	/** Gracia: el camino normal tiene su oportunidad antes de que entre el barredor. */
	public function test_respeta_la_gracia_de_los_primeros_minutos(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'minutos' => 3 ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'demasiado reciente', $r['reason'] );
	}

	public function test_justo_en_el_limite_de_la_gracia_ya_se_barre(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'minutos' => CCMCK_Guias::SWEEP_GRACIA_MIN ) ) );
		$this->assertTrue( $r['ok'] );
	}

	/** Sin tope, un pedido con defecto permanente martillea a Coordinadora cada 15 min. */
	public function test_se_rinde_tras_agotar_los_intentos(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'intentos' => CCMCK_Guias::SWEEP_MAX_INTENTOS ) ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'agotados los reintentos', $r['reason'] );
	}

	public function test_el_ultimo_intento_disponible_si_se_usa(): void {
		$r = CCMCK_Guias::sweep_decision( $this->ctx( array( 'intentos' => CCMCK_Guias::SWEEP_MAX_INTENTOS - 1 ) ) );
		$this->assertTrue( $r['ok'] );
	}

	/** Un contexto vacío no debe reventar ni, peor, devolver ok. */
	public function test_contexto_vacio_no_revienta_y_no_barre(): void {
		$r = CCMCK_Guias::sweep_decision( array() );
		$this->assertFalse( $r['ok'] );
		$this->assertNotSame( '', $r['reason'] );
	}
}
```

- [ ] **Paso 2: Ejecutar la prueba y verificar que FALLA**

```bash
php phpunit.phar --filter GuiasSweepTest --no-coverage
```

Esperado: FALLA con `Error: Call to undefined method CCMCK_Guias::sweep_decision()`.

**Si pasa en verde, párate:** significa que el método ya existe y este plan está desactualizado.

- [ ] **Paso 3: Añadir las constantes**

En `includes/class-ccmck-guias.php`, junto a las demás constantes de clase (después de `const META_MODALIDAD`, ~línea 24):

```php
	/** Intentos que lleva el barredor sobre este pedido. */
	const META_INTENTOS = '_ccmck_guia_intentos';

	/**
	 * Minutos de gracia antes de que el barredor toque un pedido.
	 *
	 * El camino normal (`on_processing`) debe tener su oportunidad primero: sin
	 * gracia, el barredor competiría con él y ambos llamarían a Coordinadora.
	 */
	const SWEEP_GRACIA_MIN = 10;

	/**
	 * Cuántas veces reintenta el barredor antes de rendirse.
	 *
	 * Un pedido con defecto permanente —producto sin peso, dirección incompleta—
	 * no se arregla reintentando: sin tope martillearía el WS cada 15 minutos y
	 * llenaría el pedido de notas.
	 */
	const SWEEP_MAX_INTENTOS = 3;
```

- [ ] **Paso 4: Escribir la implementación mínima**

En el mismo archivo, justo después de `should_generate()` (que termina ~línea 63):

```php
	/**
	 * ¿Debe el barredor generar la guía de este pedido? PURA.
	 *
	 * Deliberadamente NO acepta `manual`: a diferencia de `/generar-guia`, el
	 * barredor jamás debe saltarse el guard de transportadora. Un pedido enviado
	 * por otra empresa no puede acabar con un rótulo de Coordinadora.
	 *
	 * @param array $ctx {status, shipping_ids, existing_guia, minutos, intentos}
	 * @return array{ok:bool, reason:string}
	 */
	public static function sweep_decision( array $ctx ): array {
		if ( 'processing' !== (string) ( $ctx['status'] ?? '' ) ) {
			return array( 'ok' => false, 'reason' => 'no está en processing' );
		}
		if ( ! self::is_coordinadora( (array) ( $ctx['shipping_ids'] ?? array() ) ) ) {
			return array( 'ok' => false, 'reason' => 'envío con otra transportadora' );
		}
		if ( '' !== trim( (string) ( $ctx['existing_guia'] ?? '' ) ) ) {
			return array( 'ok' => false, 'reason' => 'el pedido ya tiene guía' );
		}
		if ( (int) ( $ctx['minutos'] ?? 0 ) < self::SWEEP_GRACIA_MIN ) {
			return array( 'ok' => false, 'reason' => 'demasiado reciente' );
		}
		if ( (int) ( $ctx['intentos'] ?? 0 ) >= self::SWEEP_MAX_INTENTOS ) {
			return array( 'ok' => false, 'reason' => 'agotados los reintentos' );
		}
		return array( 'ok' => true, 'reason' => '' );
	}
```

- [ ] **Paso 5: Ejecutar las pruebas y verificar que PASAN**

```bash
php phpunit.phar --filter GuiasSweepTest --no-coverage
```

Esperado: `OK (10 tests, ...)`.

- [ ] **Paso 6: Ejecutar la suite completa (no romper nada)**

```bash
php phpunit.phar --no-coverage
```

Esperado: `OK, but there were issues!` con **260 tests** (250 previos + 10 nuevos) y 1 deprecation conocida en `ReportsTest.php:98`. Cero failures, cero errors.

- [ ] **Paso 7: Commit**

```bash
git add includes/class-ccmck-guias.php tests/GuiasSweepTest.php
git commit -m "feat(guias): decision pura del barredor de guias sin generar"
```

---

### Tarea 2: Endpoint `/barrer-guia`

**Archivos:**
- Modificar: `includes/class-ccmck-guias.php` (método nuevo junto a `rest_generate`; registro de ruta junto a la de `/generar-guia`, ~línea 1011)
- Test: `tests/GuiasSweepTest.php` (añadir casos)

**Interfaces:**
- Consume: `self::sweep_decision()` (Tarea 1), `self::generate_for_order( $order, array $opts ): array{ok:bool, error:string}` y `self::ce_requested()`, ambos ya existentes; `self::rest_permission()` para la autenticación.
- Produce: `POST /wp-json/ccmck/v1/barrer-guia` con cuerpo `{"order_id": int}`. Respuestas: `200 {ok:true, guia:string, intentos:int}` · `200 {ok:false, skipped:true, reason:string}` cuando no toca barrer · `422 {code:"ccmck_failed"}` si Coordinadora falla · `404` si no existe el pedido · `403` sin secreto. La Tarea 3 depende de esta forma exacta.

- [ ] **Paso 1: Escribir la prueba que falla**

Añadir al final de `tests/GuiasSweepTest.php`, antes de la llave de cierre de la clase:

```php
	/** El motivo de descarte viaja al barredor para que pueda distinguir "ya está" de "se rindió". */
	public function test_motivos_son_estables_y_distinguibles(): void {
		$motivos = array();
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'status' => 'failed' ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'shipping_ids' => array( 'flat_rate:3' ) ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'existing_guia' => 'X' ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'minutos' => 1 ) ) )['reason'];
		$motivos[] = CCMCK_Guias::sweep_decision( $this->ctx( array( 'intentos' => 99 ) ) )['reason'];

		// Cinco motivos, cinco cadenas distintas: el barredor alerta solo con una.
		$this->assertCount( 5, array_unique( $motivos ) );
		$this->assertContains( 'agotados los reintentos', $motivos );
	}
```

- [ ] **Paso 2: Ejecutar y verificar que PASA**

```bash
php phpunit.phar --filter GuiasSweepTest --no-coverage
```

Esperado: `OK (11 tests, ...)`. Esta prueba blinda los motivos como contrato: si alguien reescribe una cadena, la Tarea 3 deja de alertar y esta prueba lo caza.

- [ ] **Paso 3: Implementar el endpoint**

En `includes/class-ccmck-guias.php`, justo después del método `rest_generate()`:

```php
	/**
	 * POST /wp-json/ccmck/v1/barrer-guia {order_id} — reintento del barredor.
	 *
	 * Separado de `/generar-guia` a propósito: aquel pasa `manual:true` y se
	 * salta el guard de transportadora, cosa que un proceso automático no debe
	 * hacer nunca. Aquí el guard se respeta.
	 *
	 * Idempotente: si el pedido ya tiene guía responde `skipped` sin tocar nada.
	 */
	public static function rest_sweep( $request ) {
		$order_id = absint( $request['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return new WP_Error( 'ccmck_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
		}

		$pagado   = $order->get_date_paid();
		$minutos  = $pagado ? (int) floor( ( time() - $pagado->getTimestamp() ) / 60 ) : 0;
		$intentos = (int) $order->get_meta( self::META_INTENTOS );

		$check = self::sweep_decision( array(
			'status'        => (string) $order->get_status(),
			'shipping_ids'  => self::order_shipping_ids( $order ),
			'existing_guia' => (string) $order->get_meta( self::META_GUIA ),
			'minutos'       => $minutos,
			'intentos'      => $intentos,
		) );

		if ( ! $check['ok'] ) {
			return rest_ensure_response( array(
				'ok'       => false,
				'skipped'  => true,
				'reason'   => $check['reason'],
				'intentos' => $intentos,
			) );
		}

		// El contador sube ANTES de intentar: si la petición muere a mitad —que es
		// justamente el fallo que este barredor cubre— el intento igual queda contado
		// y el pedido no se reintenta para siempre.
		$intentos++;
		$order->update_meta_data( self::META_INTENTOS, (string) $intentos );
		$order->save();

		$ce     = self::ce_requested( '', self::order_shipping_ids( $order ), (string) $order->get_meta( self::META_MODALIDAD ) );
		$result = self::generate_for_order( $order, array( 'contra_entrega' => $ce ) );

		if ( ! $result['ok'] ) {
			$order->add_order_note( sprintf(
				'Barredor de guías: intento %d de %d falló (%s).',
				$intentos,
				self::SWEEP_MAX_INTENTOS,
				$result['error']
			) );
			return new WP_Error( 'ccmck_failed', $result['error'], array( 'status' => 422 ) );
		}

		$order->add_order_note( sprintf(
			'Guía generada por el barredor en el intento %d (el camino automático no la había creado).',
			$intentos
		) );

		return rest_ensure_response( array(
			'ok'       => true,
			'guia'     => (string) $order->get_meta( self::META_GUIA ),
			'intentos' => $intentos,
		) );
	}
```

- [ ] **Paso 4: Registrar la ruta**

En el mismo archivo, justo después del bloque `register_rest_route( 'ccmck/v1', '/generar-guia', ... );` (~línea 1015):

```php
		register_rest_route( 'ccmck/v1', '/barrer-guia', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_sweep' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
```

- [ ] **Paso 5: Verificar sintaxis y suite completa**

```bash
php -l includes/class-ccmck-guias.php && php phpunit.phar --no-coverage
```

Esperado: `No syntax errors detected` y **261 tests**, cero failures.

- [ ] **Paso 6: Commit**

```bash
git add includes/class-ccmck-guias.php tests/GuiasSweepTest.php
git commit -m "feat(guias): endpoint barrer-guia con tope de reintentos y notas trazables"
```

---

### Tarea 3: Workflow `cwGuiaSweep01` en n8n

**Archivos:**
- Crear: `docs/n8n/cwGuiaSweep01.import-ready.json`

**Interfaces:**
- Consume: `POST /wp-json/ccmck/v1/barrer-guia` de la Tarea 2, con la forma de respuesta exacta descrita allí.
- Produce: el workflow `cwGuiaSweep01`, que la Tarea 4 importa y publica.

Copia la estructura de `cwFacturaSweep01` (trigger 15 min → ventana → consulta WC → split → acción → resumen) y le añade una **rama lateral** de alerta, como manda CLAUDE.md §4.

- [ ] **Paso 1: Escribir el JSON import-ready**

Crear `docs/n8n/cwGuiaSweep01.import-ready.json`. El `x-ccmck-secret` **no va en el archivo**: se toma de una variable de entorno de n8n (`CCMCK_GUIAS_SECRET`), igual que el resto de secretos.

```json
{
  "id": "cwGuiaSweep01",
  "name": "CW Guía - Barredor processing sin guía (15min)",
  "nodes": [
    {
      "parameters": { "rule": { "interval": [ { "field": "minutes", "minutesInterval": 15 } ] } },
      "id": "a1000000-0000-4000-8000-000000000001",
      "name": "Cada 15 min",
      "type": "n8n-nodes-base.scheduleTrigger",
      "typeVersion": 1.2,
      "position": [ -260, 300 ]
    },
    {
      "parameters": {
        "jsCode": "// pedidos processing modificados en las ultimas 48h (misma ventana que cwFacturaSweep01)\nconst d = new Date(Date.now() - 48*3600*1000);\nconst pad = n => String(n).padStart(2,'0');\nconst iso = d.getUTCFullYear()+'-'+pad(d.getUTCMonth()+1)+'-'+pad(d.getUTCDate())+'T'+pad(d.getUTCHours())+':'+pad(d.getUTCMinutes())+':'+pad(d.getUTCSeconds());\nreturn [{ json: { modified_after: iso } }];"
      },
      "id": "a1000000-0000-4000-8000-000000000002",
      "name": "Ventana 48h",
      "type": "n8n-nodes-base.code",
      "typeVersion": 2,
      "position": [ -40, 300 ],
      "onError": "continueRegularOutput"
    },
    {
      "parameters": {
        "url": "={{ 'https://ccmtiendadelsonido.com/wp-json/wc/v3/orders?status=processing&per_page=40&modified_after=' + $json.modified_after }}",
        "authentication": "predefinedCredentialType",
        "nodeCredentialType": "wooCommerceApi",
        "options": { "timeout": 30000, "response": { "response": { "neverError": true, "fullResponse": true, "responseFormat": "json" } } }
      },
      "id": "a1000000-0000-4000-8000-000000000003",
      "name": "WC processing recientes",
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4.2,
      "position": [ 180, 300 ],
      "onError": "continueRegularOutput"
    },
    {
      "parameters": {
        "jsCode": "// Candidatos: Coordinadora, sin guia y fuera de la gracia.\n// El filtro se repite aqui aunque el endpoint tambien decida: asi no se\n// dispara una peticion por cada pedido sano cada 15 minutos.\nconst GRACIA_MIN = 10;\nconst body = ($json && $json.body) || [];\nconst list = Array.isArray(body) ? body : [];\nconst out = [];\nfor (const o of list) {\n  if (!o || o.status !== 'processing') continue;\n  const ship = (o.shipping_lines || []).map(s => String(s.method_id || '') + ' ' + String(s.method_title || ''));\n  if (!ship.some(s => s.toLowerCase().indexOf('coordinadora') !== -1)) continue;\n  const meta = {};\n  for (const m of (o.meta_data || [])) meta[m.key] = m.value;\n  if (String(meta['_coordinadora_tracking_number'] || '').trim() !== '') continue;\n  const pagado = o.date_paid_gmt ? Date.parse(o.date_paid_gmt + 'Z') : 0;\n  if (!pagado) continue;\n  const minutos = Math.floor((Date.now() - pagado) / 60000);\n  if (minutos < GRACIA_MIN) continue;\n  out.push({ json: { order_id: o.id, numero: o.number, minutos, total: o.total } });\n}\nreturn out;"
      },
      "id": "a1000000-0000-4000-8000-000000000004",
      "name": "Candidatos sin guía",
      "type": "n8n-nodes-base.code",
      "typeVersion": 2,
      "position": [ 400, 300 ],
      "onError": "continueRegularOutput"
    },
    {
      "parameters": {
        "method": "POST",
        "url": "https://ccmtiendadelsonido.com/wp-json/ccmck/v1/barrer-guia",
        "sendHeaders": true,
        "headerParameters": { "parameters": [
          { "name": "Content-Type", "value": "application/json" },
          { "name": "x-ccmck-secret", "value": "={{ $env.CCMCK_GUIAS_SECRET }}" }
        ] },
        "sendBody": true,
        "specifyBody": "json",
        "jsonBody": "={{ JSON.stringify({ order_id: $json.order_id }) }}",
        "options": {
          "timeout": 90000,
          "response": { "response": { "neverError": true, "fullResponse": true, "responseFormat": "json" } },
          "batching": { "batch": { "batchSize": 1, "batchInterval": 3000 } }
        }
      },
      "id": "a1000000-0000-4000-8000-000000000005",
      "name": "Barrer guía",
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4.2,
      "position": [ 620, 300 ],
      "onError": "continueRegularOutput"
    },
    {
      "parameters": {
        "jsCode": "// Resume la pasada y separa a quien se rindio, que es lo unico que merece aviso.\nconst MAX_INTENTOS = 3;   // igual que CCMCK_Guias::SWEEP_MAX_INTENTOS\nconst all = $input.all();\nconst hechas = [];\nconst rendidos = [];\nconst fallos = [];\nfor (const it of all) {\n  const b = (it.json && it.json.body) || {};\n  const cod = (it.json && it.json.statusCode) || 0;\n  if (b.ok === true) { hechas.push(b.guia); continue; }\n  if (b.skipped === true) {\n    if (b.reason === 'agotados los reintentos') rendidos.push({ intentos: b.intentos });\n    continue;\n  }\n  if (cod >= 400) fallos.push({ cod, error: String((b && b.message) || '').slice(0, 120) });\n}\nreturn [{ json: { revisados: all.length, generadas: hechas.length, guias: hechas, rendidos: rendidos.length, fallos: fallos.length, max_intentos: MAX_INTENTOS, detalle_fallos: fallos.slice(0, 5) } }];"
      },
      "id": "a1000000-0000-4000-8000-000000000006",
      "name": "Resumen",
      "type": "n8n-nodes-base.code",
      "typeVersion": 2,
      "position": [ 840, 300 ],
      "onError": "continueRegularOutput"
    },
    {
      "parameters": {
        "conditions": { "options": { "caseSensitive": true, "version": 2 }, "combinator": "or", "conditions": [
          { "id": "c1", "operator": { "type": "number", "operation": "gt" }, "leftValue": "={{ $json.rendidos }}", "rightValue": 0 },
          { "id": "c2", "operator": { "type": "number", "operation": "gt" }, "leftValue": "={{ $json.fallos }}", "rightValue": 0 }
        ] },
        "options": {}
      },
      "id": "a1000000-0000-4000-8000-000000000007",
      "name": "¿Hay que avisar?",
      "type": "n8n-nodes-base.if",
      "typeVersion": 2.2,
      "position": [ 1060, 420 ],
      "onError": "continueRegularOutput"
    },
    {
      "parameters": {
        "fromEmail": "ia@ccmtiendadelsonido.com",
        "toEmail": "ia@ccmtiendadelsonido.com",
        "subject": "=Barredor de guías: {{ $json.rendidos }} pedido(s) sin rótulo tras agotar reintentos",
        "emailFormat": "text",
        "text": "=Pedidos que agotaron los {{ $json.max_intentos }} reintentos: {{ $json.rendidos }}\nFallos en esta pasada: {{ $json.fallos }}\nGeneradas correctamente: {{ $json.generadas }}\nRevisados: {{ $json.revisados }}\n\nDetalle de fallos: {{ JSON.stringify($json.detalle_fallos) }}\n\nEstos pedidos estan pagados y SIN guia. Hay que generarla a mano o corregir el producto/direccion."
      },
      "id": "a1000000-0000-4000-8000-000000000008",
      "name": "Email vigía guías",
      "type": "n8n-nodes-base.emailSend",
      "typeVersion": 2.1,
      "position": [ 1280, 420 ],
      "onError": "continueRegularOutput"
    }
  ],
  "connections": {
    "Cada 15 min":            { "main": [ [ { "node": "Ventana 48h", "type": "main", "index": 0 } ] ] },
    "Ventana 48h":            { "main": [ [ { "node": "WC processing recientes", "type": "main", "index": 0 } ] ] },
    "WC processing recientes":{ "main": [ [ { "node": "Candidatos sin guía", "type": "main", "index": 0 } ] ] },
    "Candidatos sin guía":    { "main": [ [ { "node": "Barrer guía", "type": "main", "index": 0 } ] ] },
    "Barrer guía":            { "main": [ [ { "node": "Resumen", "type": "main", "index": 0 } ] ] },
    "Resumen":                { "main": [ [ { "node": "¿Hay que avisar?", "type": "main", "index": 0 } ] ] },
    "¿Hay que avisar?":       { "main": [ [ { "node": "Email vigía guías", "type": "main", "index": 0 } ], [] ] }
  },
  "settings": { "executionOrder": "v1" }
}
```

- [ ] **Paso 2: Validar el JSON antes de acercarlo a producción**

```bash
python3 -c "import json;d=json.load(open('docs/n8n/cwGuiaSweep01.import-ready.json'));ns=[n['name'] for n in d['nodes']];print('nodos:',len(ns));print('sin onError:',[n['name'] for n in d['nodes'] if n['type'].endswith('scheduleTrigger')==False and 'onError' not in n]);print('conexiones huerfanas:',[k for k in d['connections'] if k not in ns])"
```

Esperado: `nodos: 8`, `sin onError: []`, `conexiones huerfanas: []`.

- [ ] **Paso 3: Comprobar que la variable de entorno existe en n8n**

```bash
ssh -o ConnectTimeout=25 root@2.24.202.75 'docker exec n8n-n8n-1 sh -c "test -n \"\$CCMCK_GUIAS_SECRET\" && echo PRESENTE || echo FALTA"'
```

Si dice `FALTA`, **para y avisa al dueño**: hay que añadirla al `docker-compose` de n8n y reiniciar el contenedor. Sin ella el barredor recibiría 403 en cada llamada. No inventes el secreto ni lo escribas en el JSON.

- [ ] **Paso 4: Commit**

```bash
git add docs/n8n/cwGuiaSweep01.import-ready.json
git commit -m "feat(n8n): barredor de guias cada 15 min con vigia de pedidos rendidos"
```

---

### Tarea 4: Despliegue

**Archivos:**
- Modificar: `docs/CHANGELOG.md`

**Interfaces:**
- Consume: todo lo anterior.
- Produce: el barredor vivo en producción.

⚠️ **Esta tarea toca producción. Exige GO explícito del dueño antes del paso 3 y otro antes del paso 5.** Un GO no se extiende al siguiente despliegue.

- [ ] **Paso 1: Añadir la línea al CHANGELOG**

En `docs/CHANGELOG.md`, bajo la sección de la versión en curso:

```markdown
- Barredor de guías: reintenta cada 15 min los pedidos en `processing` con envío
  Coordinadora que se quedaron sin guía, con 10 min de gracia y tope de 3
  intentos. Avisa por correo cuando un pedido agota los reintentos. Cubre los
  tres modos de fallo vistos: timeout del WS, error del WS y descarte silencioso.
```

- [ ] **Paso 2: Commit y PR**

```bash
git add docs/CHANGELOG.md
git commit -m "docs: barredor de guias en el changelog"
git push -u origin HEAD
gh pr create --title "Barredor de guias sin generar" --body "Cierra el agujero por el que un pedido pagado se queda sin guia en silencio (casos #34378, #34423, #34487). Endpoint nuevo + workflow n8n cada 15 min. 261 tests en verde."
```

- [ ] **Paso 3: PEDIR GO. Desplegar el plugin a producción y a dev**

Backup primero, y **el mismo archivo a las dos copias** (dev tiene trabajo propio del carrito; solo se toca `class-ccmck-guias.php`):

```bash
ssh -o ConnectTimeout=25 ccm-web 'B=~/backups-ccmck; R=domains/ccmtiendadelsonido.com/public_html; cp -p $R/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-guias.php $B/guias_PROD_PRE_BARREDOR.php; cp -p $R/dev/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-guias.php $B/guias_DEV_PRE_BARREDOR.php; ls -la $B/ | grep BARREDOR'
```

```bash
scp -o ConnectTimeout=25 includes/class-ccmck-guias.php ccm-web:domains/ccmtiendadelsonido.com/public_html/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-guias.php && scp -o ConnectTimeout=25 includes/class-ccmck-guias.php ccm-web:domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout/includes/class-ccmck-guias.php
```

- [ ] **Paso 4: Verificar el despliegue del plugin**

```bash
ssh -o ConnectTimeout=25 ccm-web 'R=domains/ccmtiendadelsonido.com/public_html; for P in "$R/wp-content/mu-plugins/ccm-checkout" "$R/dev/wp-content/mu-plugins/ccm-checkout"; do echo "-- $P"; php -l $P/includes/class-ccmck-guias.php; grep -c "rest_sweep\|SWEEP_MAX_INTENTOS" $P/includes/class-ccmck-guias.php; done'
```

Esperado: `No syntax errors detected` en las dos y un conteo de marcadores ≥ 4 en cada una.

Y que la tienda sigue en pie:

```bash
curl -s -o /dev/null -w "portada HTTP %{http_code}\n" -L --max-time 30 https://ccmtiendadelsonido.com/
```

Esperado: `HTTP 200`.

- [ ] **Paso 5: PEDIR GO. Importar Y PUBLICAR el workflow — un solo paso**

`n8n import:workflow` **desactiva el workflow y no publica nada**. Si te quedas a medias, queda con `activeVersionId: null`, que es peor que no haber tocado nada (CLAUDE.md §1).

```bash
ssh -o ConnectTimeout=25 root@2.24.202.75 'docker exec n8n-n8n-1 sh -c "mkdir -p /tmp/imp"' && scp -o ConnectTimeout=25 docs/n8n/cwGuiaSweep01.import-ready.json root@2.24.202.75:/root/cwGuiaSweep01.json && ssh -o ConnectTimeout=25 root@2.24.202.75 'docker cp /root/cwGuiaSweep01.json n8n-n8n-1:/tmp/imp/ && docker exec n8n-n8n-1 n8n import:workflow --input=/tmp/imp/cwGuiaSweep01.json'
```

Inmediatamente después, publicar con la herramienta MCP `publish_workflow` sobre `cwGuiaSweep01`. **No entregues este comando al dueño sin el publish incluido.**

- [ ] **Paso 6: Verificar que quedó publicado y activo**

```bash
ssh -o ConnectTimeout=25 root@2.24.202.75 'sqlite3 -header -separator " | " /var/lib/docker/volumes/n8n_data/_data/database.sqlite "SELECT id, name, active, CASE WHEN activeVersionId IS NULL THEN \"SIN PUBLICAR\" ELSE \"publicado\" END FROM workflow_entity WHERE id=\"cwGuiaSweep01\";"'
```

Esperado: `cwGuiaSweep01 | CW Guía - Barredor... | 1 | publicado`. Si dice `SIN PUBLICAR`, **no has terminado**.

- [ ] **Paso 7: Verificar con tráfico real (a los ~20 min)**

```bash
ssh -o ConnectTimeout=25 root@2.24.202.75 'sqlite3 -separator " | " /var/lib/docker/volumes/n8n_data/_data/database.sqlite "SELECT id, datetime(startedAt,\"-5 hours\"), status FROM execution_entity WHERE workflowId=\"cwGuiaSweep01\" ORDER BY id DESC LIMIT 3;"'
```

Esperado: al menos una ejecución `success`.

**El silencio no es éxito** (CLAUDE.md §9). Comprobar que el barredor *mira* pedidos, no solo que no falla: revisar el nodo `Resumen` de la última ejecución y confirmar que `revisados` refleja los pedidos reales de las últimas 48 h. Si `revisados` es 0 cuando sabes que hay pedidos en `processing`, la consulta a WC está mal y el barredor es decorativo.

- [ ] **Paso 8: Prueba de fuego controlada**

Sobre un pedido real ya despachado, comprobar que el barredor **no** lo toca (idempotencia):

```bash
ssh -o ConnectTimeout=25 ccm-web 'cd domains/ccmtiendadelsonido.com/public_html; SEC=$(wp eval "echo CCMCK_Settings::get(\"guias_api_secret\",\"\");" 2>/dev/null | tail -1); curl -s -X POST "https://ccmtiendadelsonido.com/wp-json/ccmck/v1/barrer-guia" -H "Content-Type: application/json" -H "x-ccmck-secret: $SEC" -d "{\"order_id\":34487}"'
```

Esperado: `{"ok":false,"skipped":true,"reason":"el pedido ya tiene guía","intentos":0}`. **No debe crear una segunda guía.**

- [ ] **Paso 9: Verificar que el correo de alerta REALMENTE llega (antes de dar por bueno el despliegue)**

I4: nadie ha comprobado nunca que el correo de "se rindió" salga de n8n y
llegue a la bandeja. **Requiere que la credencial SMTP del nodo "Email vigía
guías" esté configurada en n8n** — si no lo está, este paso lo revela ANTES
de depender de ella en producción, no después de que un pedido real se
quede pagado y sin guía en silencio.

Las URLs de "WC processing recientes" y "Barrer guía" están fijas a
`ccmtiendadelsonido.com` (producción): un pedido de `dev` nunca entra por la
consulta a WooCommerce del workflow, así que no basta con forzar el pedido y
esperar 15 minutos. La prueba se hace en dos mitades: el endpoint PHP se
prueba contra dev, sin tocar producción (9.1-9.2), y el envío del correo se
prueba ejecutando manualmente solo la cola del workflow en el editor de n8n
con los datos que ese pedido real produciría (9.3).

9.1. En dev, listar pedidos `processing` y elegir uno con envío Coordinadora
(comprobar en wp-admin → Pedidos si no se sabe de memoria cuál `<ID>` lo es):

```bash
ssh -o ConnectTimeout=25 ccm-web 'cd domains/ccmtiendadelsonido.com/public_html/dev; wp post list --post_type=shop_order --post_status=wc-processing --posts_per_page=20 --format=table --fields=ID,post_date'
```

Forzar en ese pedido (`<ID>`) el contador de intentos al máximo
(`CCMCK_Guias::SWEEP_MAX_INTENTOS` = 3) y limpiar guía/alerta de pruebas
anteriores, para que `sweep_decision()` lo reporte como "agotados los
reintentos" en el primer barrido (almacenamiento clásico: los pedidos son
`post` de WooCommerce, así que `wp post meta` opera directo sobre ellos):

```bash
ssh -o ConnectTimeout=25 ccm-web 'cd domains/ccmtiendadelsonido.com/public_html/dev; wp post meta update <ID> _ccmck_guia_intentos 3; wp post meta delete <ID> _coordinadora_tracking_number; wp post meta delete <ID> _ccmck_guia_alerta_enviada; wp post meta get <ID> _ccmck_guia_intentos'
```

Esperado: `3`.

9.2. Ejecutar la pasada a mano contra dev, con el secreto de dev:

```bash
ssh -o ConnectTimeout=25 ccm-web 'cd domains/ccmtiendadelsonido.com/public_html/dev; SEC=$(wp eval "echo CCMCK_Settings::get(\"guias_api_secret\",\"\");" 2>/dev/null | tail -1); curl -s -X POST "https://ccmtiendadelsonido.com/dev/wp-json/ccmck/v1/barrer-guia" -H "Content-Type: application/json" -H "x-ccmck-secret: $SEC" -d "{\"order_id\":<ID>}"'
```

Esperado: `{"ok":false,"skipped":true,"reason":"agotados los reintentos","intentos":3,"order_id":<ID>,"alerta_enviada":false}`.
`alerta_enviada:false` confirma que es la primera vez — la corrección I2
(`sweep_alerta_decision()`) acaba de marcar `_ccmck_guia_alerta_enviada` en
el pedido. Repetir el mismo curl una segunda vez debe devolver
`"alerta_enviada":true`: así se comprueba también que un segundo aviso NO se
repetiría — justo lo que I2 corrige.

9.3. En el editor de n8n, abrir `cwGuiaSweep01`. En el nodo "Resumen", usar
"Pin data" para fijar manualmente un item de salida que reproduzca lo que
esa pasada real habría resumido:

```json
[{ "revisados": 1, "generadas": 0, "guias": [], "rendidos": 1, "fallos": 0,
   "max_intentos": 3, "detalle_rendidos": [ { "intentos": 3, "order_id": "<ID>" } ],
   "detalle_fallos": [], "consulta_fallida": false, "motivo_consulta": "",
   "pagina_llena": false }]
```

y pulsar "Execute step" en "¿Hay que avisar?" y luego en "Email vigía guías"
(o "Execute workflow" desde "Resumen" hacia adelante, con el pin activo).
Esto evita depender de que dev sea visible para la consulta a WooCommerce
del workflow (fija a producción) y prueba justo la parte que I4 señala como
nunca comprobada: el envío real por SMTP, no solo que el nodo no marque
error.

Esperado: "Email vigía guías" termina en verde (sin error de SMTP) y llega
un correo a `ia@ccmtiendadelsonido.com` con asunto "Barredor de guías: 1
pedido(s) sin rótulo tras agotar reintentos". Si el nodo falla o el correo
no aparece en la bandeja en unos minutos (revisar spam), la credencial SMTP
de n8n no está configurada o está mal — corregirla ANTES de confiar en esta
alerta en producción, y ANTES de dar el Paso 5 por bueno.

9.4. Quitar el pin del nodo "Resumen" (para que la próxima ejecución real
del workflow vuelva a usar datos en vivo) y limpiar el pedido de prueba en
dev:

```bash
ssh -o ConnectTimeout=25 ccm-web 'cd domains/ccmtiendadelsonido.com/public_html/dev; wp post meta delete <ID> _ccmck_guia_intentos; wp post meta delete <ID> _ccmck_guia_alerta_enviada'
```

---

## Lo que este plan NO hace, y por qué

- **No arregla el candado de 60 s.** No está probado que sea la causa del #34487, y tocar `on_processing` arriesga el camino que hoy funciona para casi todos los pedidos. El barredor lo cubre por abajo sea cual sea la causa. Si más adelante se confirma la carrera, se ataca por separado.
- **No hace ruidoso el descarte silencioso de `should_generate`.** Escribir una nota en cada descarte llenaría de ruido pedidos normales (recogida local, otra transportadora). El barredor solo habla cuando algo de verdad quedó sin guía.
- **No reintenta indefinidamente.** Un pedido con producto sin peso o dirección incompleta no se arregla solo; a los 3 intentos calla y avisa a una persona.
