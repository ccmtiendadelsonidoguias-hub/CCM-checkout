# Generación automática de guías de Coordinadora — Diseño

## Contexto y objetivo

El checkout ya cotiza el flete de Coordinadora con el módulo `CCMCK_Coordinadora`
(cajas por tipo de producto, desplegado y verificado en producción). Las guías, en
cambio, se crean **a mano** en el panel de Coordinadora. Coordinadora entregó
credenciales del web service de guías (sandbox y producción, mismas credenciales) y
el flujo se validó end-to-end en sandbox (guía de prueba `33042000009` + rótulo PDF).

**Objetivo:** al confirmarse el pago de un pedido web (estado **Procesando**), generar
la guía automáticamente vía `Guias.generarGuia`, guardar número + rastreo en el
pedido, permitir descargar el rótulo desde el admin, y avisar al cliente por
**WhatsApp** (webhook a n8n → sender `cwSendWa01`).

## API del servicio de guías (verificado en vivo)

- **Endpoints** (JSON-RPC 2.0, mismo transporte que el cotizador):
  - Producción: `https://guias.coordinadora.com/ws/guias/1.6/server.php`
  - Sandbox: `https://sandbox.coordinadora.com/agw/ws/guias/1.6/server.php`
- **Auth** (distinta al cotizador): `usuario` (formato `login.div`, ej. `ccmtienda.ws`)
  + `clave` = **SHA-256 hex** de la contraseña. Verificado: clave plana → "Usuario o
  clave invalido"; SHA-256 → pasa.
- **`Guias.generarGuia`**: params de negocio + `detalle[]` de bultos (mismo formato
  que el cotizador: `{ubl, alto, ancho, largo, peso, unidades, referencia,
  nombre_empaque}`). **`ubl: 0` (automático) funciona** — no se requiere tabla UBL.
- **Respuesta**: `{id_remision, codigo_remision (nº guía), pdf_guia (vacío en la
  práctica), url_terceros (URL de rastreo), referencia (echo)}`.
- **Rótulo PDF**: NO llega en `generarGuia`; se obtiene con `Guias.reimprimirGuia`
  `{codigo_remision, formato_impresion: "1"}` → `{codigo_remision, pdf}` (base64).
  Verificado: sin `formato_impresion` da "Undefined index".
- **Errores**: HTTP siempre 200; `error.code:"-1"` + `message` legible. `anularGuia`
  existe (`{codigo_remision, usuario, clave}` → bool) — fuera de alcance v1.
- **Credenciales**: usuario `ccmtienda.ws` · `id_cliente` **49444** (Div.01 Acuerdo
  Semanal = la `cuenta 2` del cotizador) · 49445 es Flete Contra Entrega (no se usa:
  todo se paga antes).

## Observaciones OBLIGATORIAS de Coordinadora (requisitos del go-live)

1. **`fecha` vacía** → la guía se genera con la fecha del día.
2. **`nit_remitente` VACÍO** (señalado explícitamente sobre nuestra prueba).
3. **`nombre_remitente`** = razón social o nombre comercial.
4. **`ciudad_remitente`** = DANE real de donde se recoge el despacho (`08001000`).
5. **Volúmenes correctos** → se cumplen usando el mismo `pack()` del cotizador.
6. **Avisar el día del go-live** — Geraldine Gineth Ruiz revisará la liquidación de
   los primeros despachos.

## Decisiones (aprobadas con el usuario)

- **Disparador:** automático al pasar a **Procesando** (pago aprobado). Sin botón de
  generar manual; re-poner el pedido en Procesando reintenta si no hay guía.
- **Sin contraentrega:** todos los métodos de pago de la web son prepagados →
  `recaudos: []` siempre, `id_cliente` 49444 siempre.
- **Rótulo:** botón **"Descargar rótulo"** en el pedido del admin (PDF al vuelo, no
  se almacena).
- **Aviso al cliente:** por **WhatsApp vía n8n** (webhook desde el plugin; el
  workflow n8n envía con el patrón `cwSendWa01`, sesión → fallback plantilla WABA).
- **Ambientes:** selector sandbox/producción en ajustes; se prueba en sandbox
  primero. Toggle general de guías **apagado por defecto**.

## Componentes

### 1. `CCMCK_Guias` — módulo nuevo (`includes/class-ccmck-guias.php`)

Métodos **PUROS** (testeables sin WP) + una capa fina acoplada.

**`items_from_order( array $lines ): array`** — PURO. Recibe líneas normalizadas
`{qty, weight, largo, ancho, alto, cat_ids}` (la extracción desde
`$order->get_items()` la hace un wrapper acoplado que replica
`CCMCK_Coordinadora::items_from_package`, leyendo producto de cada línea). Devuelve
la misma forma que consume `CCMCK_Coordinadora::pack()`.

**`build_guia_params( array $args ): array`** — PURO. Arma los params completos de
`Guias.generarGuia` desde:
`{usuario, clave_sha256, id_cliente, remitente:{nombre, direccion, telefono, ciudad},
destinatario:{nombre, direccion, ciudad_dane, telefono, documento}, valor_declarado,
contenido, referencia, observaciones, detalle}`.

