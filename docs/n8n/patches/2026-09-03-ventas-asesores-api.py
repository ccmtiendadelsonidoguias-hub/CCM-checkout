# -*- coding: utf-8 -*-
"""Cirugia sobre el export de cwVentaApi01 (ventas de asesores, 2026-09-03).
Uso: python3 docs/n8n/patches/2026-09-03-ventas-asesores-api.py /tmp/cwVentaApi01.json /tmp/cwVentaApi01.new.json
Cada ancla debe aparecer EXACTAMENTE una vez; si no, aborta sin escribir."""
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

if any(n['name'] in ('Agente → vendedor', '¿Payload OK?') for n in w['nodes']):
    raise SystemExit('ya parchado')

# ---------- 1. nodo nuevo: Agente → vendedor ----------
AGENTE_CODE = r"""// Unico mapa agente de Chatwoot -> vendedor / centro de costo de Alegra (2026-09-03).
// Corre en TODAS las acciones; Prefill build y Order payload lo leen por nombre.
// OJO: el vendedor 5 es OTRO Camilo. Camilo Caraballo Avendaño = 6.
const MAPA = {
  'heider@ccmtiendadelsonido.com':   { vendedor_id: 3, vendedor_nombre: 'Heider Arrieta' },
  'farid@ccmtiendadelsonido.com':    { vendedor_id: 4, vendedor_nombre: 'Farid Sanchez' },
  'gerencia@ccmtiendadelsonido.com': { vendedor_id: 6, vendedor_nombre: 'Camilo Caraballo Avendaño' },
};
const CCOSTO = { ccosto_id: 3, ccosto_nombre: 'Ventas Virtuales Personas CCM' };
const b = $json.body || {};
const ag = b.agente || {};
const email = String(ag.email || '').trim().toLowerCase();
const hit = MAPA[email] || null;
const agente_resuelto = Object.assign({ email, name: String(ag.name || ''), conocido: !!hit },
  hit ? Object.assign({}, hit, CCOSTO) : { vendedor_id: null, vendedor_nombre: '', ccosto_id: null, ccosto_nombre: '' });
// el item del webhook sigue intacto: los IF de accion leen $json.body.action como siempre
return [{ json: Object.assign({}, $json, { agente_resuelto }) }];"""

wh = node('WH Venta'); pf = node('¿Prefill?')
w['nodes'].append({
    "parameters": {"jsCode": AGENTE_CODE},
    "id": "d1a2b3c4-0001-4000-8000-000000000001", "name": "Agente → vendedor",
    "type": "n8n-nodes-base.code", "typeVersion": 2,
    "position": [wh['position'][0] + 180, wh['position'][1]]})
assert [c['node'] for c in w['connections']['WH Venta']['main'][0]] == ['¿Prefill?']
w['connections']['WH Venta']['main'][0] = [{"node": "Agente → vendedor", "type": "main", "index": 0}]
w['connections']['Agente → vendedor'] = {"main": [[{"node": "¿Prefill?", "type": "main", "index": 0}]]}

# ---------- 2. Prefill build devuelve el vendedor del agente ----------
n = node('Prefill build'); c = n['parameters']['jsCode']
c = sub(c,
"return [{ json: { ok: true, cliente: { nombre: base.nombre, apellido: base.apellido, telefono: base.telefono, email: base.email, ciudad: base.ciudad, documento: '', direccion: '' }, items: out, monto } }];",
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}\n"
"return [{ json: { ok: true, cliente: { nombre: base.nombre, apellido: base.apellido, telefono: base.telefono, email: base.email, ciudad: base.ciudad, documento: '', direccion: '' }, items: out, monto,\n"
"  vendedor_id: ag.vendedor_id || null, vendedor_nombre: ag.vendedor_nombre || '', ccosto_id: ag.ccosto_id || null, ccosto_nombre: ag.ccosto_nombre || '', agente_email: ag.email || '' } }];",
'Prefill build/return')
n['parameters']['jsCode'] = c

