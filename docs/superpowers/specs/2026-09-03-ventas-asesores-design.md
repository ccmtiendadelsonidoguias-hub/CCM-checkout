# Ventas de asesores por el botón Venta — diseño

**Fecha:** 2026-09-03 · **Estado:** aprobado por el dueño, pendiente de plan.

## Problema

El botón **💰 Venta** de Chatwoot crea pedidos en WooCommerce con `_ccm_origen = chatwoot_venta`.
Hoy los **75 pedidos de los últimos 30 días** (98 desde el 17-jul) llevan vendedor **9 «Bot CCM IA»**,
centro de costo **10 «IA CCM»** y lista «Precio Publico»: son los valores preseleccionados del popup y
ningún asesor los cambia. Consecuencias:

- No existe ningún dato que distinga una venta de Heider o Farid de una del bot.
- Los informes de Alegra atribuyen al bot todo lo vendido por chat.
- WooCommerce → Informes tiene pestaña «Ventas del bot» y excluye esos pedidos de «Ventas», pero no
  hay dónde ver lo de los asesores.

## Decisiones del dueño (2026-09-03)

1. **Atribución:** al **agente que abre el popup**; el campo **Vendedor** manda si él lo cambia. El
   bot deja de ser el default y solo se atribuye al bot si el asesor lo marca explícitamente.
2. **Dónde se ve:** WooCommerce → Informes, **pestaña nueva «Ventas asesores»**, al estilo de
   «Ventas del bot». Excluidas del total de «Ventas», igual que las del bot.
3. **Alegra:** centro de costo **3 «Ventas Virtuales Personas CCM»** (código 02) para los asesores;
   vendedor = el asesor. «IA CCM» queda solo para ventas marcadas como del bot.

## Alcance

**Entra:** popup `cwVentaPage01`, API `cwVentaApi01`, `includes/class-ccmck-reports.php` y sus tests.
Incluye, a propósito, el guard que impide que un error de validación cree un pedido vacío (ver §2.4),
porque este diseño añade un error nuevo al mismo camino.

**No entra:** reatribuir los 98 pedidos históricos; panel en Chatwoot; autenticación de la API del
popup (hallazgo aparte); arreglo del resolutor del `scan`; averiguar quién añade `?conv=` a la URL
del popup (funciona; el diseño no depende de eso porque el agente se lee del `appContext`).

## Hechos verificados que sostienen el diseño

- Chatwoot 4.17 (`DashboardApp/Frame.vue`, confirmado en el bundle en ejecución) envía al iframe, al
  cargar y ante `chatwoot-dashboard-app:fetch-info`, un `postMessage` con
  `{event:'appContext', data:{conversation, contact, currentAgent:{id,name,email}}}`. El popup de
  📦 Pedidos ya lo consume; el de Venta lo ignora.
- Agentes de Chatwoot (cuenta 1): Camilo `gerencia@…` (id 1, admin), Farid `farid@…` (10),
  Heider `heider@…` (12).
- Vendedores en Alegra: 3 Heider Arrieta · 4 Farid Sanchez · 6 Camilo Caraballo Avendaño (el 5 es
  OTRO Camilo, no usar) · 9 Bot CCM IA.
- `factura copy` (`Set - datos del pedido` → `Agrupar factura por pedido`) y `cwFacturaFlete01`
  (`Parse + idempotencia`) ya leen `_ccm_alegra_seller_id`, `_ccm_alegra_cost_center_id` y
  `_ccm_alegra_price_list_id` del pedido. **Alegra no necesita cambios.**
- `CCMCK_Reports::filter_report_query` filtra `posts.ID NOT IN (subquery por meta)` sobre
  `woocommerce_reports_get_order_report_query`; `render_bot_report` reutiliza
  `WC_Report_Sales_By_Date` con un booleano `$scope_only_bot`. Hay 11 tests en `tests/ReportsTest.php`.
