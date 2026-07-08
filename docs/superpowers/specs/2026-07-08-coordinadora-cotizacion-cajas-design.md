# Cotización Coordinadora con armado de cajas por tipo de producto — Diseño

## Contexto y objetivo

Hoy el flete de **Coordinadora** en el checkout lo cotiza el plugin de terceros
`wp-content/plugins/coordinadora`: registra un `WC_Shipping_Method` (id `coordinadora`,
rate id auto `coordinadora:N`) cuyo `calculate_shipping()` llama a un backend externo
(`https://wc-backend-dot-cm-integraciones.uk.r.appspot.com/api/coordinadoraWs/CalculateShipping`)
armando el payload **producto por producto** (un bulto por línea del carrito). El
mu-plugin `CCMCK_Shipping` **no cotiza**: solo re-pinta como cards las tarifas que WC ya
calculó (`includes/class-ccmck-shipping.php`).

**El problema:** en carritos con varios productos el flete sale **inválido** (muy caro).
Causa confirmada probando la API real: Coordinadora cobra **peso volumétrico** (factor
2500) y aplica un **mínimo de 30 kg liquidados por bulto** en envíos tipo mercancía. Al
mandar cada producto/accesorio como bulto suelto, cada uno suma ≥30 kg facturables y el
flete se dispara (ej. Barranquilla→Bogotá: un accesorio de 0.5 kg como bulto aparte
cuesta ~$40.800 extra).

**Objetivo:** cotizar el flete **directo** contra `Cotizador.cotizar` con las
credenciales propias de la tienda, agrupando los productos del carrito en **cajas según
reglas por tipo de producto** (como se despachan de verdad), de modo que el flete refleje
los bultos reales. Mostrar además los **días de entrega** en la card.

## Datos verificados de la API (base de todo el diseño)

- **Endpoint:** `POST https://ws.coordinadora.com/ags/1.5/server.php` — JSON-RPC 2.0.
- **Método:** `Cotizador.cotizar` (con punto). `params` = objeto plano nombrado.
- **Credenciales** (tienda CCM, verificadas en producción, origen Barranquilla `08001000`):
  `apikey`, `clave`, `nit` (901677789). Van dentro de `params`.
- **Request** (campos relevantes): `origen`/`destino` = código DANE 8 dígitos;
  `valoracion` = valor declarado COP; `producto: 0` (auto); `nivel_servicio: [{item:1}]`
  (formato verificado del body real; enviar `[0]` también funciona pero se usa el probado);
  `detalle[]` = un objeto por **tipo de bulto** con `{ubl:0, alto, ancho, largo, peso,
  unidades}` (dimensiones en cm, peso en kg, `unidades` = nº de bultos idénticos).
- **Respuesta** (`result`, con `error:null`): `flete_total` (int, = fijo+variable+otros),
  `flete_fijo`, `flete_variable` (≈1% de la valoración, con pisos), `peso_liquidado`,
  `volumen` (peso volumétrico en kg = dims_cm³/2500), `dias_entrega` (**string**),
  `peso_real`, `producto`, `ubl`.
- **Errores:** HTTP siempre **200**; el error viene en `error` (no en el status). Formato
  `{"error":{"code":0,"message":"Exception: Error, ..."}}`. `error.code` casi siempre `0`
  → **no** sirve para discriminar; la regla es `error !== null` → falló. Un método/params
  inválidos pueden devolver **HTML de Fatal error** (no JSON) → hay que tolerar no-JSON.
- **Peso volumétrico:** `volumen = alto*ancho*largo/2500`. Se factura `max(peso_real,
  volumétrico)` por bulto. Consolidar en una sola caja (un `detalle` con dims sumadas)
  evita el piso de 30 kg por bulto suelto.

Requests/respuestas de las pruebas guardados en
`scratchpad/coordinadora/` (t*/r* .json) durante la investigación.

## Decisiones (aprobadas con el usuario)

1. **Ámbito:** solo el **checkout web**. Fuera de alcance: guías, tracking, bot n8n.
2. **Cotización directa** con credenciales propias (no el backend de terceros): es la
   única forma de controlar el armado de cajas.
