# Spec: Errores de WooCommerce inline por campo (estilo Shopify)

**Fecha:** 2026-06-12
**Plugin:** ccm-checkout (mu-plugin)
**Estado:** Aprobado (diseño)

## Contexto

El checkout ya muestra inline los errores de **validación cliente** (campos obligatorios
vacíos): borde rojo + mensaje debajo del campo (`ccmck-checkout.js`, función
`ccmckValidateRequired`; CSS `.ccmck-invalid` / `.ccmck-field-error`).

Pero los errores que llegan del **servidor de WooCommerce** (evento `checkout_error` vía
AJAX: formato de email/teléfono/documento, "Población es un campo requerido", fallo de
pasarela, etc.) hoy se agrupan en un **banner rosa** (`.woocommerce-error`) que el JS solo
reubica encima del botón de pago (`ccmck-notice-relocated`). Es un bloque con todos los
errores juntos.

Referencia aprobada por el usuario (Shopify, newconcept.co): cada error va **inline debajo
de su campo**, con el campo en borde rojo, **sin banner**. Verificado en vivo por
chrome-devtools MCP: color `#dd1d1d`, 14px, mensaje bajo el campo + borde rojo del campo.

**Objetivo:** repartir los errores server-side de WC inline bajo cada campo (reusando el
estilo inline que ya existe) y dejar solo los no-mapeables en un aviso compacto junto al
botón.

## Decisiones de brainstorming (2026-06-12)

- **Errores sin campo asociable** (pasarela de pago, "no hay método de envío", genéricos):
  van a un **aviso compacto encima del botón de pago** (reusa `ccmck-notice-relocated`,
  pero solo con el subconjunto sobrante). Si todos se mapearon, no aparece banner.
- **Mapeo error→campo:** **por reconocimiento del nombre del campo en el texto** del
  mensaje. WooCommerce envuelve el nombre del campo en `<strong>` dentro de cada `<li>`
  (p. ej. `<strong>Población</strong> es un campo requerido`). Se casa ese texto contra los
  labels de los `form-row`; con aliases para frases que no usan el label exacto.

## Comportamiento esperado

Al disparar el evento `checkout_error`:

1. **Indexar campos:** recorrer `.checkout-main .form-row`, leer el texto del label
   flotado (ya limpio por `ccmckFloatLabels`), normalizarlo (minúsculas, sin acentos, sin
   `*`) → mapa `labelNormalizado → $row`. Incluir aliases conocidos del proyecto:
   - `correo`, `email`, `e-mail` → `billing_email`
   - `teléfono`, `telefono`, `móvil`, `movil`, `celular` → `billing_phone`
   - `documento`, `número de documento` → `billing_document_number`
   - `tipo de documento` → `billing_document_type`
   - `población`, `poblacion`, `ciudad` → `billing_city`
   - `departamento`, `provincia`, `estado` → `billing_state`
   - `dirección`, `direccion`, `calle` → `billing_address_1`
   - `nombre` → `billing_first_name`; `apellido(s)` → `billing_last_name`
   - `cédula`, `cedula`, `nit`, `código postal` → `billing_postcode`

   El mapa se construye dinámicamente desde los labels presentes; los aliases solo añaden
   sinónimos hacia el `id` del campo. (Tolerante a que un campo no exista en el DOM.)

2. **Repartir errores:** para cada `<li>` del/los `.woocommerce-error`:
   1. Si tiene `<strong>`, casar su texto (normalizado) contra el índice.
   2. Si no casa, buscar en el texto completo del `<li>` cualquier label/alias conocido
      como subcadena (normalizada). Primer match gana.
   3. Si casa con una fila → error mapeado a esa fila. Si no → error "suelto".

3. **Render:**
   - **Mapeado:** añadir `.ccmck-invalid` a la fila (borde rojo) y pintar
     `.ccmck-field-error` con el texto del error debajo (reusa `ccmckSetRowError`). Si una
     fila recibe varios errores, se muestra el primero.
   - **Sueltos:** reconstruir un `.woocommerce-error` compacto solo con esos `<li>` y
     reubicarlo encima del botón (lógica de `ccmck-notice-relocated` ya existente). Si no
     hay sueltos, eliminar/ocultar el grupo de notices para que no quede banner vacío.
   - **Scroll** al primer campo con error (o al aviso suelto si no hubo ninguno mapeado),
     igual que la validación cliente.

4. **Limpieza:** el handler existente `input change` sobre `.ccmck-invalid` ya borra el
   error al editar. Se mantiene; cubre también los errores de formato (campo no vacío que el
   usuario corrige) — al primer cambio se limpia el inline y WC re-valida en el siguiente
   submit.

## Alcance / no-alcance

- **Solo** `assets/ccmck-checkout.js` (+ ajuste menor de CSS si hace falta para el aviso
  compacto). **Sin PHP.**
- No se toca la validación cliente de campos vacíos (ya funciona).
- No se toca el estilo base `.woocommerce-error` / `.woocommerce-message`.
- El mapeo es heurístico por texto en español; un mensaje totalmente inesperado cae al
  aviso suelto (nunca se pierde un error).

## Verificación

No hay tests JS en el repo. Verificación **en vivo por chrome-devtools MCP**: inyectar
errores reales de WC (required, formato, pasarela, mezcla) sobre el checkout de dev y
comprobar que cada uno cae en su campo inline y los sueltos en el aviso compacto, antes de
que el usuario despliegue por File Manager. Ver [[deploy-dev-server]].
