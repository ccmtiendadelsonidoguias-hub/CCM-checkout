# Sistecrédito como popup en el checkout — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que al pagar con Sistecrédito (`wcsistecredito`) el visor/modal abra sobre `/pago/` sin que el cliente salga del checkout, reusando el documento ya capturado.

**Architecture:** Mismo patrón que el popup de Wompi: una rama nueva en la intercepción de `$.ajax` (`wc-ajax=checkout`) ya existente en `assets/ccmck-checkout.js`. Cuando el método es `wcsistecredito` y WooCommerce devuelve `redirect → order-pay`, se hace `fetch` de esa página, se extrae el `<app-visor>` (con su JWT `authentication`) + el `<script id="visor">`, se inyectan en `/pago/` y se dispara `sc:visor:open` (el visor abre su propio modal). Los campos de documento propios del plugin se ocultan por CSS y se autollenan desde `billing_document_*`. Cualquier fallo cae al flujo nativo (redirección a order-pay).

**Tech Stack:** WooCommerce checkout clásico, jQuery (`window.jQuery`), Web Components (`app-visor`), CSS. Sin PHP nuevo. Sin librerías nuevas.

## Global Constraints

- Gateway id: **`wcsistecredito`**. Título visible: "Paga con".
- El visor SOLO acepta tipo de documento **CC** o **CE**; cualquier otro → default **CC**.
- Campos que el plugin POSTea: `wcsistecredito-document-type` (select `#wcsistecredito-document-type`), `wcsistecredito-document-id` (input `#wcsistecredito-document-id`).
- El JWT `authentication` es server-side (secreto del plugin): **NO se recrea**, se scrapea de order-pay.
- **Fallback obligatorio:** ante cualquier fallo → `window.location = orderPayUrl`. El checkout nunca se rompe.
- Activable/desactivable con el filtro conceptual `ccmck_sistecredito_popup_enabled` (guard JS), alineado con `ccmck_wompi_popup_enabled`.
- Despliegue: solo `assets/ccmck-checkout.js` + `assets/ccmck-checkout.css` (auto cache-bust por `filemtime`, sin OPcache), por File Manager. La carpeta local NO se sincroniza con el server.
- Verificación: **en vivo con chrome-devtools MCP** (no hay tests JS). Se prueba por inyección antes de desplegar.
- Rama git `main`. Commits con `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## File Structure

- `assets/ccmck-checkout.js` — **modificar**: añadir (a) sincronización de documento de Sistecrédito, (b) predicado + función de popup, (c) rama en el wrapper de `$.ajax`.
- `assets/ccmck-checkout.css` — **modificar**: ocultar los campos de documento propios del plugin en la caja de pago de Sistecrédito.
- `docs/CHANGELOG.md` — **modificar**: entrada en "Añadido".

Referencias de inserción en `ccmck-checkout.js` (anclas estables, no números de línea):
- La sincronización de documento va junto al bloque de Addi `ccmckSyncBillingId` (busca `$( document ).on( 'input change', '#billing_document_number', ccmckSyncBillingId );`).
- Las funciones del popup van **justo antes** de `var ccmckOrigAjax = $.ajax;`.
- La rama nueva va dentro del `opts.success` del wrapper, **después** del `else if` de Efecty (`/order-received/.test( data.redirect ) && ccmckEfectySelected()`).

---

### Task 1: Sincronizar y ocultar los campos de documento de Sistecrédito

**Files:**
- Modify: `assets/ccmck-checkout.js` (junto al bloque `ccmckSyncBillingId`)
- Modify: `assets/ccmck-checkout.css` (sección nueva al final)

**Interfaces:**
- Produces: `ccmckSyncSistecreditoDoc()` — rellena `#wcsistecredito-document-type` (CC/CE) y `#wcsistecredito-document-id` desde billing. Idempotente. Sin retorno.

- [ ] **Step 1: Añadir la sincronización de documento en `ccmck-checkout.js`**

Inserta este bloque inmediatamente después de las líneas que enlazan `ccmckSyncBillingId` (las tres: `$( document ).on( 'input change', '#billing_document_number', ccmckSyncBillingId );`, `$( document.body ).on( 'updated_checkout', ccmckSyncBillingId );`, `$( ccmckSyncBillingId );`):