Reglas fijas (observaciones de Coordinadora incorporadas):

| Campo | Valor |
|---|---|
| `codigo_remision` | `''` |
| `fecha` | `''` (obs. 1) |
| `id_cliente` | del ajuste (default 49444) |
| `estado` | `'IMPRESO'` |
| `id_remitente` | `0` |
| `nit_remitente` | `''` (obs. 2) |
| `nombre_remitente` | ajuste razón social (obs. 3) |
| `direccion_remitente` / `telefono_remitente` | ajustes |
| `ciudad_remitente` | ajuste origen (default `08001000`) (obs. 4) |
| `nit_destinatario` | documento del cliente (meta `_billing_document_number`) |
| `div_destinatario` | `''` |
| `nombre_destinatario` | nombre + apellidos del pedido |
| `direccion_destinatario` | `address_1` + `address_2` |
| `ciudad_destinatario` | DANE (reutiliza `CCMCK_Coordinadora::dane_from_city`) |
| `telefono_destinatario` | teléfono del pedido |
| `valor_declarado` | suma de `line_total` del pedido (int) |
| `codigo_cuenta` | `2` |
| `codigo_producto` | `0` |
| `nivel_servicio` | `1` |
| `linea` | `''` |
| `contenido` | `'Equipos de sonido'` (constante) |
| `referencia` | número del pedido (`$order->get_order_number()`) |
| `observaciones` | nota del cliente del pedido (o `''`) |
| `detalle` | `CCMCK_Coordinadora::build_detalle( pack(...) )` con `referencia:''` y `nombre_empaque:'Caja'` por entrada |
| `recaudos` | `array()` |
| `margen_izquierdo` / `margen_superior` | `0` |
| `formato_impresion` | `''` |
| `usuario` / `clave` | usuario + SHA-256 |

**`parse_guia_response( string $body, $http_code ): array`** — PURO. →
`{ok, codigo_remision, id_remision, tracking_url, error}`. Mismas reglas que
`parse_response` del cotizador (no-JSON → fail; `error !== null` → fail; falta
`codigo_remision` → fail).

**`build_webhook_payload( array $args ): array`** — PURO. →
`{order_id, order_number, guia, tracking_url, customer:{name, phone}, total}`.

**Capa acoplada:**
- `endpoint(): string` — sandbox o producción según ajuste.
- `rpc( array $body ): array` — `wp_remote_post` (timeout 15 s: generar guía tarda
  más que cotizar) + parse. Log a `WC_Logger` canal `ccmck-coordinadora`.
- `on_processing( $order_id )` — hook `woocommerce_order_status_processing`, prio 20:
  1. Guards: toggle `guias_enabled` ON; usuario/clave no vacíos; método de envío del
     pedido no es pickup (`ccmck_local_pickup`); meta `_coordinadora_tracking_number`
     vacío (**idempotencia**); candado transient `ccmck_guia_lock_{id}` (60 s) contra
     doble disparo.
  2. Ítems del pedido; si alguno sin peso/dimensiones → nota interna
     "Guía Coordinadora NO generada: producto sin peso/medidas (SKU …)" + log, abort.
  3. Ciudad DANE del pedido; si no se extrae → nota + abort.
  4. `pack()` con umbral y reglas de los ajustes existentes → `build_detalle`.
  5. `generarGuia`. **Éxito**: metas `_coordinadora_tracking_number` (compatible con
     la UI del plugin viejo), `_coordinadora_tracking_url` (= `url_terceros`),
     `_ccmck_guia_id_remision`; nota interna "Guía Coordinadora generada: NNN";
     webhook a n8n. **Fallo**: nota interna con el mensaje + log; sin reintento
     automático.
- `send_webhook( array $payload )` — POST JSON a `guias_webhook_url` (si está
  configurada), timeout 5 s, **fire-and-forget**: cualquier fallo solo se loguea, la
  guía nunca se bloquea por WhatsApp.
- `render_metabox( $order )` / `ajax_label()` — ver Componente 2.
- `init()` — registra hook, metabox y acción AJAX. Registrar en `ccm-checkout.php`.

### 2. Botón "Descargar rótulo" (admin del pedido)

- Metabox "Guía Coordinadora" en el pedido (solo si hay
  `_coordinadora_tracking_number`): número de guía + link de rastreo + botón
  "Descargar rótulo".
- El botón apunta a `admin-ajax.php?action=ccmck_guia_label&order_id=N&_wpnonce=…`
  (capability `edit_shop_orders` + nonce). El handler llama
  `Guias.reimprimirGuia {codigo_remision, formato_impresion:"1"}`, decodifica el
  base64 y responde `Content-Type: application/pdf` +
  `Content-Disposition: attachment; filename="guia-NNN.pdf"`. Errores → `wp_die`
  con el mensaje.

### 3. Ajustes — sección "Generación de guías" en la pestaña Coordinadora