3. **Reglas de empaque por tipo de producto** (precedencia de arriba hacia abajo):

   | Tipo de producto | Regla | Ejemplos |
   |---|---|---|
   | **Parlantes** (categoría configurada) | **2 por caja** | 4→2 cajas · 5→3 cajas · 6→3 cajas |
   | Otros productos **≥ 5 kg** | 1 por caja (separado) | 3→3 cajas |
   | Productos **< 5 kg** | Consolidados en una caja | 6 accesorios→1 caja |

   - Nº de cajas de un grupo = `ceil(cantidad / N)`.
   - La regla de "parlantes" se define por **categoría de WooCommerce** (tabla
     `categoría → unidades por caja`). Producto que no cae en ninguna regla especial →
     se resuelve solo por peso. Un producto nuevo cotiza bien sin configurar nada.
4. **Medidas de la caja consolidada:** **apilar dimensiones** de los productos que van
   dentro (no caja fija). Convención (ver Componentes): `alto` = suma, `ancho`/`largo` =
   máximo, `peso` = suma. Para bultos homogéneos el volumen escala lineal con la cantidad.
5. **Reemplazo con respaldo:** si la cotización propia funciona, **reemplaza** la tarifa
   `coordinadora:N` por la nuestra (`ccmck_coordinadora`). Si falla (API caída, timeout,
   producto sin peso/dimensiones, credenciales vacías, toggle apagado) → **no se toca** la
   tarifa del plugin viejo (queda como fallback). El checkout nunca se queda sin envío.
6. **Días de entrega** en la card: "Coordinadora — $XX.XXX · Llega en N días hábiles".
7. **Toggle apagado = comportamiento actual intacto.**

## Enfoque técnico

**Un módulo nuevo `CCMCK_Coordinadora` engancha `woocommerce_package_rates`** (mismo
patrón que `CCMCK_Pickup::inject`, `class-ccmck-pickup.php:43-50,89`). El filtro corre
**después** de que WC ya calculó las tarifas del paquete, así que cuando entra ya tiene
`coordinadora:N` en `$rates` (la tarifa del plugin viejo, disponible como fallback
"gratis" — ya se calculó en el mismo ciclo).

Flujo del filtro (prioridad 20, para correr tras la inyección de pickup):

1. Si el toggle está off, faltan credenciales, o el carrito no necesita envío → devolver
   `$rates` sin tocar.
2. Construir las **cajas** desde `$package['contents']` con el motor de empaque (puro).
3. Si **algún** producto del carrito no tiene peso o dimensiones → **abortar** (log +
   devolver `$rates` sin tocar; queda el fallback). Misma disciplina que hoy, pero logueada.
4. Extraer el **DANE destino** de `$package['destination']['city']`.
5. Llamar a `Cotizador.cotizar` (timeout 5 s). Parsear.
6. **Éxito:** `unset` de toda rate cuyo id empiece por `coordinadora`; añadir
   `WC_Shipping_Rate('ccmck_coordinadora', 'Coordinadora', flete_total, ...)` con
   `dias_entrega` en meta.
7. **Fallo:** devolver `$rates` intacto (log del mensaje crudo).

WooCommerce **cachea** el resultado de `woocommerce_package_rates` por hash del paquete en
sesión: la llamada externa solo se repite cuando cambia el carrito o el destino. La label
`Coordinadora` coincide con `CCMCK_Shipping::placeholder_labels()`
(`class-ccmck-shipping.php:100`) → la UI no requiere cambios de placeholder. Los días de
entrega **no** van en la label (rompería el de-dupe de placeholders); viajan como meta de
la rate y se pintan aparte.

**Alternativa considerada y descartada:** registrar un `WC_Shipping_Method` propio (el
patrón "correcto" de WC, como el plugin viejo). Se descarta para esta iteración: el patrón
`woocommerce_package_rates` es el que ya usa el mu-plugin (`CCMCK_Pickup`), no requiere
zonas de envío, permite reemplazar la tarifa vieja in-situ y tenerla como fallback en la
misma pasada, y mantiene toda la lógica en métodos puros testeables. Migrar a
`WC_Shipping_Method` queda como posible refactor futuro.

