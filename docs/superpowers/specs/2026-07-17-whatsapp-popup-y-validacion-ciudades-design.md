# Pop-up de WhatsApp post-compra + validación de ciudad/departamento

Fecha: 2026-07-17

## 1. Pop-up de WhatsApp en la página de gracias

- En la página de gracias (pedido no fallido, `whatsapp_enabled` activo), a los **5 s** se abre un `<dialog>` modal con fade-in (patrón ccm-reviews).
- Contenido: `whatsapp_title` + `whatsapp_subtitle` de los ajustes, botón verde "Abrir WhatsApp" (pestaña nueva) y botón de cerrar.
- URL: `https://wa.me/{whatsapp_number}?text=` con el mensaje:
  > Hola, soy {nombre}. Acabo de realizar el pedido #{numero} en ccmtiendadelsonido.com y quiero confirmar mi compra.
  - {nombre} = billing first + last name del pedido; número desde ajustes (573178119077).
- **Una vez por pedido**: clave `ccmck_wa_{order_id}` en `localStorage`; si existe, no se vuelve a abrir.
- Implementación en `CCMCK_Whatsapp` (clase existente vacía): hook `woocommerce_thankyou` prio 5 imprime dialog + JS inline. URL construida en PHP (`build_wa_url()`, método puro testeable). Sin AJAX ni nonce (compatible con page cache).
- CSS en `ccmck-checkout.css` (sección WHATSAPP CTA existente).

## 2. Ciudad y departamento validados contra el catálogo

Origen: caso William — ciudad escrita que no matchea el dropdown del plugin
`wc-departamentos-y-ciudades-colombia` → sin tarifa Coordinadora → pedido salió como pickup forzado.

### Validación servidor (bloqueante)

- Nueva clase `CCMCK_Cities`, hook `woocommerce_after_checkout_validation`:
  - Se salta si el carrito no necesita envío, si el POST de `shipping_method` es pickup **explícito**, o si el catálogo no está disponible (fail-open si el plugin de ciudades se desactiva).
  - `billing_state` debe ser un departamento del catálogo y `billing_city` un código DANE existente dentro de ese departamento. Si no → error en `$errors` ("Selecciona tu ciudad de la lista para calcular el envío.") y el pago no continúa.
- Catálogo: se incluye `assets/places/CO-cities.php` del plugin de ciudades (cache estático, filtro `ccmck_cities_catalog`).
- Lógica pura testeable: `validate_destination(array $catalog, string $state, string $city): string` → `''|'state'|'city'`.

### Pickup no puede ser "la única opción" silenciosa

- En `CCMCK_Shipping::render()`: si el carrito necesita envío, las únicas tarifas reales son pickup y el destino no es cotizable (ciudad/departamento vacíos o fuera del catálogo):
  - Se muestra aviso destacado: "Selecciona tu ciudad y departamento para calcular el envío."
  - La card de pickup se muestra **sin auto-seleccionar** (no postea `shipping_method`, así la validación de ciudad aplica).
  - Se mantienen los placeholders deshabilitados (Coordinadora).
- Métodos puros nuevos: `is_pickup_only(array $methods): bool`, `unselect_all(array $methods): array`.

## Tests

- `WhatsappTest`: build de URL/mensaje (encode, nombre vacío, número desde ajustes).
- `CitiesTest`: validate_destination (ok, estado inválido, ciudad inválida, vacíos).
- `ShippingTest`: is_pickup_only / unselect_all.