Keys nuevas en `ccmck_settings` (defaults + whitelist en sanitize + campos en la
vista, mismo patrón existente):

| Key | Default | Sanitize |
|---|---|---|
| `guias_enabled` | `false` | `! empty()` |
| `guias_env` | `'sandbox'` | whitelist `sandbox|production` |
| `guias_usuario` | `'ccmtienda.ws'` | `sanitize_text_field` |
| `guias_clave` | `''` | `sanitize_text_field` (se guarda plana; SHA-256 en runtime) |
| `guias_id_cliente` | `49444` | `absint` |
| `guias_remitente_nombre` | `'CCM Tienda del Sonido'` | `sanitize_text_field` |
| `guias_remitente_direccion` | `''` | `sanitize_text_field` |
| `guias_remitente_telefono` | `''` | solo dígitos |
| `guias_webhook_url` | `''` | `esc_url_raw` |

La ciudad remitente reutiliza `coordinadora_origin` (una sola fuente).

### 4. Workflow n8n — aviso por WhatsApp (entregable aparte)

JSON **import-ready** (patrón de la casa: backup → patch aislado → import → smokes):

- **Webhook** (POST, path secreto) — recibe el payload del plugin.
- Normalizar teléfono a formato `57XXXXXXXXXX`.
- Enviar por el patrón **`cwSendWa01`** (Chatwoot): sesión abierta → mensaje normal;
  sin sesión (caso típico del comprador web) → **plantilla WABA**.
- Mensaje: `¡Hola {nombre}! Tu pedido #{order_number} ya va en camino 🚚 Guía
  Coordinadora: {guia}. Síguelo aquí: {tracking_url}`.
- **Dependencia de configuración (usuario):** verificar si alguna de las 13
  plantillas WABA existentes sirve para "pedido despachado con guía"; si no, crear
  una en Meta. El workflow se entrega parametrizado con el nombre de la plantilla.

### 5. Go-live (según observaciones de Coordinadora)

1. Deploy con `guias_enabled = false` (nada cambia).
2. Configurar sección de guías con `guias_env = sandbox`; activar; hacer un pedido
   de prueba → verificar guía sandbox + nota + metabox + rótulo + WhatsApp.
3. Cambiar `guias_env = production`; **avisar a Coordinadora el día** para que
   Geraldine revise la liquidación de los primeros despachos.
4. Monitorear log `ccmck-coordinadora` y las notas de pedido los primeros días.

## Riesgos cubiertos

- **Doble guía (plugin viejo):** el plugin de terceros NO genera guías desde
  WordPress (solo notifica a su nube); si el panel de Coordinadora generara una para
  el mismo pedido, sería visible porque ambos escriben el mismo meta. Acción
  operativa: dejar de crear guías a mano para pedidos web tras el go-live.
- **Pickup:** excluido por guard.
- **Doble disparo del hook:** meta de idempotencia + transient lock.
- **Dirección/ciudad inválida:** abort con nota interna; la guía se hace a mano.
- **WhatsApp caído:** no bloquea (fire-and-forget + log).

## Testing

**`tests/GuiasTest.php`** (nuevo, lógica pura):
- `build_guia_params`: `fecha === ''`, `nit_remitente === ''`, `estado === 'IMPRESO'`,
  `id_remitente === 0`, `recaudos === []`, `codigo_cuenta === 2`, nombre/ciudad
  remitente desde args, destinatario mapeado, `detalle` con `nombre_empaque`.
- `parse_guia_response`: éxito (codigo_remision + url_terceros), error de negocio,
  body no-JSON, respuesta sin codigo_remision.
- `build_webhook_payload`: forma exacta del payload.
- `items_from_order` (parte pura): mapeo de líneas.

**`tests/SettingsTest.php`**: defaults + sanitize de las 9 keys nuevas (env
whitelist, id_cliente absint, teléfono solo dígitos, URL).

Sin tests de WP para hook/metabox/AJAX (verificación manual en sandbox, paso 2 del
go-live).

## Archivos afectados

| Archivo | Cambio | Deploy |
|---|---|---|
| `includes/class-ccmck-guias.php` | **nuevo** | PHP → OPcache |
| `ccm-checkout.php` | require + `CCMCK_Guias::init()` | PHP → OPcache |
| `includes/class-ccmck-settings.php` | 9 keys nuevas | PHP → OPcache |
| `includes/views/settings-page.php` | sección "Generación de guías" | PHP → OPcache |
| `tests/GuiasTest.php` (nuevo), `tests/SettingsTest.php`, `tests/bootstrap.php` | tests + require | — |
| `docs/CHANGELOG.md` | entrada | — |
| Workflow n8n (JSON aparte) | **nuevo** | import en n8n |

## Fuera de alcance (YAGNI)

- Anular/regenerar guía con botón (existe `anularGuia`; se hace desde el panel si
  hace falta).
- Tracking embebido en la página de gracias.
- Plantilla WABA en Meta (configuración del usuario, no código).
- Contraentrega/recaudos (no aplica: todo prepagado).
- Impresión masiva de rótulos / despachos (`generarDespacho`).