# ---------- 3. Order payload: sin default al bot, canal + agente ----------
n = node('Order payload'); c = n['parameters']['jsCode']
c = sub(c, "const line_items = cp.items.map(i => {",
"// v14 (2026-09-03): vendedor = casilla es_bot > lo elegido en el popup > agente de Chatwoot. SIN default al bot.\n"
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}\n"
"const vend = (function () {\n"
"  if (f.es_bot === true) return { id: 9, nombre: 'Bot CCM IA', ccosto_id: 10, ccosto_nombre: 'IA CCM', agente_email: String(ag.email || '') };\n"
"  const id = Number(f.vendedor_alegra_id) || Number(ag.vendedor_id) || 0;\n"
"  if (!id) return null;\n"
"  const delPopup = Number(f.vendedor_alegra_id) === id;\n"
"  return { id,\n"
"    nombre: delPopup ? String(f.vendedor_nombre || '') : String(ag.vendedor_nombre || ''),\n"
"    ccosto_id: Number(f.centro_costo_id) || Number(ag.ccosto_id) || (id === 9 ? 10 : 3),\n"
"    ccosto_nombre: String(f.centro_costo_nombre || ag.ccosto_nombre || (id === 9 ? 'IA CCM' : 'Ventas Virtuales Personas CCM')),\n"
"    agente_email: String(ag.email || '') };\n"
"})();\n"
"if (!vend) return { error: 'sin_vendedor: elige el vendedor en el popup', sin_vendedor: true };\n"
"const line_items = cp.items.map(i => {", 'Order payload/vend')
c = sub(c,
"    { key: '_ccm_alegra_seller_id', value: String(f.vendedor_alegra_id || '9') },\n"
"    { key: '_ccm_alegra_seller_nombre', value: String(f.vendedor_nombre || 'Bot CCM IA') },\n"
"    { key: '_ccm_alegra_cost_center_id', value: String(f.centro_costo_id || '10') },\n"
"    { key: '_ccm_alegra_cost_center_nombre', value: String(f.centro_costo_nombre || 'IA CCM') },",
"    { key: '_ccm_alegra_seller_id', value: String(vend.id) },\n"
"    { key: '_ccm_alegra_seller_nombre', value: vend.nombre },\n"
"    { key: '_ccm_alegra_cost_center_id', value: String(vend.ccosto_id) },\n"
"    { key: '_ccm_alegra_cost_center_nombre', value: vend.ccosto_nombre },\n"
"    { key: '_ccm_canal_venta', value: vend.id === 9 ? 'bot' : 'asesor' },\n"
"    { key: '_ccm_agente_chatwoot', value: vend.agente_email },", 'Order payload/metas')
n['parameters']['jsCode'] = c

# ---------- 4. Resultado: conv en la rama de error + aviso de agente sin mapa ----------
n = node('Resultado'); c = n['parameters']['jsCode']
c = sub(c, "if (op.error) return { ok: false, error: op.error };",
"if (op.error) return { ok: false, error: op.error, conv: $('Crear parse').first().json.conv };", 'Resultado/error conv')
c = sub(c, "return { ok: true, order_id: o.id, order_number: num, total_wc: o.total, conv: op.conv, entrega, msg_cliente,",
"let ag = {}; try { ag = $('Agente → vendedor').first().json.agente_resuelto || {}; } catch (e) {}\n"
"if (ag.email && ag.conocido === false) nota += ' ⚠️ Agente ' + ag.email + ' sin vendedor en el mapa (nodo Agente → vendedor de cwVentaApi01): la venta se atribuyo a lo elegido en el popup.';\n"
"return { ok: true, order_id: o.id, order_number: num, total_wc: o.total, conv: op.conv, entrega, msg_cliente,", 'Resultado/aviso agente')
n['parameters']['jsCode'] = c

# ---------- 5. IF ¿Payload OK? entre Order payload y WC crear pedido ----------
op_node = node('Order payload')
w['nodes'].append({
    "parameters": {"conditions": {"options": {"caseSensitive": True, "leftValue": "", "typeValidation": "loose", "version": 2},
        "conditions": [{"id": "pok1", "leftValue": "={{ !$json.error }}", "rightValue": "true",
            "operator": {"type": "boolean", "operation": "true", "singleValue": True}}], "combinator": "and"}, "options": {}},
    "id": "d1a2b3c4-0002-4000-8000-000000000002", "name": "¿Payload OK?",
    "type": "n8n-nodes-base.if", "typeVersion": 2.2,
    "position": [op_node['position'][0] + 160, op_node['position'][1]]})
assert [c['node'] for c in w['connections']['Order payload']['main'][0]] == ['WC crear pedido']
w['connections']['Order payload']['main'][0] = [{"node": "¿Payload OK?", "type": "main", "index": 0}]
w['connections']['¿Payload OK?'] = {"main": [[{"node": "WC crear pedido", "type": "main", "index": 0}],
                                              [{"node": "Resultado", "type": "main", "index": 0}]]}

json.dump(w, open(dst, 'w'), ensure_ascii=False, indent=1)
print('OK nodos', len(w['nodes']))
