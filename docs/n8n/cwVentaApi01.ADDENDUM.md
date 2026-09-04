# cwVentaApi01 — addendum 2026-09-03 (ventas de asesores)

44 → 46 nodos. Desplegado con GO del dueño el 2026-09-03. Backup: `/root/backups/cwVentaApi01_PRE_ASESORES_20260903.json`.

- **`Agente → vendedor`** (nuevo, entre `WH Venta` y `¿Prefill?`): único mapa correo de Chatwoot → vendedor/centro de costo de Alegra. Emite `agente_resuelto`. Desconocido → `vendedor_id: null`, `conocido: false`.
- **`Prefill build`**: devuelve `vendedor_id/nombre`, `ccosto_id/nombre`, `agente_email` para precargar el popup.
- **`Order payload`** (v14): sin default al bot. `es_bot` > popup > agente. Sin vendedor → `sin_vendedor`. Metas nuevas `_ccm_canal_venta` (`bot` si vendedor 9, si no `asesor`) y `_ccm_agente_chatwoot`. `_ccm_origen` intacto.
- **`¿Payload OK?`** (nuevo): corta el camino a `WC crear pedido` cuando `Order payload` devuelve `error`. Antes, el error viajaba como `order_body || {}` y **creaba un pedido vacío en processing** (#34114, #34158, #34449).
- **`Resultado`**: en error devuelve `conv` (la nota privada iba a `/conversations/undefined`); en éxito avisa si el agente no está en el mapa.

Smokes tras el despliegue: `prefill` con el correo de Heider devolvió vendedor 3 / centro 3; `crear` sin vendedor devolvió `sin_vendedor` con `conv`, dejó la nota privada en la conversación y **no creó ningún pedido**.

Arnés: `docs/n8n/harness/ventas_asesores_api.js` (falla contra el export anterior — 10 fallos —, 15/15 verde contra el nuevo). Parche: `docs/n8n/patches/2026-09-03-ventas-asesores-api.py`.

Hasta que se despliegue el popup nuevo (`cwVentaPage01`), el popup viejo sigue mandando `vendedor_alegra_id: "9"` (su `selected`), así que las ventas siguen atribuidas al bot; lo que ya está activo es el guard anti-fantasma.

## Notas de la revisión final (2026-09-03)

- `vendedores_en_rango()` del spec no se escribió: `resumen_por_vendedor()` alimenta el select y la tabla (una consulta en vez de dos).
- `Agente → vendedor` está en el camino de las 5 acciones sin `onError`: es Code puro y total (`$json.body || {}`, no lanza), excepción prevista en el plan.
- El fallback de centro de costo en `Order payload` (`id === 9 ? 10 : 3`) duplica el mapa del nodo — consolidar en el próximo despliegue de la API.

## Cambio de regla — manda el ASIGNADO (2026-09-03, mismo día, con GO)

El vendedor lo decide **a quién está asignada la conversación en Chatwoot**, no quién abre el popup:

| Asignada a | Vendedor | Centro |
|---|---|---|
| Heider / Farid | 3 / 4 | Ventas Virtuales Personas CCM |
| Camilo | 9 Bot CCM IA | IA CCM |
| Nadie (la lleva el bot) | 9 Bot CCM IA | IA CCM |

- El asignado sale de `meta.assignee.email` del GET que la rama `prefill` ya hacía a Chatwoot (forma verificada en vivo). Decisión de servidor: el iframe no puede falsearla.
- `Agente → vendedor`: el `MAPA` queda con los **2 asesores**; Camilo pasa a `AGENTES_BOT` (sigue siendo conocido para no disparar el aviso de «agente sin vendedor»).
- `Prefill build` devuelve `es_bot` y `asignado_email`; el popup refleja la casilla con `aplicarDelServidor()`.
- `Order payload`: sin vendedor del popup, el respaldo es el **bot** (antes: el agente que apretó el botón, que le habría cargado ventas del bot a Heider). La rama `sin_vendedor` quedó inalcanzable y se eliminó.
- `_ccm_agente_chatwoot` sigue guardando **quién apretó el botón** — así se puede auditar después la diferencia entre quien vende y quien registra.
- El desplegable manda si el asesor lo cambia; la casilla «Venta cerrada por el bot» gana sobre todo.

Arneses: `docs/n8n/harness/asignado_manda.js` (13 fallos contra el export anterior, 12/12 con el cambio) y `ventas_asesores_popup.html` (14/14 en navegador). Tres aserciones de `ventas_asesores_api.js` se actualizaron porque codificaban la regla anterior; ese arnés sigue fallando 4 veces contra el export sin este cambio.

Smoke en producción tras desplegar (popup abierto por Heider en las tres):
`conv 22663 (Camilo) → 9/IA CCM` · `conv 53964 (Farid) → 4/Ventas Virtuales Personas` · `conv 35979 (sin asignar) → 9/IA CCM`.

Backups: `/root/backups/cwVentaApi01_PRE_ASIGNADO_20260903.json`, `cwVentaPage01_PRE_ASIGNADO_20260903.json`.
