# -*- coding: utf-8 -*-
"""Cirugia sobre el HTML del nodo `HTML` de cwVentaPage01 (ventas de asesores, 2026-09-03).
Uso: python3 docs/n8n/patches/2026-09-03-ventas-asesores-popup.py /tmp/cwVentaPage01.json /tmp/cwVentaPage01.new.json
Cada ancla debe aparecer EXACTAMENTE una vez."""
import json, sys
src, dst = sys.argv[1], sys.argv[2]
raw = json.load(open(src)); w = raw[0] if isinstance(raw, list) else raw
node = [n for n in w['nodes'] if n['name'] == 'HTML'][0]
h = node['parameters']['responseBody']

def sub(old, new, label):
    global h
    if h.count(old) != 1:
        raise SystemExit('ANCLA no unica (%d): %s' % (h.count(old), label))
    h = h.replace(old, new)

if 'id="es_bot"' in h:
    raise SystemExit('ya parchado')

# 1. selects sin default al bot + opcion vacia + casilla
sub('<select id="vendedor">\n<option value="9" selected>🤖 Bot CCM IA</option>',
    '<select id="vendedor">\n<option value="">— Elegir —</option>\n<option value="9">🤖 Bot CCM IA</option>', 'select vendedor')
sub('<select id="ccosto">\n<option value="10" selected>IA CCM</option>',
    '<select id="ccosto">\n<option value="">— Elegir —</option>\n<option value="10">IA CCM</option>', 'select ccosto')
sub('<label>Vendedor</label>',
    '<label>Vendedor <label style="font-weight:400;margin-left:8px"><input type="checkbox" id="es_bot"> 🤖 Venta cerrada por el bot</label></label>', 'casilla es_bot')

# 2. estado del agente + escucha de appContext + stub para el arnes
sub('var DKEY = "ccm_venta_draft_" + CONV;',
    'var DKEY = "ccm_venta_draft_" + CONV;\n'
    '// 2026-09-03: agente de Chatwoot (appContext) -> vendedor precargado por la API\n'
    'var AGENTE = null, AGENTE_VEND = null;\n'
    'function aplicarVendedor(v){ if (!v) return; if (v.vendedor_id) document.getElementById("vendedor").value = String(v.vendedor_id); if (v.ccosto_id) document.getElementById("ccosto").value = String(v.ccosto_id); }\n'
    'function toggleBot(){ var on = document.getElementById("es_bot").checked; var vs = document.getElementById("vendedor"), cs = document.getElementById("ccosto");\n'
    '  if (on) { vs.value = "9"; cs.value = "10"; } else { vs.value = ""; cs.value = ""; aplicarVendedor(AGENTE_VEND); } vs.disabled = on; cs.disabled = on; }\n'
    'window.addEventListener("message", function(ev){ var d; try { d = JSON.parse(ev.data); } catch(e){ return; }\n'
    '  if (d && d.event === "appContext" && d.data && d.data.currentAgent) { AGENTE = { email: String(d.data.currentAgent.email || ""), name: String(d.data.currentAgent.name || "") }; } });\n'
    'window.parent.postMessage("chatwoot-dashboard-app:fetch-info", "*");\n'
    '// stub SOLO para el arnes local (?__stub=1): no toca la API real\n'
    'if (new URLSearchParams(location.search).get("__stub") === "1") { var __f = window.fetch; window.fetch = function(u, o){ var b = {}; try { b = JSON.parse(o.body); } catch(e){}\n'
    '  if (b.action === "prefill") { window.__ultimoPrefill = b; return Promise.resolve({ json: function(){ return { ok: true, cliente: {}, items: [{ sku: "CCM1119", nombre: "Parlante de prueba", qty: 1, precio: 100000, product_id: 4601 }], vendedor_id: 3, vendedor_nombre: "Heider Arrieta", ccosto_id: 3, ccosto_nombre: "Ventas Virtuales Personas CCM" }; } }); }\n'
    '  return Promise.resolve({ json: function(){ return { ok: false }; } }); }; }', 'estado agente')

# 3. fill(): precarga vendedor/ccosto de la respuesta
sub('  if (c.tipo_documento) document.getElementById("tipodoc").value = c.tipo_documento;',
    '  if (c.tipo_documento) document.getElementById("tipodoc").value = c.tipo_documento;\n'
    '  if (d.vendedor_id || d.ccosto_id) { AGENTE_VEND = { vendedor_id: d.vendedor_id, ccosto_id: d.ccosto_id }; if (!document.getElementById("es_bot").checked) aplicarVendedor(AGENTE_VEND); }', 'fill vendedor')

# 4. arranque: esperar el appContext (max 1,5 s) y mandar agente en prefill
sub('if (!draftRaw) {\n  fetch(API, {method:"POST", headers:{"Content-Type":"application/json"}, body: JSON.stringify({action:"prefill", conv:CONV})})',
    'function arrancar(){\n  fetch(API, {method:"POST", headers:{"Content-Type":"application/json"}, body: JSON.stringify({action:"prefill", conv:CONV, agente: AGENTE})})', 'arranque prefill')
sub('    .catch(function(){ document.getElementById("sub").textContent = "No se pudo precargar — llena manual."; fill({}); runScan(true); });\n}',
    '    .catch(function(){ document.getElementById("sub").textContent = "No se pudo precargar — llena manual."; fill({}); runScan(true); });\n}\n'
    'if (!draftRaw) { var __esperas = 0; (function esperarAgente(){ if (AGENTE || __esperas >= 15) return arrancar(); __esperas++; setTimeout(esperarAgente, 100); })(); }\n'
    'document.getElementById("es_bot").onchange = toggleBot;', 'arranque espera agente')

# 5. onsubmit: exigir vendedor y mandar es_bot + agente
sub('  if (!items.length) { err.textContent = "Agrega al menos un producto con SKU."; return; }',
    '  if (!items.length) { err.textContent = "Agrega al menos un producto con SKU."; return; }\n'
    '  if (!document.getElementById("vendedor").value) { err.textContent = "Elige el vendedor (o marca que la venta la cerró el bot)."; return; }', 'submit exige vendedor')
sub('  form.vendedor_alegra_id = vsel.value;',
    '  form.vendedor_alegra_id = vsel.value;\n  form.es_bot = document.getElementById("es_bot").checked;', 'form es_bot')
sub('body: JSON.stringify({action:"crear", conv:CONV, form:form})',
    'body: JSON.stringify({action:"crear", conv:CONV, form:form, agente: AGENTE})', 'crear agente')

node['parameters']['responseBody'] = h
json.dump(w, open(dst, 'w'), ensure_ascii=False, indent=1)
print('OK bytes', len(h))
