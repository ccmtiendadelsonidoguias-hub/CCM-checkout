# Carrito propio: cajón lateral y página

**Fecha:** 2026-08-15
**Plugin:** `ccm-checkout`
**Etapa del proyecto:** 2 de 5 (área de cliente → **carrito** → favoritos → seguimiento → tarjetas)

## Qué se quiere

Dos pantallas de carrito con diseño propio, a partir de las referencias del dueño:

- **El cajón lateral** que se abre al añadir un producto: artículos con foto, cantidad y eliminar; bloques de "esto se compra junto con lo tuyo"; importes; y el botón de pagar.
- **La página `/carrito/`**: los artículos a la izquierda y una tarjeta de resumen a la derecha, con la caja de cupón y el total.

## Por qué va dentro de `ccm-checkout` y no en un plugin nuevo

Decisión del dueño, y hay dos razones que la sostienen:

1. **El carrito y el checkout son el mismo viaje y comparten piezas reales**: el cotizador de Coordinadora, el catálogo de ciudades con su DANE, los recargos y la presentación de importes. Todo eso ya vive aquí.
2. **Un contrato entre plugins es un coste que ya hemos pagado.** El 14-ago la funcionalidad de rótulos quedó repartida entre `ccm-account` y `ccm-checkout`, y eso obligó a un orden de despliegue estricto: subir el otro primero daba un error fatal a todo cliente con sesión. Con el carrito aquí dentro no hay orden que recordar.

## Lo que ya existe (comprobado, no supuesto)

| Pieza | Estado |
|---|---|
| Carrito lateral actual | Lo da **`woocommerce-side-cart-premium`**, un plugin de pago de terceros. Esto lo reemplaza |
| Página `/carrito/` (26011, "Mi carrito") | **Rota**: tiene un `<form>` de HTML estático pegado donde debería ir `[woocommerce_cart]` |
| `CCMCK_Cart_Ajax` | **Ya construido**: endpoint `ccmck_update_cart_item` que cambia cantidad y elimina, con nonce `ccmck_cart` y registrado también para invitados. Se reutiliza, no se rehace |
| `CCMCK_Templates::OVERRIDES` | Lista blanca sobre `woocommerce_locate_template`. Añadir plantillas es añadir entradas |
| Financiación | Addi y Wompi activos |

## El motor de sugerencias: dos capas

Es la parte con más diseño, y nace de un dato: **de 2.586 pedidos con artículos, 2.182 (84%) son de un solo producto**. Solo 404 tienen dos o más. Sobre 1.002 productos publicados, eso es una base fina — y **ninguno de los 1.002 tiene ventas cruzadas ni upsells configurados**.

Pero donde hay dato, es buenísimo:

| Se compran juntos | Veces |
|---|---|
| Parlante MTE 15 + Tweeter MTE 1201 | 7 |
| Swichera MTE Sw-8 + Cable Canon XLR | 7 |
| Tweeter MTE 1201 + Driver MTE P5 | 6 |
| Tweeter MTE 1201 + Cable Canon XLR | 6 |

No es ruido: es gente **armando una cabina** — parlante de graves, tweeter, driver y el cable que los une. Los clientes ya dicen qué va con qué.

### Capa 1 — lo aprendido

Un cálculo programado recorre los pedidos y guarda, por producto, con qué otros se ha comprado y cuántas veces. Se ejecuta **una vez al día**, no al abrir el carrito: la consulta cruza `wp_woocommerce_order_items` consigo misma y no puede correr en cada visita.

El resultado se guarda ya masticado, listo para leer con una consulta trivial.

**Umbral:** una pareja cuenta a partir de **dos** pedidos distintos. Con uno solo sería anécdota — dos personas que compraron lo mismo el mismo día no es un patrón.

### Capa 2 — las reglas del dueño

Una pantalla de ajustes donde el dueño define reglas por categoría:

> si el carrito lleva algo de **Cabinas Activas** → ofrece **Cables de Audio**

Las añade, las quita y las cambia él. **No van escritas en el código**: eso fue explícito.

### Cómo se combinan

Manda lo aprendido. Si el producto del carrito no tiene ninguna pareja por encima del umbral —que hoy será lo normal—, entra la regla de categoría. Si tampoco hay regla, el bloque no aparece: **una sugerencia inventada es peor que ninguna**.

Filtros que se aplican siempre, venga de donde venga la sugerencia:

- Nunca ofrecer algo que ya está en el carrito.
- Nunca ofrecer algo sin existencias o no comprable.
- Como mucho **dos** sugerencias: el cajón es estrecho y una lista larga se ignora.

Y una propiedad que importa: **la capa 1 mejora sola**. Cada pedido de dos artículos la alimenta. Hoy trabajaría la regla el 90% de las veces; con el tiempo, cada vez menos.

## Arquitectura

Cuatro piezas nuevas, siguiendo la convención `CCMCK_` del plugin:

| Clase | Responsabilidad |
|---|---|
| `CCMCK_Cart_Drawer` | Pinta el cajón y lo mantiene al día. Se apoya en los fragmentos AJAX de WooCommerce para refrescarse solo al añadir un producto |
| `CCMCK_Cart_Suggestions` | Decide qué ofrecer. Las dos capas, el umbral y los filtros |
| `CCMCK_Cart_Pairs` | El cálculo diario y su almacenamiento. Separado del anterior a propósito: uno decide, el otro recolecta |
| `CCMCK_Cart_Settings` | La pantalla de reglas por categoría |

Plantillas nuevas, por la lista blanca que ya existe: `cart/cart.php` y `cart/cart-totals.php`.

**Lo que NO se reimplementa:** los importes, los impuestos, los cupones y las existencias son de WooCommerce. Se leen de `WC()->cart` y se pintan. Recalcular el IVA por nuestra cuenta sería buscarse un problema que nadie ha pedido.

## Cupones

El dueño los quiere. Hoy están **desactivados** en WooCommerce y hay **0 publicados**, así que hay que activarlos.

**Consecuencia que hay que aceptar antes de empezar:** activarlos hace aparecer la caja de cupón **también en el checkout**, que es de este mismo plugin. No es un efecto secundario evitable; es cómo funciona WooCommerce.

## Lo que deliberadamente no hace

- **La barra de envío gratis** de la referencia. Los tres métodos configurados (`wbs`, `wbsng`, `jem_table_rate`) tienen coste y no hay ningún envío gratuito con umbral. Sin umbral, la barra no puede medir nada, y fingirla sería mentir sobre lo que falta para conseguirlo.
- **Suscripciones** ("Subscribe & save"). La tienda no las vende.
- **"Impuesto estimado"** como línea aparte. Aquí el IVA va incluido en el precio.
- **Recomendaciones personalizadas por cliente.** Las sugerencias salen del producto que está en el carrito, no de quién lo mira.

## Coordinación al desplegar

Tres cosas que van **en el mismo cambio**, o la tienda queda peor que antes:

1. Desactivar `woocommerce-side-cart-premium`. Si convive con el nuevo, hay dos cajones.
2. Devolverle a la página 26011 su `[woocommerce_cart]`, quitando el HTML estático.
3. Excluir `/carrito/` de la caché de LiteSpeed (`cache-exc` está vacío hoy). Un carrito cacheado enseña el de otro cliente.

El punto 3 no es teórico: hoy `/carrito/` se sirve cacheada y pública.

## Pruebas

Las partes que deciden, que son puras y se prueban sin WordPress:

- Qué sugerencia gana entre lo aprendido y la regla.
- El umbral de dos pedidos: una pareja de un solo pedido no entra.
- Los filtros: lo que ya está en el carrito, lo agotado, el tope de dos.
- Que sin dato y sin regla no se devuelve nada, en vez de rellenar con lo que sea.
- El recorte de las reglas del ajuste: una categoría que ya no existe no puede romper el carrito.

El cálculo diario y el pintado necesitan WordPress; se verifican en el servidor de desarrollo, y **por los dos caminos** —página completa y AJAX—, que es la lección que este proyecto ya pagó tres veces.

## Riesgos

1. **La base de aprendizaje es fina.** 404 pedidos útiles. Al principio casi todo lo resolverá la regla de categoría, así que esa pantalla de ajustes tiene que ser cómoda de verdad: es la que va a trabajar.
2. **Reemplazar un plugin de pago que hoy funciona.** El cajón actual lleva tiempo en producción. Conviene tener el nuevo verificado en desarrollo antes de apagar el viejo, y saber cómo volver atrás.
3. **La página 26011 tiene HTML pegado a mano.** Alguien lo puso ahí; conviene entender por qué antes de quitarlo, no vaya a estar tapando otra cosa.