```js
    /* ------------------------------------------------------------------ */
    /*  Sistecrédito: reusar el documento del checkout                     */
    /*  El plugin (wcsistecredito) pinta sus propios campos de documento   */
    /*  (#wcsistecredito-document-type / -id) en su caja de pago. En vez   */
    /*  de pedir el documento dos veces, los OCULTAMOS por CSS y los       */
    /*  autollenamos desde billing_document_type/number (mapeando a CC/CE, */
    /*  únicos que acepta Sistecrédito). Mismo patrón que el billing_id de */
    /*  Addi.                                                              */
    /* ------------------------------------------------------------------ */
    function ccmckSistecreditoDocType() {
        var $sel = $( '#billing_document_type' );
        if ( ! $sel.length ) {
            return 'CC';
        }
        var txt = $.trim( $sel.find( 'option:selected' ).text() ).toUpperCase();
        return ( 'CE' === txt ) ? 'CE' : 'CC'; // Sistecrédito solo CC/CE; default CC
    }

    function ccmckSyncSistecreditoDoc() {
        var $type = $( '#wcsistecredito-document-type' );
        var $id   = $( '#wcsistecredito-document-id' );
        if ( $type.length ) {
            $type.val( ccmckSistecreditoDocType() );
        }
        if ( $id.length ) {
            $id.val( $.trim( $( '#billing_document_number' ).val() || '' ) );
        }
    }
    $( document ).on( 'input change', '#billing_document_number, #billing_document_type', ccmckSyncSistecreditoDoc );
    $( document.body ).on( 'updated_checkout', ccmckSyncSistecreditoDoc );
    $( ccmckSyncSistecreditoDoc );
```

- [ ] **Step 2: Añadir el CSS que oculta los campos propios del plugin**

Añade al final de `assets/ccmck-checkout.css`:

```css
/* =============================================================
   Sistecrédito: ocultar los campos de documento propios del
   plugin en su caja de pago. El documento se reusa del checkout
   (billing_document_*) y ccmck-checkout.js sincroniza los campos
   ocultos (#wcsistecredito-document-type / -id) que el plugin
   POSTea. Se ocultan con display:none (siguen en el DOM → se
   envían). #wcsistecredito-cc-form es el <fieldset> de payment.php;
   el <hr> hermano que pinta el plugin también se oculta.
   ============================================================= */
.ccmck #payment .payment_box #wcsistecredito-cc-form,
.ccmck #payment li.payment_method_wcsistecredito .payment_box > hr {
  display: none !important;
}
```

- [ ] **Step 3: Verificar en vivo por inyección (chrome-devtools MCP)**

1. Inyectar el CSS de Step 2 y la función `ccmckSyncSistecreditoDoc` de Step 1 (vía `evaluate_script`).
2. Navegar a `/pago/` con un producto, llenar Tipo de documento = "CC" y Número = "1234567890".
3. Seleccionar el método **"Paga con" (Sistecrédito)**.
4. Comprobar con `evaluate_script`:
   - `document.querySelector('#wcsistecredito-cc-form')` está oculto (`getComputedStyle(...).display === 'none'`).
   - `document.querySelector('#wcsistecredito-document-type').value === 'CC'`.
   - `document.querySelector('#wcsistecredito-document-id').value === '1234567890'`.
   - Cambiar billing a "CE" → tras `ccmckSyncSistecreditoDoc()`, el type pasa a `'CE'`.

Expected: campos ocultos y sincronizados correctamente.

- [ ] **Step 4: Commit**

