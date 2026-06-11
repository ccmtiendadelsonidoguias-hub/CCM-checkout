# Spec: Recogida local (pickup en tienda) seleccionable sin dirección

**Fecha:** 2026-06-11
**Plugin:** ccm-checkout (mu-plugin)
**Estado:** Aprobado (diseño)

## Contexto

Tras añadir el estado *disabled* de Métodos de envío
(`2026-06-11-shipping-disabled-placeholder-design.md`), el checkout muestra
`Coordinadora` y `Recogida local` como cards grises cuando no hay dirección cotizable.
El usuario quiere poder **elegir Recogida local enseguida**, sin tener que escribir una
dirección (es recoger en tienda).

Hallazgo verificado en vivo (Store API): sin ciudad WooCommerce devuelve **cero** tarifas;
e incluso con Barranquilla solo aparece **Coordinadora** — el Local Pickup configurado en las
Zonas **no se ofrece** para las direcciones probadas. Por eso "Recogida local" hoy es solo una
etiqueta de placeholder, no una tarifa real seleccionable.

## Decisiones de brainstorming (2026-06-11)

- **Comportamiento pickup:** elegir Recogida local ⇒ **no se pide dirección de entrega**. Los
  campos de dirección dejan de ser obligatorios y el pedido se completa sin ellos.
- **Fuente de la tarifa (Opción A):** la **inyecta el propio plugin** vía
  `woocommerce_package_rates` (tarifa gratis siempre disponible, id propio estable). No se
  depende de la config de Zonas de WC.

## Resultado esperado

Sección "Métodos de envío", estados:

```
Sin dirección  → [Recogida local  $0]  ← REAL, seleccionable
                 [Coordinadora        ] ← disabled (gris, necesita dirección)
Con dirección  → [Coordinadora $19.350] ← REAL, seleccionable
                 [Recogida local  $0]   ← REAL, seleccionable
```

Al **elegir Recogida local**, los campos de dirección (dirección, depto/región, ciudad,
código postal) dejan de ser obligatorios; el pedido se puede colocar solo con contacto +
datos personales. Al volver a un método de envío, vuelven a ser obligatorios.

## Arquitectura

### 1. Tarifa de pickup siempre disponible — `CCMCK_Pickup` (clase nueva)
**Archivo:** `includes/class-ccmck-pickup.php`

- `const RATE_ID = 'ccmck_local_pickup'`, `const LABEL = 'Recogida local'`.
- `init()`: `add_filter( 'woocommerce_package_rates', [..., 'inject'], 10, 2 )`.
- `inject( array $rates, array $package ): array` — añade una tarifa libre de coste con
  `RATE_ID` y `LABEL` (coste `0`, `taxes` vacío) si no está ya presente. Devuelve `$rates`.
  Construye la tarifa con `new WC_Shipping_Rate( RATE_ID, LABEL, 0, array(), 'local_pickup' )`
  (o equivalente). Idempotente.
- `is_pickup_rate( string $rate_id ): bool` — PURO: `RATE_ID` coincide con el id elegido
  (comparando también el id base por si WC le antepone instancia). Testeable.

### 2. Render mixto (reales seleccionables + placeholders disabled de los faltantes)
**Archivo:** `includes/class-ccmck-shipping.php` (editado)

- Nuevo método PURO `missing_placeholder_labels( array $real_labels, array $placeholder_labels ): array`
  — devuelve los labels de la lista fija que **no** están entre las tarifas reales (case-insensitive,
  trim). Es lo que se pintará disabled.
- `render()` cambia a: render de cards reales (`render_cards`) **+** append de
  `render_placeholder_cards( missing_placeholder_labels( labels_de_rates_reales, placeholder_labels() ) )`.
  Si no hay ni reales ni placeholders → aviso fallback actual.
- `render_cards` / `render_placeholder_cards` se mantienen como están (la nota
  "Ingresa tu dirección…" solo se pinta si hay placeholders).