## Componentes

### 1. `CCMCK_Coordinadora` — motor de empaque (métodos PUROS, testeables sin WC)

Archivo nuevo `includes/class-ccmck-coordinadora.php`.

**Modelo de una línea del carrito** (normalizado desde `$package['contents']`):
`{ qty:int, weight:float, largo:float, ancho:float, alto:float, cat_ids:int[], line_total:float }`.

**`classify_item( array $item, float $threshold, array $rules ): array`**
Devuelve `{ kind:'rule'|'heavy'|'small', units_per_box:int }`:
- `rule`: si alguna `cat_id` del ítem está en `$rules` (tabla `cat_id => N`) → `units_per_box = N`.
- `heavy`: si no hay regla y `weight >= $threshold` → `units_per_box = 1`.
- `small`: si no hay regla y `weight < $threshold` → se consolida (ver `pack`).

**`pack( array $items, float $threshold, array $rules ): array`** → lista de **cajas**.
Cada caja: `{ largo, ancho, alto, peso }`.
- **rule / heavy:** por cada línea, `ceil(qty / N)` cajas. Se reparte en cajas de hasta N
  **unidades del mismo producto** (bultos homogéneos → volumen exacto). Cada caja se arma
  con `stack_box()` sobre `min(N, restantes)` unidades de ese producto.
- **small:** **todas** las unidades `< threshold` (de todos los productos, sin regla) se
  apilan en **una sola** caja consolidada con `stack_box()`.
- Devuelve todas las cajas de todos los grupos.

**`stack_box( array $units ): array`** — apila dimensiones. `$units` = lista de
`{largo,ancho,alto,peso}` (una entrada por unidad física):
```
largo = max(u.largo)   ancho = max(u.ancho)
alto  = Σ u.alto        peso  = Σ u.peso
```
Para unidades idénticas → `volumen = largo*ancho*(alto*n) = n * volumen_unitario` (escala
lineal, que es el cobro justo). Para mezcla (caja small) → bounding box de una pila
vertical (aproximación conservadora, nunca subcotiza groseramente).

**`build_detalle( array $boxes ): array`** — agrupa cajas **idénticas** (mismas dims +
peso, redondeadas) en un `detalle[]`: cada entrada
`{ ubl:0, alto, ancho, largo, peso, unidades:n }` con `unidades` = nº de cajas idénticas.
Ej.: 4 parlantes (N=2) → 2 cajas iguales → `[{...,unidades:2}]`. 5 parlantes → cajas
`2,2,1` → `[{caja_llena, unidades:2}, {caja_media, unidades:1}]`.

**`dane_from_city( string $city ): string`** — extrae el DANE de 8 dígitos. Acepta
`'05001000'` y `'MEDELLIN (ANT) (05001000)'`:
`preg_match('/(\d{8})\D*$/', $city, $m)` → `$m[1]` (o `''` si no hay match).

### 2. `CCMCK_Coordinadora` — cliente HTTP y parseo (parte acoplada a WP mínima)

**`build_request( array $args ): array`** — PURO. Arma el body JSON-RPC completo desde
`{apikey, clave, nit, origen, destino, valoracion, detalle}`:
```php
[ 'jsonrpc'=>'2.0', 'id'=>0, 'method'=>'Cotizador.cotizar', 'params'=>[
    'nit'=>..., 'div'=>'01', 'cuenta'=>2, 'producto'=>0,
    'origen'=>..., 'destino'=>..., 'valoracion'=>(int)..., 'nivel_servicio'=>[['item'=>1]],
    'detalle'=>$detalle, 'apikey'=>..., 'clave'=>... ] ]
```

**`parse_response( string $body, $http_code ): array`** — PURO. Devuelve
`{ ok:bool, flete_total:int, dias:int, error:string }`:
- No decodifica como JSON (HTML de Fatal error) → `ok:false`.
- `error !== null` → `ok:false`, `error` = mensaje crudo.
- `result.flete_total` presente → `ok:true`, `flete_total`, `dias = (int) result.dias_entrega`.

