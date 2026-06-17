# Sistecrédito como popup en el checkout — Diseño

Fecha: 2026-06-17
Estado: Aprobado (diseño)

## Contexto

El checkout propio **CCM Checkout** (`mu-plugins/ccm-checkout`) ya integra dos
pasarelas como **popup sobre `/pago/`**, sin que el cliente salga del checkout:

- **Wompi** (`CCMCK_Wompi` + intercepción de `$.ajax` en `ccmck-checkout.js`):
  intercepta la respuesta de `wc-ajax=checkout`, trae los datos FIRMADOS de la
  página *order-pay* y abre `window.WidgetCheckout` (modal propio de Wompi).
- **Efecty** (Mercado Pago ticket): intercepta la respuesta, trae el comprobante
  de la página *order-received* y lo muestra en un modal propio (`.ccmck-efecty-modal`).

Se acaba de añadir la pasarela **Sistecrédito** (plugin
`wp-content/plugins/spay-php-pluginwoocomerce-master`, gateway id
**`wcsistecredito`**, título "Paga con"). Hoy, al pagarla, el cliente es
**redirigido fuera de `/pago/`** a la página *order-pay*, donde recién aparece el
visor. El objetivo es integrarla **igual que Wompi**: que el visor/modal de
Sistecrédito abra **sobre `/pago/`**, sin navegar.

## Cómo funciona el plugin de Sistecrédito (hechos verificados)

Del gateway `SCMDP_Sistecredito` en `sistecredito.php`:

- Ajuste **"Habilitar widget para checkout"** (`active_widget`) → **CONFIRMADO ACTIVO**
  por el usuario.
- Con el widget activo, `process_payment($order_id)`:
  - Lee de `$_POST` `wcsistecredito-document-type` y `wcsistecredito-document-id`
    (default type `CC`, id `""`).
  - Guarda ambos en transients (`set_transient('wcsistecredito-document-type'…)`).
  - Devuelve `['result' => 'success', 'redirect' => $order->get_checkout_payment_url(true)]`
    → la **página order-pay** (igual que Wompi).
- En order-pay, `receipt_page($order_id)` (hook `woocommerce_receipt_wcsistecredito`):
  - Genera un **JWT `authentication`** server-side (`Helper::createJwtToken`, secreto
    del plugin — NO recreable desde fuera).
  - Recupera documento de los transients.
  - Encola `js/payment.js` y pinta **`payment_widget.php`**, que contiene:
    - `<script id="visor" data-key="…" src="{visor_url}">` (el JS remoto del visor).
    - `<app-visor id="Checkout" app="Checkout" options='{ idDocument, typeDocument,
      valueToPaid, orderId, authentication, storeId, vendorId, responseUrl,
      externalPageRedirection:"false", buttonFloating:"false",
      defaultButtonHidden:"true", openModal:"true" }'>` → **web component que abre
      su PROPIO modal**.
- `payment.js` define:
  - `openVisorCheckout()` → tras 2 s, `document.dispatchEvent(new CustomEvent("sc:visor:open", {detail:"Checkout"}))`.
  - listener `sc:visor:error` → redirige al endpoint `…/wc-api/siscredito_redirect`
    (que crea la transacción y redirige a la página externa de Sistecrédito).
- `payment_fields()` (checkout clásico) encola su CSS y pinta `payment.php`: campos
  propios **`#wcsistecredito-document-type`** (select, name `wcsistecredito-document-type`)
  y **`#wcsistecredito-document-id`** (input, name `wcsistecredito-document-id`).
- `responseUrl` = `…/wc-api/siscredito_confirmation` → `confirm_payment()` confirma
  el pago y `wp_redirect($this->get_return_url($order))` (order-received) o a
  `url_return` si está configurada.

**Conclusión:** es el análogo exacto de Wompi. El JWT es el dato firmado que NO se
recrea; se **scrapea** de order-pay (igual que la firma de Wompi).

## Enfoque elegido

**A — Inline tipo Wompi.** Interceptar el AJAX del checkout, traer la config del
visor de order-pay y abrir el visor inline sobre `/pago/`. El visor usa **su propio
modal** (`openModal:"true"`), así que NO se envuelve en chrome propio (a diferencia
de Efecty). Fallback robusto a la redirección nativa.

