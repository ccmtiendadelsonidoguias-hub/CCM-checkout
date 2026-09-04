# -*- coding: utf-8 -*-
"""Instrumenta el escaneo del boton Venta para poder medir su precision REAL.

Por que: la precision del escaneo no se puede medir con pedidos viejos. Sus conversaciones
siguen vivas semanas despues (74-277 mensajes, meses), y la ventana del escaneo son los
ultimos 60: lo que ve es soporte posventa, no la venta. Medido el 2026-09-04: los 5 casos
"el modelo no devolvio nada" eran eso, no un fallo del prompt.

Que hace: el popup ya sabe, en el mismo instante, que SKUs le PROPUSO el escaneo y con
cuales termino el asesor. Con que mande la propuesta, el pedido guarda la meta
`_ccm_scan_propuesta` y la precision sale de una consulta SQL sobre pedidos reales, sin
re-ejecutar el modelo y sin conjuntos de control. De paso mide cuantas veces el asesor
corrige a mano, que es la senal honesta de si el escaneo sirve.

No cambia ninguna decision: es una meta que hoy nadie lee.

Uso: python3 docs/n8n/patches/2026-09-04-instrumentar-scan.py API_IN API_OUT PAGE_IN PAGE_OUT
Cada ancla debe aparecer EXACTAMENTE una vez; si no, aborta sin escribir nada.
"""
import json, sys

api_in, api_out, page_in, page_out = sys.argv[1:5]

def cargar(p):
    d = json.load(open(p)); return d[0] if isinstance(d, list) else d

def sub(code, old, new, label):
    if code.count(old) != 1:
        raise SystemExit('ANCLA no unica (%d) en %s' % (code.count(old), label))
    return code.replace(old, new)

# ------------------------------------------------------------------ API
w = cargar(api_in)
if 'scan_propuesta' in json.dumps(w, ensure_ascii=False):
    raise SystemExit('API ya parchada')
n = [x for x in w['nodes'] if x['name'] == 'Order payload'][0]
c = n['parameters']['jsCode']
c = sub(c,
"    { key: '_ccm_agente_chatwoot', value: vend.agente_email },",
"    { key: '_ccm_agente_chatwoot', value: vend.agente_email },\n"
"    // Instrumentacion (2026-09-04): que SKUs propuso el escaneo, para medir su precision\n"
"    // real contra las lineas que quedaron. Ausencia de la meta = no se uso el escaneo.\n"
"    ...(String(f.scan_propuesta || '').trim() ? [{ key: '_ccm_scan_propuesta', value: String(f.scan_propuesta).slice(0, 2000) }] : []),",
'Order payload/meta propuesta')
n['parameters']['jsCode'] = c
json.dump(w, open(api_out, 'w'), ensure_ascii=False, indent=1)

# ------------------------------------------------------------------ POPUP
w = cargar(page_in)
n = [x for x in w['nodes'] if x['name'] == 'HTML'][0]
h = n['parameters']['responseBody']
if 'scan_propuesta' in h:
    raise SystemExit('popup ya parchado')

def subh(old, new, label):
    global h
    if h.count(old) != 1:
        raise SystemExit('ANCLA no unica (%d) en %s' % (h.count(old), label))
    h = h.replace(old, new)

# 1. variable donde queda la propuesta del escaneo
subh('var AGENTE = null, AGENTE_VEND = null;',
     'var AGENTE = null, AGENTE_VEND = null;\n'
     '// Instrumentacion: lo que PROPUSO el escaneo, tal cual, antes de que el asesor toque nada.\n'
     'var SCAN_PROPUESTA = "";', 'popup/var propuesta')

# 2. guardarla al llegar la respuesta del escaneo (antes de fill, que ya la pinta)
subh('      montoManual = false;\n      fill(d, {overwrite:true, montoFijo: !!d.monto_es_acordado});',
     '      montoManual = false;\n'
     '      try { SCAN_PROPUESTA = JSON.stringify({ ts: new Date().toISOString(),\n'
     '        items: (d.items || []).map(function(i){ return { s: String(i.sku || ""), q: Number(i.qty) || 1 }; }) }); } catch(e) { SCAN_PROPUESTA = ""; }\n'
     '      fill(d, {overwrite:true, montoFijo: !!d.monto_es_acordado});', 'popup/guardar propuesta')

# 3. mandarla al crear
subh('  form.es_bot = document.getElementById("es_bot").checked;',
     '  form.es_bot = document.getElementById("es_bot").checked;\n'
     '  form.scan_propuesta = SCAN_PROPUESTA;', 'popup/enviar propuesta')

n['parameters']['responseBody'] = h
json.dump(w, open(page_out, 'w'), ensure_ascii=False, indent=1)
print('OK  api nodos', len(cargar(api_out)['nodes']), '| popup bytes', len(h))