### 3. Dirección opcional al elegir pickup
**Archivo:** `includes/class-ccmck-pickup.php` (mismos hooks de la clase)

- **Servidor (autoritativo):** `woocommerce_after_checkout_validation( $data, $errors )` — si el
  método elegido (`$data['shipping_method']`) es pickup (`is_pickup_rate`), eliminar de `$errors`
  los avisos de los campos de dirección de entrega
  (`billing_address_1`, `billing_city`, `billing_state`, `billing_postcode` y los del plugin de
  departamentos/ciudades si aplica). Lista de campos en una constante para testear su filtrado.
- `filter_address_errors( $errors, array $fields ): object` — método auxiliar PURO sobre un stub de
  `WC_Errors`-like para verificar que quita solo esos códigos. (Si el stub resulta complejo, se
  testea la lógica de "qué claves quitar" con un helper de arrays.)
- **Cliente (UX):** JS en `assets/ccmck-checkout.js` — al cambiar `input[name^=shipping_method]`:
  si el seleccionado es pickup, a las `.form-row` de los campos de dirección les quita
  `validate-required` y oculta el asterisco (`.required`/`.optional`); si no, restaura. No envía
  nada; solo cosmético + evita el bloqueo de validación cliente de `checkout.js`.

### 4. Boot y assets
- `ccm-checkout.php`: `require` + `CCMCK_Pickup::init()` en `ccmck_boot`.
- CSS menor si hace falta para la card de pickup (reusa el estilo de cards reales).

## Archivos

| Acción | Archivo |
|--------|---------|
| Nuevo | `includes/class-ccmck-pickup.php` |
| Editar | `includes/class-ccmck-shipping.php` (render mixto + `missing_placeholder_labels`) |
| Editar | `ccm-checkout.php` (boot) |
| Editar | `assets/ccmck-checkout.js` (toggle de obligatoriedad) |
| Editar | `assets/ccmck-checkout.css` (si hace falta) |
| Editar | `tests/ShippingTest.php` + nuevo `tests/PickupTest.php` |

## Tests (PHPUnit PHAR + stubs)

- `CCMCK_Pickup::inject` añade la tarifa pickup (id/label/coste 0) e idempotente (no duplica).
- `CCMCK_Pickup::is_pickup_rate` reconoce el id (con y sin prefijo de instancia).
- `CCMCK_Shipping::missing_placeholder_labels`: quita los ya presentes (case-insensitive),
  conserva los faltantes, listas vacías.
- Filtrado de errores de dirección: dada una lista de campos, quita solo esos códigos.
- Regresión: `render_cards`, `render_placeholder_cards`, `placeholder_labels` intactos.

## Fuera de alcance (YAGNI)
- Selector de "tienda" concreta / múltiples puntos de recogida.
- Cambiar la config de Zonas de WooCommerce.
- Persistir preferencia de pickup entre sesiones.
- Cobro/impuesto por pickup (siempre gratis).

## Verificación (en vivo, chrome-devtools MCP)
Despliegue por File Manager (memoria `deploy-dev-server`) + **purgar OPcache**. Tras subir:

1. `/pago/` sin dirección → `Recogida local` aparece como card **seleccionable** ($0) y
   `Coordinadora` como card **disabled**.
2. Seleccionar Recogida local → los campos de dirección dejan de marcar obligatorio; se puede
   pulsar "Realizar pedido"/colocar la orden sin dirección (probar el submit, confirmar que el
   servidor no exige dirección).
3. Poner ciudad (Barranquilla) → aparece `Coordinadora $19.350` seleccionable junto a pickup;
   al elegir Coordinadora los campos vuelven a ser obligatorios.
4. Confirmar que el total cuadra (pickup = sin coste de envío).

## Notas de despliegue
- CSS/JS auto-bustan por `?ver=<filemtime>` (force_version activo).
- Los PHP requieren **purgar OPcache**.