(Descartado **B**: dejar que redirija a order-pay — el cliente sale de `/pago/`.
Descartado **C**: pre-cargar sin crear pedido — el pedido y el JWT se generan
server-side en order-pay.)

## Arquitectura y flujo

Todo vive en `assets/ccmck-checkout.js` (+ reglas en `assets/ccmck-checkout.css`).
**Sin clases PHP nuevas**: se reutiliza lo que ya pinta el plugin de Sistecrédito.

### 1. Detección del método

```js
function ccmckSistecreditoSelected() {
  return $('input[name="payment_method"]:checked').val() === 'wcsistecredito';
}
```

### 2. Intercepción (rama nueva en el wrapper de `$.ajax` existente)

En el bloque que ya distingue Wompi (`order-pay|pay_for_order`) y Efecty
(`order-received`), añadir:

```js
} else if ( data && 'success' === data.result && data.redirect &&
     /order-pay|pay_for_order/.test( data.redirect ) && ccmckSistecreditoSelected() ) {
    handled = ccmckOpenSistecreditoPopup( data.redirect );
}
```

(El gate por método evita colisión con la rama de Wompi, que comparte el patrón
`order-pay`.)

### 3. `ccmckOpenSistecreditoPopup(orderPayUrl)`

1. `fetch(orderPayUrl, { credentials:'include' })` → `text()`.
2. `DOMParser` → extraer:
   - `script#visor[src]` (atributos `src` + `data-key`).
   - `app-visor[options]` (el elemento del visor con su `options` JSON, que ya
     incluye el JWT `authentication`, `responseUrl`, documento, total, etc.).
3. Si falta cualquiera de los dos → `window.location = orderPayUrl` (fallback).
4. Si no está cargado aún, **inyectar el script del visor** (`src` + `data-key`) en
   `<head>`/`<body>` y esperar a que defina el custom element (`customElements.whenDefined('app-visor')`
   o un pequeño poll con timeout).
5. Inyectar el `<app-visor>` (clonado del parseado) en un contenedor propio
   `#ccmck-sistecredito-host` en `document.body`.
6. Registrar el listener `sc:visor:error` (réplica de `payment.js`): al fallar,
   `window.location = orderPayUrl` (que reintenta el flujo nativo).
7. Disparar la apertura: `document.dispatchEvent(new CustomEvent('sc:visor:open', { detail: 'Checkout' }))`
   (réplica de `openVisorCheckout`, con el mismo id `Checkout`).
8. Quitar el estado `processing` del form (`$('form.checkout').removeClass('processing').unblock()`).
9. Devuelve `true` (handled) para que WC no redirija.

### 4. Éxito / redirección final (RIESGO a validar en vivo)

`payment.js` solo expone `sc:visor:open` y `sc:visor:error`; el camino de **éxito**
no es explícito (lo maneja el visor: probablemente navega solo vía `responseUrl`
tras la aprobación, igual que en order-pay). Plan:

- Confiar primero en la navegación propia del visor (mismo comportamiento que en
  order-pay, ya que cargamos el mismo `<app-visor>` con el mismo `responseUrl`).
- Si en pruebas el visor embebido NO navega tras aprobar, añadir un listener al
  evento de éxito que emita el visor (descubrir su nombre en vivo, p. ej.
  `sc:visor:success`/`close`) → `window.location = orderReceivedUrl`.

Este punto se cierra **probando en vivo** (chrome-devtools MCP).

### 5. Documento (reusar el del checkout — decisión del usuario)

El cliente ya ingresó **Tipo de documento** (`#billing_document_type`, valores
numéricos: 13=CC, 22=CE, 31=NIT…) y **Número de documento**
(`#billing_document_number`) en la sección Entrega. Sistecrédito solo acepta
**CC/CE**.

- **Sincronizar** los campos propios del plugin (que se pintan en su caja de pago)
  desde los de billing, vía JS (delegado en `input/change` + en `updated_checkout`),
  igual que el `billing_id` de Addi:
  - `wcsistecredito-document-type` ← mapeo por **texto** de la opción de
    `#billing_document_type`: `"CC" → CC`, `"CE" → CE`, cualquier otro → **CC**
    (default; Sistecrédito es crédito de consumo).
  - `wcsistecredito-document-id` ← `#billing_document_number`.