```bash
git add assets/ccmck-checkout.js assets/ccmck-checkout.css
git commit -m "feat(checkout): reusar y ocultar el documento de Sistecredito (sync desde billing)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Intercepción y apertura del visor de Sistecrédito sobre /pago/

**Files:**
- Modify: `assets/ccmck-checkout.js` (funciones antes de `var ccmckOrigAjax = $.ajax;` y rama en `opts.success`)

**Interfaces:**
- Consumes: nada de Task 1 (independiente).
- Produces:
  - `ccmckSistecreditoSelected(): boolean` — `true` si el radio de pago marcado es `wcsistecredito`.
  - `ccmckOpenSistecreditoPopup(orderPayUrl: string): true` — orquesta fetch→extraer→cargar visor→abrir; siempre devuelve `true` (handled). Navega a `orderPayUrl` ante cualquier fallo.

- [ ] **Step 1: Añadir las funciones del popup de Sistecrédito**

Inserta este bloque **justo antes** de `var ccmckOrigAjax = $.ajax;`:

```js
    /* ------------------------------------------------------------------ */
    /*  Sistecrédito como POPUP (visor inline)                             */
    /*  Con el "widget para checkout" activo, al colocar el pedido el      */
    /*  plugin guarda el documento en transients y responde con redirect a */
    /*  la página order-pay, donde pinta un <app-visor> (web component que  */
    /*  abre su PROPIO modal) + el <script id="visor">. Interceptamos esa   */
    /*  respuesta, TRAEMOS el <app-visor> (con su JWT authentication) y el  */
    /*  script de esa página, los inyectamos aquí y abrimos el visor sobre  */
    /*  /pago/ sin navegar. Cualquier fallo → redirección normal a         */
    /*  order-pay (donde el visor abre igual). No recreamos el JWT.         */
    /* ------------------------------------------------------------------ */
    function ccmckSistecreditoSelected() {
        return $( 'input[name="payment_method"]:checked' ).val() === 'wcsistecredito';
    }

    function ccmckOpenSistecreditoPopup( orderPayUrl ) {
        if ( false === window.ccmckSistecreditoPopupEnabled ) {
            return false; // guard de activación; por defecto activo
        }
        fetch( orderPayUrl, { credentials: 'include' } )
            .then( function ( r ) { return r.text(); } )
            .then( function ( html ) {
                var doc       = new DOMParser().parseFromString( html, 'text/html' );
                var scriptEl  = doc.querySelector( 'script#visor[src]' );
                var visorEl   = doc.querySelector( 'app-visor' );
                if ( ! scriptEl || ! visorEl ) {
                    window.location = orderPayUrl; // sin visor → flujo normal
                    return;
                }

                var host = document.getElementById( 'ccmck-sistecredito-host' );
                if ( ! host ) {
                    host = document.createElement( 'div' );
                    host.id = 'ccmck-sistecredito-host';
                    document.body.appendChild( host );
                }

                // Si el visor falla, el plugin emite sc:visor:error → fallback.
                document.addEventListener( 'sc:visor:error', function onErr() {
                    document.removeEventListener( 'sc:visor:error', onErr );
                    window.location = orderPayUrl;
                } );

                var appVisor = document.importNode( visorEl, true );

                function mountAndOpen() {
                    host.innerHTML = '';
                    host.appendChild( appVisor );
                    // réplica de openVisorCheckout() del plugin (mismo id "Checkout").
                    document.dispatchEvent( new CustomEvent( 'sc:visor:open', {
                        detail: appVisor.getAttribute( 'id' ) || 'Checkout'
                    } ) );
                }

                if ( window.customElements && customElements.get( 'app-visor' ) ) {
                    mountAndOpen();
                    return;
                }

                // Cargar el script del visor (con su data-key) y esperar a que
                // defina el custom element, con timeout de seguridad.
                var s = document.createElement( 'script' );
                s.id  = 'visor';
                s.src = scriptEl.getAttribute( 'src' );
                if ( scriptEl.getAttribute( 'data-key' ) ) {
                    s.setAttribute( 'data-key', scriptEl.getAttribute( 'data-key' ) );
                }
                s.onerror = function () { window.location = orderPayUrl; };
                document.body.appendChild( s );

                var waited = 0;
                var timer = setInterval( function () {
                    if ( window.customElements && customElements.get( 'app-visor' ) ) {
                        clearInterval( timer );
                        mountAndOpen();
                    } else if ( ( waited += 100 ) >= 8000 ) {
                        clearInterval( timer );
                        window.location = orderPayUrl;
                    }
                }, 100 );
            } )
            .catch( function () { window.location = orderPayUrl; } );
        return true;
    }
