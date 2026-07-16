# Botón "Generar guía Coordinadora" en el pedido — Diseño (express)

## Contexto y objetivo

La generación automática de guías (spec 2026-07-15) excluye a propósito los pedidos
de **Recogida local**. Pero hay clientes que marcan recogida **por error** y sí
necesitan envío: hoy esa guía tocaría hacerla a mano en el panel de Coordinadora.

**Objetivo:** botón **"Generar guía Coordinadora"** en el pedido del admin para
generar la guía manualmente con el mismo motor (mismas cajas, observaciones,
metas, notas y aviso WhatsApp).

## Decisiones (aprobadas)

- El botón aparece en **cualquier pedido sin guía** (rescata recogidas por error
  Y fallos de la automática ya corregidos). Si el pedido ya tiene guía → no hay
  botón (imposible duplicar).
- La generación manual **también envía el WhatsApp** al cliente.
- El camino manual **salta** la exclusión de pickup y el toggle `guias_enabled`
  (acción deliberada del admin), pero **mantiene**: guía existente, lock,
  credenciales, productos con medidas y ciudad con DANE.
- Errores en pantalla con `wp_die` + back link y guía de corrección (ej. editar
  la Población con su código DANE) — no nota enterrada.

## Componentes

1. **`should_generate( $ctx )`** gana el flag `manual` (PURO): con `manual:true`
   no evalúa `enabled` ni `shipping_ids`.
2. **Refactor**: el núcleo de `on_processing` se extrae a
   `generate_for_order( $order ): {ok, error}` (items → DANE → pack →
   generarGuia → metas + notas + webhook). `on_processing` = guards automáticos +
   lock + nota de fallo; `ajax_generate` = guards manuales + lock + `wp_die` de
   fallo / redirect al pedido en éxito.
3. **`generate_button_markup( $url )`** (PURO): botón con `confirm()` +
   descripción. `render_admin` lo pinta cuando no hay guía; con guía pinta la
   caja existente.
4. **`ajax_generate`**: acción `ccmck_guia_generate`, nonce + `edit_shop_orders`,
   redirect a `get_edit_order_url()` en éxito.

## Testing

`GuiasTest`: `manual` salta pickup/enabled pero bloquea guía existente/lock/
credenciales; markup del botón (URL, texto, confirm; '' si URL vacía). Núcleo ya
cubierto por la suite (160 tests). Stub `esc_js` añadido al bootstrap.

## Fuera de alcance

Anular/regenerar guía existente; botón masivo en el listado de pedidos; edición
de ciudad inline (se edita en el pedido y se reintenta).
