# Spec: Skeleton loading (shimmer) en el checkout

**Fecha:** 2026-06-12
**Plugin:** ccm-checkout (mu-plugin)
**Estado:** Aprobado (diseño)

## Contexto

El checkout es server-rendered (los campos aparecen al instante). Durante los refrescos
AJAX de WooCommerce (`update_checkout` → `updated_checkout`, al cambiar cantidad, dirección
o método de envío) el resumen del pedido y los métodos de pago/envío se recalculan, y WC tapa
esas zonas con su **overlay blanco + spinner** por defecto. No hay tratamiento de carga propio.

El usuario quiere **skeleton loading con efecto shimmer** (barrido), tanto en la **carga
inicial** de la página como durante los **refrescos AJAX**, sobre campos, métodos de pago,
resumen, etc. Validado en vivo por MCP el look de dos paletas (clara para la columna blanca,
oscura para el sidebar negro).

## Decisiones de brainstorming (2026-06-12)

- **Momentos:** carga inicial **y** refrescos AJAX.
- **Estilo:** **shimmer** (gradiente que se desliza), dos paletas (clara / oscura).
- **Mecanismo central:** **toggle de clases CSS** sobre los contenedores de cada región (NO
  inyectar markup dentro que WooCommerce pueda reemplazar en el AJAX). La barra shimmer se
  dibuja con `::after` sobre la región y se oculta el contenido real debajo.

## Comportamiento esperado

### Regiones

| Región      | Contenedor                                   | Paleta  | Carga inicial | AJAX |
|-------------|----------------------------------------------|---------|---------------|------|
| Campos      | `.checkout-main .form-row` (una barra/fila)  | clara   | Sí            | No   |
| Envío       | `#ccmck_shipping_methods`                     | clara   | Sí            | Sí   |
| Pago        | `.ccmck-payment-section` / `#payment`         | clara   | Sí            | Sí   |
| Resumen     | `.checkout-sidebar` (review-order + totales)  | oscura  | Sí            | Sí   |

Los campos solo llevan skeleton en la carga inicial (no se recargan por AJAX).

### Carga inicial — clase `ccmck-preload` (CSS puro, sin parpadeo)

- La plantilla `templates/checkout/form-checkout.php` añade `ccmck-preload` al wrapper
  `<div class="ccmck ccmck-checkout-page">` (línea 40). Así el skeleton se ve **desde el
  primer pintado**, antes de que corra el JS (evita el flash contenido→skeleton→contenido).
- Mientras `.ccmck-preload`: cada `.form-row` muestra una barra shimmer (oculta el control
  real); resumen / pago / envío muestran paneles shimmer; el contenido real queda oculto.
- El JS quita `ccmck-preload` cuando el checkout está listo: en `window.load` **o** el primer
  `updated_checkout` (lo que ocurra primero), con un **timeout de seguridad** (~4 s) para que
  nunca se quede pegado. Al quitarla, transición con **fade** al contenido real.

### Refrescos AJAX — toggle por JS (`ccmckSkeleton`)

- `update_checkout` (inicio del refresco) → añade `ccmck-skel` a **resumen + pago + envío**.
- `updated_checkout` (fin) → quita `ccmck-skel` de esas regiones.
- AJAX de cantidad del carrito (`ccmck_update_cart_item`, en `$.post`) → añade `ccmck-skel`
  al **resumen** de inmediato (antes de que dispare `update_checkout`); se quita en el
  `updated_checkout` subsiguiente.
- Esto **reemplaza** el overlay blanco + spinner por defecto de WC en esas zonas (se
  neutraliza el `blockUI` de WC en esos contenedores vía CSS para que no se solapen).

### Robustez / accesibilidad

- Como el skeleton es una **clase en el contenedor** (no markup dentro), sobrevive a que WC
  reemplace el contenido interno por AJAX.
- Mientras hay skeleton, la región **bloquea interacción** (`pointer-events`) y el overlay es
  `aria-hidden` (no anuncia ruido al lector de pantalla).
- El timeout de seguridad garantiza que la carga inicial nunca deje el checkout tapado aunque
  fallara un evento.

## Alcance / no-alcance

- Archivos: `assets/ccmck-checkout.css`, `assets/ccmck-checkout.js`, y `templates/checkout/
  form-checkout.php` (solo añadir la clase `ccmck-preload` al wrapper).
- No se toca la lógica de pago/envío ni los fragments; solo se superpone/retira el skeleton.
- No se añaden dependencias; shimmer es CSS puro (keyframes + gradiente).

## Verificación

Sin tests JS en el repo → verificación **en vivo por chrome-devtools MCP** sobre
`dev.ccmtiendadelsonido.com/pago/`:
1. Carga inicial: recargar y comprobar que el skeleton se ve desde el inicio y se retira al
   estar listo (sin flash), con fade.
2. AJAX cantidad: pulsar +/− y ver skeleton del resumen mientras recalcula.
3. AJAX dirección/envío: cambiar ciudad/método y ver skeleton de resumen+pago+envío entre
   `update_checkout` y `updated_checkout`.
4. Timeout de seguridad: simular que no llega `updated_checkout` y confirmar que a los ~4 s se
   revela el contenido igual.
Ver [[deploy-dev-server]].