```

- [ ] **Step 2: Añadir la rama en el wrapper de `$.ajax`**

En el `opts.success` del wrapper, justo después del `else if` de Efecty (el bloque `} else if ( data && 'success' === data.result && data.redirect && /order-received/.test( data.redirect ) && ccmckEfectySelected() ) { handled = ccmckOpenEfectyPopup( data.redirect ); }`), añade:

```js
                    } else if ( data && 'success' === data.result && data.redirect &&
                         /order-pay|pay_for_order/.test( data.redirect ) && ccmckSistecreditoSelected() ) {
                        handled = ccmckOpenSistecreditoPopup( data.redirect );
```

Queda así la cadena completa de condiciones (Wompi → Efecty → Sistecrédito):

```js
                try {
                    if ( data && 'success' === data.result && data.redirect &&
                         /order-pay|pay_for_order/.test( data.redirect ) && ccmckWompiSelected() ) {
                        handled = ccmckOpenWompiPopup( data.redirect );
                    } else if ( data && 'success' === data.result && data.redirect &&
                         /order-received/.test( data.redirect ) && ccmckEfectySelected() ) {
                        handled = ccmckOpenEfectyPopup( data.redirect );
                    } else if ( data && 'success' === data.result && data.redirect &&
                         /order-pay|pay_for_order/.test( data.redirect ) && ccmckSistecreditoSelected() ) {
                        handled = ccmckOpenSistecreditoPopup( data.redirect );
                    }
                } catch ( e ) { handled = false; }
```

(El gate por método —`ccmckSistecreditoSelected()`— impide que choque con la rama de Wompi, que comparte el patrón `order-pay`. El bloque `if ( handled ) { ... removeClass('processing').unblock(); return; }` ya existente desbloquea el form y evita la redirección de WC.)

- [ ] **Step 3: Lint del JS**

Run: `node -c assets/ccmck-checkout.js`
Expected: sin salida (sintaxis OK).

- [ ] **Step 4: Commit**

```bash
git add assets/ccmck-checkout.js
git commit -m "feat(checkout): Sistecredito como popup (visor inline sobre /pago/)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Verificación en vivo end-to-end y cierre del flujo de éxito

**Files:**
- (Posible) Modify: `assets/ccmck-checkout.js` — solo si el §4 del spec (evento de éxito del visor) requiere un listener extra.

**Interfaces:**
- Consumes: Task 1 (`ccmckSyncSistecreditoDoc`), Task 2 (`ccmckOpenSistecreditoPopup`, rama `$.ajax`).

- [ ] **Step 1: Inyectar la implementación completa en el checkout en vivo**

Con chrome-devtools MCP, en `/pago/`, inyectar por `evaluate_script`: el CSS de Task 1, las funciones de Task 1 y Task 2, y un re-wrap de `$.ajax` que añada la rama de Sistecrédito por encima del wrapper desplegado (mismo método usado para probar Wompi/Efecty). Definir `window.ccmckSistecreditoPopupEnabled = true`.

- [ ] **Step 2: Colocar un pedido real con Sistecrédito**

Añadir producto al carrito → `/pago/` → llenar billing documento (CC + número) + términos → seleccionar **"Paga con" (Sistecrédito)** → Realizar el pedido.

- [ ] **Step 3: Confirmar el popup inline**

Con `take_snapshot` / `evaluate_script` verificar:
- La URL **sigue en `/pago/`** (no navegó).
- Existe el `<app-visor id="Checkout">` dentro de `#ccmck-sistecredito-host` y el visor está **visible** (su modal abierto).
- El visor muestra el **monto** y **documento** correctos.
- En `#payment`, `#wcsistecredito-document-type`/`-id` fueron POSTeados con el documento del checkout (el pedido se creó con el documento correcto — revisar la order/nota).

Expected: el visor de Sistecrédito abre sobre `/pago/` sin navegar.

- [ ] **Step 4: Resolver el flujo de ÉXITO (open item del spec §4)**

Observar qué hace el visor al **aprobar/cerrar**: escuchar en `document` los eventos `sc:visor:*` (p. ej. con un listener temporal que loguee cualquier `CustomEvent` cuyo `type` empiece por `sc:visor`) y/o ver si el visor navega solo vía su `responseUrl`.
- Si el visor **auto-navega** a order-received al aprobar → no hace falta nada.
- Si **no** auto-navega → añadir en `ccmckOpenSistecreditoPopup` un listener al evento de éxito real descubierto (p. ej. `sc:visor:success`/`sc:visor:close`) que haga `window.location = orderReceivedUrl` (derivar `orderReceivedUrl` del propio `options.responseUrl`/order, o de `wc_get_endpoint_url` expuesto). Documentar el evento real en el commit.

- [ ] **Step 5: Probar el fallback**

Forzar un fallo (p. ej. inyectar una variante de `ccmckOpenSistecreditoPopup` donde `visorEl` sea `null`) y confirmar que `window.location` va a `orderPayUrl` (el flujo nativo, donde el visor abre en order-pay) sin romper el checkout.

- [ ] **Step 6: (Si hubo cambio en Step 4) lint + commit**

```bash
node -c assets/ccmck-checkout.js
git add assets/ccmck-checkout.js
git commit -m "fix(checkout): cierre del flujo de exito del visor de Sistecredito

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Changelog y despliegue

**Files:**
- Modify: `docs/CHANGELOG.md`

- [ ] **Step 1: Añadir la entrada en el CHANGELOG**

En `docs/CHANGELOG.md`, bajo `## [Sin publicar]` → `### Añadido`, añade como primer ítem:

```markdown
- **Sistecrédito como popup en el checkout (no página nueva)**: al pagar con Sistecrédito (`wcsistecredito`, con el "widget para checkout" activo), en vez de redirigir a la página order-pay, el **visor de Sistecrédito** abre en un modal **sobre `/pago/`**, sin que el cliente salga del checkout. Mismo patrón que el popup de Wompi: `ccmck-checkout.js` intercepta la respuesta del `wc-ajax=checkout` cuando el método es Sistecrédito y el redirect es a order-pay, **trae el `<app-visor>` (con su JWT de autenticación) y el script del visor** de esa página, los inyecta en `/pago/` y dispara `sc:visor:open`. El documento se **reusa del checkout**: los campos propios del plugin (`#wcsistecredito-document-type`/`-id`) se ocultan por CSS y se autollenan desde `billing_document_*` (mapeados a CC/CE). **Fallback** robusto: ante cualquier fallo (sin visor, fetch/parse/throw, script no carga, `sc:visor:error`) se cae a la redirección normal a order-pay. Verificado en vivo (chrome-devtools).
```

- [ ] **Step 2: Commit**

```bash
git add docs/CHANGELOG.md
git commit -m "docs(changelog): Sistecredito como popup en el checkout

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 3: Push y desplegar**

```bash
git push origin main
```

Indicar al usuario que suba por **File Manager**: `assets/ccmck-checkout.js` + `assets/ccmck-checkout.css` (auto cache-bust por `filemtime`, **sin OPcache**). Tras subir, verificar por MCP con `fetch` cache-bust que el server sirve el código nuevo y repetir un pago real con Sistecrédito para confirmarlo desplegado.

---

## Self-Review

**Cobertura del spec:**
- Detección de método → Task 2 (`ccmckSistecreditoSelected`). ✓
- Intercepción + fetch order-pay + extraer `<app-visor>`/script + abrir visor → Task 2 (`ccmckOpenSistecreditoPopup`) + rama `$.ajax`. ✓
- Fallback ante todos los casos de error → Task 2 (catch, sin visor, onerror, timeout) + Task 3 Step 5. ✓
- Documento reusado (sync CC/CE + ocultar campos del plugin) → Task 1. ✓
- Sin chrome propio (visor abre su modal) → Task 2 (solo monta `<app-visor>` + dispatch open). ✓
- Activación `ccmck_sistecredito_popup_enabled` → Task 2 (guard `window.ccmckSistecreditoPopupEnabled`). ✓
- Riesgo §4 (evento de éxito) → Task 3 Step 4 (resolver en vivo). ✓
- Pruebas en vivo MCP + despliegue + changelog → Task 3, Task 4. ✓

**Placeholders:** ninguno. El único punto "abierto" (evento de éxito) es una tarea de descubrimiento en vivo con acción concreta y fallback, no un placeholder de implementación.

**Consistencia de tipos/nombres:** `ccmckSistecreditoSelected`, `ccmckOpenSistecreditoPopup`, `ccmckSyncSistecreditoDoc`, `ccmckSistecreditoDocType` usados de forma consistente. Ids del DOM: `#wcsistecredito-document-type`/`-id`, `#wcsistecredito-cc-form`, `app-visor#Checkout`, `script#visor`, `#ccmck-sistecredito-host`. Evento `sc:visor:open`/`sc:visor:error` igual que el plugin.