**`quote( array $args ): array`** — único método NO puro: `wp_remote_post` (timeout 5 s,
`Content-Type: application/json`, `body = wp_json_encode(build_request(...))`) → si
`is_wp_error` devuelve `ok:false`; si no, `parse_response( wp_remote_retrieve_body,
wp_remote_retrieve_response_code )`. Loguea el error crudo en `WC_Logger` canal
`ccmck-coordinadora` (mismo estilo que el plugin viejo).

### 3. `CCMCK_Coordinadora::rates()` — filtro `woocommerce_package_rates`

Orquesta 1–7 del *Enfoque técnico*. Lee settings con `CCMCK_Settings::get()`.
- `valoracion` = subtotal del carrito (`Σ line_total`), casteado a int.
- Añade la rate: `$rate = new WC_Shipping_Rate('ccmck_coordinadora', 'Coordinadora',
  (float)$flete_total, array(), 'ccmck_coordinadora')`; si `dias>0`,
  `$rate->add_meta_data('dias_entrega', $dias)`; `unset` de `coordinadora*` en `$rates`;
  `$rates['ccmck_coordinadora'] = $rate`.

**`init()`**: `add_filter('woocommerce_package_rates', array(__CLASS__,'rates'), 20, 2);`
Registrar en `ccm-checkout.php`: `require_once` (bloque líneas 15-31) + `CCMCK_Coordinadora::init();`
en `ccmck_boot()` (líneas 44-59).

### 4. Días de entrega en la card — `CCMCK_Shipping`

`build_methods()` (`class-ccmck-shipping.php:25-52`): al mapear cada rate, capturar la meta
`dias_entrega` → añadir `'eta' => (int) $dias` al array del método (0 si no hay).
`render_cards()` (líneas 59-88): tras `<span class="ccmck-ship-cost">`, si `eta > 0`:
```php
'<span class="ccmck-ship-eta">' . esc_html( sprintf(
    _n( 'Llega en %d día hábil', 'Llega en %d días hábiles', $eta, 'ccm-checkout' ), $eta
) ) . '</span>'
```
Ambos siguen siendo puros (reciben el array; la lectura de meta se hace en `build_methods`
desde el objeto rate vía `get_meta_data()`, con guardas `method_exists`).

### 5. Ajustes — `CCMCK_Settings` + `includes/views/settings-page.php`

Nuevas keys en `defaults()` (`class-ccmck-settings.php:13-52`) y whitelist en `sanitize()`
(líneas 54-103):

| Key | Default | Sanitize |
|---|---|---|
| `coordinadora_enabled` | `false` | `! empty()` |
| `coordinadora_apikey` | `''` | `sanitize_text_field` |
| `coordinadora_clave` | `''` | `sanitize_text_field` |
| `coordinadora_nit` | `'901677789'` | `preg_replace('/[^0-9]/','',…)` |
| `coordinadora_origin` | `'08001000'` | `preg_replace('/[^0-9]/','',…)` (8 díg.) |
| `coordinadora_weight_threshold` | `5.0` | `(float)`, acotado `>= 0` |
| `coordinadora_box_rules` | `array()` | filas `{cat:absint(>0), n:max(1,absint)}`, sin cat duplicada |

