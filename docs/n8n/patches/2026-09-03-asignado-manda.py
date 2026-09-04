# -*- coding: utf-8 -*-
"""cwVentaApi01: el VENDEDOR lo decide a quien esta ASIGNADA la conversacion en Chatwoot,
no quien abre el popup (decision del dueno 2026-09-03).

  asignada a Heider / Farid  -> ese asesor (canal 'asesor')
  asignada a Camilo o A NADIE (la lleva el bot) -> Bot CCM IA 9 / IA CCM 10 (canal 'bot')

El asignado llega en `meta.assignee.email` del GET que la rama `prefill` ya hace a Chatwoot
(verificado en vivo). `_ccm_agente_chatwoot` sigue guardando QUIEN apreto el boton, para poder
auditar despues la diferencia entre quien vende y quien registra.

Uso: python3 docs/n8n/patches/2026-09-03-asignado-manda.py ENTRADA.json SALIDA.json
Cada ancla debe aparecer EXACTAMENTE una vez; si no, aborta sin escribir.
"""
import json, sys

src, dst = sys.argv[1], sys.argv[2]
raw = json.load(open(src))
w = raw[0] if isinstance(raw, list) else raw

def node(name):
    for n in w['nodes']:
        if n['name'] == name:
            return n
    raise SystemExit('no existe nodo ' + name)

def sub(code, old, new, label):
    if code.count(old) != 1:
        raise SystemExit('ANCLA no unica (%d) en %s' % (code.count(old), label))
    return code.replace(old, new)

if 'asignado_manda' in json.dumps(w, ensure_ascii=False):
    raise SystemExit('ya parchado')

# ---------- 1. Agente → vendedor: MAPA = solo los 2 asesores; expone BOT ----------
n = node('Agente → vendedor')
c = n['parameters']['jsCode']
c = sub(c,
"  'gerencia@ccmtiendadelsonido.com': { vendedor_id: 6, vendedor_nombre: 'Camilo Caraballo Avendaño' },\n};",
"};\n"
"// Los chats de Camilo (el dueno) se facturan por el BOT, igual que los que no tiene\n"
"// nadie asignados: decision del dueno 2026-09-03. Va aparte del MAPA para que\n"
"// `conocido` siga siendo true y Resultado no avise de 'agente sin vendedor'.\n"
"const AGENTES_BOT = ['gerencia@ccmtiendadelsonido.com'];",
'Agente → vendedor/Camilo fuera del mapa')
c = sub(c,
"const hit = MAPA[email] || null;",
"const hit = MAPA[email] || null;\nconst esAgenteBot = AGENTES_BOT.indexOf(email) !== -1;",
'Agente → vendedor/esAgenteBot')
c = sub(c,
"conocido: !!hit },",
"conocido: !!hit || esAgenteBot },",
'Agente → vendedor/conocido')
c = sub(c,
"const agente_resuelto = Object.assign({ email, name: String(ag.name || ''), conocido: !!hit || esAgenteBot },",
"// 2026-09-03 asignado_manda: el mapa y el bot viajan en el item para que los use quien\n"
"// SI conoce la conversacion (Prefill build / Order payload). Aqui no se sabe a quien\n"
"// esta asignada: este nodo corre justo tras el webhook, antes de hablar con Chatwoot.\n"
"const BOT = { vendedor_id: 9, vendedor_nombre: 'Bot CCM IA', ccosto_id: 10, ccosto_nombre: 'IA CCM' };\n"
"const agente_resuelto = Object.assign({ email, name: String(ag.name || ''), conocido: !!hit || esAgenteBot, mapa: MAPA, ccosto: CCOSTO, bot: BOT },",
'Agente → vendedor/expone mapa')
n['parameters']['jsCode'] = c

# ---------- 2. Prefill build: resuelve por el ASIGNADO ----------
n = node('Prefill build')
c = n['parameters']['jsCode']
c = sub(c,
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}",
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}\n"
"// asignado_manda: el vendedor sale de a QUIEN esta asignada la conversacion.\n"
"// Sin asignar (la lleva el bot) o asignada a Camilo -> Bot CCM IA, como antes.\n"
"let asignado = '';\n"
"try { asignado = String((((($('GET conv venta').first().json || {}).meta || {}).assignee) || {}).email || '').trim().toLowerCase(); } catch (e) {}\n"
"const hitAsig = (ag.mapa || {})[asignado] || null;\n"
"const vend = hitAsig ? Object.assign({}, hitAsig, ag.ccosto || {}) : Object.assign({}, ag.bot || {});\n"
"const esBot = !hitAsig;",
'Prefill build/resolver asignado')
c = sub(c,
"  vendedor_id: ag.vendedor_id || null, vendedor_nombre: ag.vendedor_nombre || '', ccosto_id: ag.ccosto_id || null, ccosto_nombre: ag.ccosto_nombre || '', agente_email: ag.email || '' } }];",
"  vendedor_id: vend.vendedor_id || null, vendedor_nombre: vend.vendedor_nombre || '',\n"
"  ccosto_id: vend.ccosto_id || null, ccosto_nombre: vend.ccosto_nombre || '',\n"
"  es_bot: esBot, asignado_email: asignado, agente_email: ag.email || '' } }];",
'Prefill build/return')
n['parameters']['jsCode'] = c

# ---------- 3. Order payload: el respaldo ya no es el agente, es el BOT ----------
n = node('Order payload')
c = n['parameters']['jsCode']
c = sub(c,
"  const id = Number(f.vendedor_alegra_id) || Number(ag.vendedor_id) || 0;\n"
"  if (!id) return null;\n"
"  const delPopup = Number(f.vendedor_alegra_id) === id;\n",
"  // asignado_manda: si el popup no manda vendedor, el respaldo es el BOT (no el agente que\n"
"  // apreto el boton): sin un asesor explicito, la venta es del bot por definicion del dueno.\n"
"  const id = Number(f.vendedor_alegra_id) || Number((ag.bot || {}).vendedor_id) || 9;\n"
"  const delPopup = Number(f.vendedor_alegra_id) === id;\n",
'Order payload/respaldo bot')
c = sub(c,
"    nombre: delPopup ? String(f.vendedor_nombre || '') : String(ag.vendedor_nombre || ''),\n"
"    ccosto_id: Number(f.centro_costo_id) || Number(ag.ccosto_id) || (id === 9 ? 10 : 3),\n"
"    ccosto_nombre: String(f.centro_costo_nombre || ag.ccosto_nombre || (id === 9 ? 'IA CCM' : 'Ventas Virtuales Personas CCM')),\n",
"    nombre: delPopup ? String(f.vendedor_nombre || '') : String((ag.bot || {}).vendedor_nombre || 'Bot CCM IA'),\n"
"    ccosto_id: Number(f.centro_costo_id) || Number((ag.ccosto || {}).ccosto_id ? (id === 9 ? 10 : ag.ccosto.ccosto_id) : 0) || (id === 9 ? 10 : 3),\n"
"    ccosto_nombre: String(f.centro_costo_nombre || (id === 9 ? 'IA CCM' : ((ag.ccosto || {}).ccosto_nombre || 'Ventas Virtuales Personas CCM'))),\n",
'Order payload/nombres')
c = sub(c,
"if (!vend) return { error: 'sin_vendedor: elige el vendedor en el popup', sin_vendedor: true };\n",
"",
'Order payload/quitar sin_vendedor muerto')
n['parameters']['jsCode'] = c

json.dump(w, open(dst, 'w'), ensure_ascii=False, indent=1)
print('OK nodos', len(w['nodes']))