- Bug preexistente (3 casos en 60 días: #34114, #34158, #34449): cuando `Order payload` devuelve
  `{error}`, `WC crear pedido` recibe `order_body || {}` y **crea un pedido vacío** que `WC touch`
  pasa a processing. Además `Resultado` en la rama de error no lleva `conv`, así que la nota de
  error va a `/conversations/undefined/messages` y se pierde.

## 1. Popup — `cwVentaPage01` (nodo `HTML`)

1. Al cargar: `window.addEventListener('message', …)` que parsea `ev.data`, y si
   `event === 'appContext'` guarda `AGENTE = {email, name}` de `data.currentAgent`. Emite
   `parent.postMessage('chatwoot-dashboard-app:fetch-info','*')` para pedirlo (mismo patrón que
   📦 Pedidos).
2. `prefill` se lanza **después** de recibir `appContext` (o tras 1,5 s sin respuesta, con `agente`
   vacío). Manda `agente: AGENTE` en `prefill` y en `crear`.
3. `<select id="vendedor">` y `<select id="ccosto">` pierden el `selected` del bot. Se rellenan con
   `vendedor_id` / `ccosto_id` que devuelva `prefill`. Sin dato: primera opción «— Elegir —».
4. Casilla **«🤖 Venta cerrada por el bot»** (`id="es_bot"`) junto a Vendedor. Marcada: fija
   vendedor `9` y centro `10`, y deshabilita ambos selects. Desmarcada: restaura los valores del
   agente (los guardados al recibir `prefill`).
5. Validación en `onsubmit`: sin vendedor → mensaje «Elige el vendedor» y no envía.
6. `form` incluye `es_bot: bool` y los campos existentes (`vendedor_alegra_id`, `vendedor_nombre`,
   `centro_costo_id`, `centro_costo_nombre`) tal como hoy.

## 2. API — `cwVentaApi01`

### 2.1 Nodo nuevo `Agente → vendedor` (Code, runOnceForAllItems)

Único sitio del mapa. Entrada: `agente.email` (case-insensitive, trim). Salida:

| correo | vendedor_id | vendedor_nombre | ccosto_id | ccosto_nombre |
|---|---|---|---|---|
| `heider@ccmtiendadelsonido.com` | 3 | Heider Arrieta | 3 | Ventas Virtuales Personas CCM |
| `farid@ccmtiendadelsonido.com` | 4 | Farid Sanchez | 3 | Ventas Virtuales Personas CCM |
| `gerencia@ccmtiendadelsonido.com` | 6 | Camilo Caraballo Avendaño | 3 | Ventas Virtuales Personas CCM |
| otro / vacío | `null` | `''` | `null` | `''` |

Se coloca **al inicio**, entre `WH Venta` y `¿Prefill?`, de modo que corre en **todas** las
acciones y tanto `Prefill build` como `Order payload` lo leen con
`$('Agente → vendedor').first().json`. Un solo mapa, ninguna copia. El nodo pasa el item del
webhook intacto (`return [{ json: Object.assign({}, $json, { agente_resuelto: {...} }) }]`), así los
IF de acción siguen leyendo `$json.body.action` sin cambios.

### 2.2 `prefill`

`Prefill build` añade a la respuesta: `vendedor_id`, `vendedor_nombre`, `ccosto_id`,
`ccosto_nombre`, `agente_email`.

### 2.3 `crear` — `Order payload`

- Deja de asumir `'9'` / `'10'` / `'Bot CCM IA'` / `'IA CCM'`.
- `vendedor_id = f.es_bot ? 9 : (f.vendedor_alegra_id || agente.vendedor_id)`; análogo para
  centro de costo (`es_bot` → 10). Si sigue vacío → `return { error: 'sin_vendedor: elige el
  vendedor en el popup' }`.
- Metas nuevas en el pedido:
  - `_ccm_canal_venta` = `'bot'` si `vendedor_id === 9`, si no `'asesor'`.
  - `_ccm_agente_chatwoot` = correo del agente (puede ir vacío si Chatwoot no lo mandó).
- `_ccm_origen = chatwoot_venta` y el resto de metas **sin cambios**.

### 2.4 Guard anti-fantasma (incluido)

- Nodo IF **`¿Payload OK?`** entre `Order payload` y `WC crear pedido`: `{{ !$json.error }}`.
  Rama falsa → `Resultado`.
- `Resultado` en rama de error devuelve `{ ok:false, error, conv: $('Crear parse').first().json.conv }`
  para que `Nota en Chatwoot` llegue a la conversación correcta (nota privada `Venta: <error>`).
- `WC crear pedido` y `WC touch (dispara factura)` **no** cambian.

## 3. Plugin — `includes/class-ccmck-reports.php`

### 3.1 Constantes y scope

```php
const META_CANAL    = '_ccm_canal_venta';
const CANAL_ASESOR  = 'asesor';
const SCOPE_EXCLUDE_ALL = 'exclude_all'; // pestaña Ventas (default)
const SCOPE_ONLY_BOT    = 'only_bot';
const SCOPE_ONLY_ASESOR = 'only_asesor';
private static string $scope = self::SCOPE_EXCLUDE_ALL;
private static string $vendedor = ''; // _ccm_alegra_seller_id, solo con only_asesor
```

### 3.2 Subqueries (puras, devuelven SQL ya preparado)

- `chat_orders_subquery()` — `_ccm_origen = chatwoot_venta` (la de hoy, renombrada).
- `asesor_orders_subquery( string $vendedor = '' )` — `_ccm_canal_venta = asesor`, y si `$vendedor`
  no está vacío, además `_ccm_alegra_seller_id = $vendedor`.
- `bot_orders_subquery()` — chat **y no** asesor: `post_id IN (chat) AND post_id NOT IN (asesor)`.
  Así los pedidos históricos sin `_ccm_canal_venta` siguen contando como bot.

### 3.3 `filter_report_query`

| scope | WHERE añadido |
|---|---|
| `exclude_all` | `posts.ID NOT IN (chat)` |
| `only_bot` | `posts.ID IN (bot)` |
| `only_asesor` | `posts.ID IN (asesor[vendedor])` |

### 3.4 Totales para el aviso

`chat_totals( $desde, $hasta )` devuelve `['bot'=>['n','total'], 'asesor'=>['n','total']]` con las
mismas tablas y estados que el informe. El aviso en «Ventas» pasa a:
*«Excluidas de este informe — Ventas del bot: n · $ · [verlas] — Ventas asesores: n · $ · [verlas]»*.

### 3.5 Pestañas

- `ccmck_bot` («Ventas del bot»): igual que hoy pero con `SCOPE_ONLY_BOT`.
- `ccmck_asesores` («Ventas asesores»), callback `render_asesores_report()`:
  1. Lee `vendedor` de `$_GET` (sanitizado; solo dígitos).
  2. Pinta arriba un formulario GET con `<select name="vendedor">` — «Todos» + un `<option>` por
     cada `_ccm_alegra_seller_id/_nombre` visto en pedidos `canal=asesor` del rango
     (`vendedores_en_rango( $desde, $hasta )`) — conservando `range/start_date/end_date`.
  3. Tabla resumen `resumen_por_vendedor( $desde, $hasta )`: vendedor · pedidos · total, ordenada
     por total desc, con fila de suma.
  4. `WC_Report_Sales_By_Date::output_report()` con `SCOPE_ONLY_ASESOR` + vendedor. El CSV que
     genera WooCommerce sale con el mismo filtro.

### 3.6 Tests (`tests/ReportsTest.php`, funciones puras)

- Subqueries: contienen las metas correctas; `asesor` con y sin vendedor; `bot` excluye asesores.
- `filter_report_query` en los tres scopes; scope vuelve a `exclude_all` tras renderizar.
- `register_tab` añade `ccmck_asesores` sin tocar las demás; callbacks apuntan bien.
- `vendedor` de GET: solo dígitos; vacío → todos.
- Resumen: agrega por vendedor, ordena por total, suma correcta (con `$wpdb` stub).

## 4. Flujo de datos

```
Chatwoot ─appContext{currentAgent}─▸ popup ─prefill{agente}─▸ API: Agente→vendedor ─▸ popup (selects precargados)
asesor revisa / marca «es bot» ─crear{form, agente}─▸ API: Order payload
   ├─ error  ─▸ ¿Payload OK? NO ─▸ Resultado{ok:false, conv} ─▸ Nota privada en el chat
   └─ ok     ─▸ WC crear pedido (metas: _ccm_origen, _ccm_canal_venta, _ccm_agente_chatwoot,
                _ccm_alegra_seller_id/_cost_center_id…) ─▸ WC touch ─▸ factura copy / flete (sin cambios)
WooCommerce → Informes: Ventas (excluye chat) · Ventas del bot (canal≠asesor) · Ventas asesores (canal=asesor, filtro vendedor)
```

## 5. Errores y casos borde

- Chatwoot no manda `appContext` (popup abierto fuera de Chatwoot): `agente` vacío → sin default;
  el asesor elige a mano; `_ccm_agente_chatwoot` queda vacío.
- Agente nuevo sin fila en el mapa: igual que arriba, más una nota privada en el chat
  «Agente X sin vendedor asignado — añadir al mapa» al crear, para que se note.
- Asesor marca «es bot» y además cambia el vendedor: gana la casilla (vendedor 9).
- Pedido creado antes de este cambio: sin `_ccm_canal_venta` → cuenta como bot en informes.
- Fallo del PUT a processing: fuera de alcance (hallazgo 🟠4), se documenta.

## 6. Pruebas y despliegue

1. **Plugin** — PHPUnit verde (suite completa). Desplegar a **prod y dev** (patrón sha256 antes de
   todo `scp`, ver [[ccmck_release_arreglo_fuera_de_main]]). Tolera pedidos sin la meta nueva.
2. **API** — arnés viejo-vs-nuevo sobre `Agente → vendedor`, `Order payload` y `Resultado`; el
   caso «error ya no llega a `WC crear pedido`» debe **fallar contra el código viejo**. Backup,
   import + publish + `deactivate/activate` por API (el import pierde el registro del webhook).
3. **Popup** — servido en local con `appContext` inyectado: precarga, casilla, bloqueo, validación.
   Luego mismo ciclo de despliegue.
4. **Verificación real** — una venta de prueba desde Chatwoot por cada asesor; comprobar metas en el
   pedido, vendedor/centro en la factura Alegra, y que aparece en «Ventas asesores» y no en «Ventas».

Un **GO por despliegue**, sin excepciones (CLAUDE.md §2).

## 7. Riesgos

- Si Chatwoot cambia el formato de `appContext`, el popup degrada a «sin default» (no se rompe).
- El mapa agente→vendedor vive en un nodo de n8n: añadir un asesor exige tocar el workflow.
  Aceptado por ahora (3 asesores); si crece, moverlo a una data table.
- `WC_Report_Sales_By_Date` es el informe clásico (no Analytics); si WooCommerce lo retira, ambas
  pestañas caen juntas — mismo riesgo que ya existe con «Ventas del bot».