UI: **pestaña nueva "Coordinadora"** (anchor `.nav-tab` + panel `.ccmck-tab-panel`
`data-tab="coordinadora"`, toggling ya resuelto en `assets/ccmck-admin.js`). Campos:
toggle, apikey, clave (`type="password"`), NIT, origen (con nota "DANE 8 díg., Barranquilla
= 08001000"), umbral de peso, y un **repeater** `categoría (select de `product_cat`) →
unidades por caja` reutilizando el patrón de repeater existente (`shipping_cards`/`faq_items`
en la vista + `ccmck-admin.js`). Se documenta: fila típica **Parlantes → 2**.

Nota: las credenciales del plugin viejo viven en `woocommerce_coordinadora_settings`
(ajustes del método WC). Aquí se usa una **fuente propia** en `ccmck_settings`; el origen
único evita ambigüedad.

### 6. Estilo — `assets/ccmck-checkout.css`

```css
.ccmck-ship-eta {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    color: #6b7280;
}
```

## Testing

**`tests/CoordinadoraTest.php`** (nuevo — motor puro):
- `classify_item`: cat en reglas → `rule` con su N; sin regla y `weight>=5` → `heavy`(N=1);
  `weight<5` → `small`.
- `pack` / `build_detalle`:
  - 4 parlantes N=2 → 2 cajas idénticas → detalle 1 entrada `unidades:2`.
  - 5 parlantes N=2 → cajas `2,2,1` → 2 entradas (`unidades:2` y `unidades:1`).
  - 6 parlantes N=2 → 3 cajas → `unidades:3`.
  - 3 productos ≥5 kg sin regla → 3 cajas separadas.
  - 6 accesorios <5 kg → 1 caja consolidada.
  - Carrito mixto (2 parlantes + 1 pesado + 3 accesorios) → 3 grupos de cajas correctos.
- `stack_box`: `alto` suma, `ancho`/`largo` máximo, `peso` suma; volumen lineal para
  idénticos.
- `dane_from_city`: `'05001000'` y `'MEDELLIN (ANT) (05001000)'` → `'05001000'`; basura → `''`.
- `build_request`: estructura JSON-RPC correcta (`method`, `params.detalle`, casts).
- `parse_response`:
  - éxito (`result.flete_total`, `dias_entrega:"2"`) → `ok:true, flete_total, dias:2`.
  - `error !== null` → `ok:false`, mensaje.
  - body no-JSON (HTML de Fatal error) → `ok:false`.

**`tests/ShippingTest.php`**: `build_methods` captura `eta` desde la meta; `render_cards`
pinta `.ccmck-ship-eta` cuando `eta>0` y **no** cuando `eta=0`.

**`tests/SettingsTest.php`**: `defaults()` trae las 7 keys nuevas; `sanitize` limpia NIT y
origen a solo dígitos, acota el umbral, y normaliza `coordinadora_box_rules` (cat inválida
o N<1 descartadas; sin cat duplicada); ausencia de keys no rompe.

## Archivos afectados

| Archivo | Cambio | Deploy |
|---|---|---|
| `includes/class-ccmck-coordinadora.php` | **nuevo**: motor de cajas + cliente + filtro | PHP → OPcache |
| `ccm-checkout.php` | `require_once` + `::init()` | PHP → OPcache |
| `includes/class-ccmck-settings.php` | defaults + sanitize (7 keys) | PHP → OPcache |
| `includes/views/settings-page.php` | pestaña "Coordinadora" + repeater de reglas | PHP → OPcache |
| `includes/class-ccmck-shipping.php` | `eta` en `build_methods`/`render_cards` | PHP → OPcache |
| `assets/ccmck-checkout.css` | estilo `.ccmck-ship-eta` | auto cache-bust |
| `tests/CoordinadoraTest.php` (nuevo), `tests/ShippingTest.php`, `tests/SettingsTest.php` | tests | — |
| `docs/CHANGELOG.md` | entrada en *[Sin publicar] → Añadido* | — |

## Despliegue

- PHP → subir por File Manager + **purgar OPcache**.
- CSS → auto cache-bust por `filemtime` (`CCMCK_Assets`), sin OPcache.
- **Activación en dos pasos** para no romper el checkout en vivo: (1) subir el código con
  `coordinadora_enabled = false` (todo sigue con el plugin viejo); (2) cargar credenciales +
  reglas y activar el toggle; verificar en el checkout con un carrito mixto real.
- Ver [[deploy-dev-server]].

## Fuera de alcance (YAGNI)

- Generación de guías (`Guias.generarGuia`, endpoint hermano), tracking y cotización en el
  bot n8n.
- Migrar a un `WC_Shipping_Method` propio (posible refactor futuro).
- Envíos internacionales (`Cotizador.cotizarInter`) y `nivel_servicio` express/programado
  (la cuenta solo devolvió servicio estándar en las pruebas).
- Cajas por medida fija o algoritmo de bin-packing 3D: se usa apilado por suma de
  dimensiones, homogéneo por producto.
- Caja de reparto para productos sin peso/dimensiones: si falta el dato se hace fallback al
  plugin viejo + log (no se inventa una medida).