- **Ocultar** por CSS los campos propios de Sistecrédito en su caja de pago
  (`#wcsistecredito-document-type`, `#wcsistecredito-document-id` y su `<p>`), para
  no duplicar la captura. Se mantienen en el DOM (ocultos) para que se POSTeen.
- Si por timing el plugin re-pinta sus campos, la sincronización en `updated_checkout`
  los re-rellena.

### 6. Activación / fallback

- Filtro `ccmck_sistecredito_popup_enabled` (default `true`) por si se quiere
  desactivar y volver al flujo nativo. (Se evalúa en JS leyendo un flag localizado,
  o simplemente como constante; alineado con `ccmck_wompi_popup_enabled`.)
- **Cualquier** fallo (sin `<app-visor>`, fetch/parse/throw, script no carga en N ms)
  → `window.location = orderPayUrl`. El checkout nunca se rompe.

## Componentes y límites

- **`ccmckSistecreditoSelected()`** — predicado puro del método seleccionado.
- **`ccmckOpenSistecreditoPopup(orderPayUrl)`** — orquesta fetch → extraer → cargar
  script → abrir visor → fallback. Único punto que toca el visor.
- **`ccmckSyncSistecreditoDoc()`** — sincroniza los campos doc del plugin desde
  billing (mapa CC/CE). Idempotente; se llama en `input/change` y `updated_checkout`.
- **Rama en el wrapper de `$.ajax`** — una condición más, sin tocar Wompi/Efecty.
- **CSS** — ocultar campos doc del plugin + (si hace falta) estilos mínimos del host.

Cada pieza es independiente y testeable; la superficie nueva es pequeña y acotada a
`ccmck-checkout.js`/`.css`.

## Manejo de errores

| Caso | Manejo |
|---|---|
| Método no es `wcsistecredito` | No se intercepta (otras ramas o flujo normal). |
| `fetch` de order-pay falla | `catch` → `window.location = orderPayUrl`. |
| No hay `script#visor` o `app-visor` en el HTML | `window.location = orderPayUrl`. |
| El script del visor no define el custom element en N ms | `window.location = orderPayUrl`. |
| `sc:visor:error` | `window.location = orderPayUrl` (réplica de `payment.js`). |
| Documento vacío/no CC-CE | Mapea a CC; el `id` viene de billing_document_number (obligatorio en el checkout). |

## Pruebas

- **PHPUnit:** el cambio es JS/CSS (front), sin lógica PHP nueva → no requiere tests
  PHPUnit nuevos (igual que Wompi/Efecty, que se validaron en vivo).
- **En vivo (chrome-devtools MCP)** — el método de validación de este proyecto:
  1. Producto al carrito → `/pago/` → llenar documento + términos → método
     **"Paga con" (Sistecrédito)** → Realizar el pedido.
  2. Confirmar que la URL **se queda en `/pago/`** y abre el **visor de Sistecrédito**
     (modal) con el monto/documento correctos.
  3. Confirmar la **sincronización de documento** (los campos del plugin van llenos
     y ocultos; el pedido se crea con el documento correcto).
  4. Validar el **camino de éxito** (qué hace el visor al aprobar/cerrar) y, si no
     auto-navega, cerrar el pendiente del §4 con el evento real.
  5. Validar el **fallback**: forzar un fallo (p. ej. visor ausente) → redirige a
     order-pay sin romper.
- Se probará por **inyección** (re-wrap de `$.ajax` + CSS) antes de desplegar, como
  con Wompi/Efecty.

## Despliegue

- Archivos: `assets/ccmck-checkout.js` + `assets/ccmck-checkout.css` (ambos
  **auto cache-bust por `filemtime`**, sin OPcache).
- Subida por **File Manager** del hosting (la carpeta local no se sincroniza).
- Commit en `main` + entrada en `docs/CHANGELOG.md`.

## Fuera de alcance

- Reescribir el visor o su modal (es del proveedor; solo lo cargamos/abrimos).
- Soporte para el checkout por **bloques** de WooCommerce (CCM usa el clásico; el
  plugin ya trae su integración de bloques aparte en `payment.js`/`wcsistecredito-blocks.php`).
- Tipos de documento fuera de CC/CE (Sistecrédito no los soporta).
- El **simulador** de Sistecrédito en páginas de producto (no toca el checkout).
