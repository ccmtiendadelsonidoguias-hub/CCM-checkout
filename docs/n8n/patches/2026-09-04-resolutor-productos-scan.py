# -*- coding: utf-8 -*-
"""cwVentaApi01: el escaneo elegia el producto equivocado cuando dos nombres son casi
iguales. `WC resolver scan` pedia per_page=1 y se quedaba con el primer resultado del
orden de relevancia de WooCommerce, que NO es la coincidencia exacta:

    LLM: "Tweeter MTE 1201 800W"  (nombre exacto de CCM1143)
    WC:  1) CCM1526 "Tweeter MTE 1201Bk 800W"   <- se llevaba este
         2) CCM1143 "Tweeter MTE 1201 800W"

Cambios: 5 candidatos + eleccion explicita en un nodo nuevo (`Elegir producto`), antes
de Alegra porque `Alegra resolver scan` usaba el PRIMER resultado para el precio de
transferencia. Sin coincidencia clara devuelve vacio: el popup obliga al asesor a poner
el SKU, que es preferible a facturar otro producto.

Uso: python3 docs/n8n/patches/2026-09-04-resolutor-productos-scan.py ENTRADA.json SALIDA.json
Cada ancla debe aparecer EXACTAMENTE una vez; si no, aborta sin escribir.
"""
import json, sys

src, dst = sys.argv[1], sys.argv[2]
raw = json.load(open(src)); w = raw[0] if isinstance(raw, list) else raw

def node(name):
    for n in w['nodes']:
        if n['name'] == name: return n
    raise SystemExit('no existe nodo ' + name)

if any(n['name'] == 'Elegir producto' for n in w['nodes']):
    raise SystemExit('ya parchado')

ELEGIR = r"""// Elige el producto de WooCommerce para UNA linea extraida por el LLM (2026-09-04).
// Antes lo decidia el propio HTTP con per_page=1: se quedaba con el primer resultado del
// orden de relevancia de WooCommerce, que no es la coincidencia exacta (CCM1526 "Tweeter
// MTE 1201Bk" le ganaba a CCM1143 "Tweeter MTE 1201"). Niveles de mas fuerte a mas debil;
// si ninguno decide -> vacio, y el popup obliga al asesor a ponerlo.
const p = $('Split prods').item.json || {};
const r = $json || {};
const cand = Array.isArray(r.body) ? r.body : [];
const norm = (s) => String(s || '')
  .normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/\([^)]*\)/g, ' ')
  .toUpperCase().replace(/[^A-Z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
const jac = (a, b) => { const A = new Set(norm(a).split(' ')), B = new Set(norm(b).split(' '));
  let i = 0; A.forEach(x => { if (B.has(x)) i++; });
  return i / Math.max(1, A.size + B.size - i); };
const nombre = String(p.nombre || '');
const t = norm(nombre);
const sal = (sku, wc, motivo) => ({ sku_elegido: String(sku || '') || 'NONE', wc: wc || null, motivo: motivo });
if (p.__empty) return sal('', null, 'sin_productos');

// El LLM dio un SKU: el HTTP consulto por SKU, no por nombre.
if (p.sku) {
  const hit = cand.find(x => String(x.sku || '').toUpperCase() === String(p.sku).toUpperCase()) || cand[0] || null;
  if (!hit) return sal('', null, 'sku_no_existe');
  // El modelo puede copiar un CCM de otra parte del chat. Caso real: dijo "Tweeter MTE
  // 1201 800W" con sku CCM270, que es la BOBINA de ese tweeter. Si el nombre extraido no
  // comparte ni la primera palabra (el tipo de producto) con el del SKU, no nos fiamos.
  if (t) {
    const primera = norm(hit.name).split(' ')[0];
    if (primera && t.split(' ').indexOf(primera) === -1) return sal('', null, 'sku_no_cuadra_con_nombre:' + hit.sku);
  }
  return sal(String(hit.sku || '').toUpperCase(), hit, 'por_sku');
}
if (!cand.length) return sal('', null, 'sin_candidatos');
// 1) nombre exacto normalizado, un unico candidato
const ex = cand.filter(x => norm(x.name) === t);
if (ex.length === 1) return sal(String(ex[0].sku || '').toUpperCase(), ex[0], 'nombre_exacto');
if (ex.length > 1) return sal('', null, 'empate_exacto');
// 2) pegado sin espacios: "PL 243 Washer" dentro de "Luz LED PRO DJ PL243 Washer"
const st = t.replace(/ /g, '');
if (st.length >= 6) {
  const cont = cand.filter(x => norm(x.name).replace(/ /g, '').indexOf(st) !== -1);
  if (cont.length === 1) return sal(String(cont[0].sku || '').toUpperCase(), cont[0], 'contenido');
}
// 3) el mas parecido, con piso y margen. Con una sola palabra no se adivina: "4L (con olor
// a chicle)" quedaba en "4L" y colaba cualquier cosa.
if (t.split(' ').length < 2) return sal('', null, 'nombre_muy_corto');
const ord = cand.slice().sort((a, b) => jac(b.name, t) - jac(a.name, t));
const s0 = jac(ord[0].name, t);
if (s0 < 0.40) return sal('', null, 'parecido_bajo');
if (ord.length === 1 || (s0 - jac(ord[1].name, t)) >= 0.10) return sal(String(ord[0].sku || '').toUpperCase(), ord[0], 'mas_parecido');
return sal('', null, 'ambiguo');"""

# ---------- 1. WC resolver scan: 5 candidatos y sin parentesis ----------
n = node('WC resolver scan')
old_url = n['parameters']['url']
assert "per_page=1" in old_url and "search=" in old_url, 'la URL no es la esperada'
n['parameters']['url'] = ("={{ 'https://ccmtiendadelsonido.com/wp-json/wc/v3/products?_fields=id,name,sku,price,stock_status,stock_quantity&' "
  "+ ($json.__empty ? 'sku=NONE' : ($json.sku ? 'sku=' + encodeURIComponent($json.sku) "
  ": 'search=' + encodeURIComponent(String($json.nombre || '').replace(/\\([^)]*\\)/g, ' ').replace(/\\s+/g, ' ').trim().slice(0, 60)) + '&per_page=5')) }}")

# ---------- 2. nodo nuevo Elegir producto ----------
pos = n.get('position', [0, 0])
w['nodes'].append({
    "parameters": {"mode": "runOnceForEachItem", "jsCode": ELEGIR},
    "id": "e1e2e3e4-0001-4000-8000-000000000001", "name": "Elegir producto",
    "type": "n8n-nodes-base.code", "typeVersion": 2, "position": [pos[0] + 160, pos[1]]})
assert [c['node'] for c in w['connections']['WC resolver scan']['main'][0]] == ['Alegra resolver scan']
w['connections']['WC resolver scan']['main'][0] = [{"node": "Elegir producto", "type": "main", "index": 0}]
w['connections']['Elegir producto'] = {"main": [[{"node": "Alegra resolver scan", "type": "main", "index": 0}]]}

# ---------- 3. Alegra usa el SKU ELEGIDO, no el primer resultado ----------
n = node('Alegra resolver scan')
old = "={{ 'https://api.alegra.com/api/v1/items?query=' + encodeURIComponent(((($json.body || [])[0] || {}).sku) || $('Split prods').item.json.sku || 'NONE') + '&limit=3' }}"
if n['parameters']['url'] != old: raise SystemExit('ANCLA Alegra: la URL cambio')
n['parameters']['url'] = "={{ 'https://api.alegra.com/api/v1/items?query=' + encodeURIComponent($json.sku_elegido || 'NONE') + '&limit=3' }}"

# ---------- 4. Scan resolver: usa lo elegido ----------
n = node('Scan resolver'); c = n['parameters']['jsCode']
def sub(code, o, nw, label):
    if code.count(o) != 1: raise SystemExit('ANCLA no unica (%d) en %s' % (code.count(o), label))
    return code.replace(o, nw)
c = sub(c, "const wcs = $('WC resolver scan').all().map(i => i.json);",
        "const els = $('Elegir producto').all().map(i => i.json);", 'Scan resolver/els')
c = sub(c, "  const wcp = ((wcs[i] || {}).body || [])[0] || null;\n"
           "  const skuF = (wcp && wcp.sku) ? String(wcp.sku).toUpperCase() : String(p.sku || '');",
        "  const el = els[i] || {};\n"
        "  const wcp = el.wc || null;\n"
        "  // vacio a proposito cuando la eleccion no fue clara (motivo en el propio nodo):\n"
        "  // el popup obliga al asesor a poner el SKU, mejor que facturar otro producto.\n"
        "  const skuF = (el.sku_elegido && el.sku_elegido !== 'NONE') ? String(el.sku_elegido).toUpperCase() : '';",
        'Scan resolver/eleccion')
n['parameters']['jsCode'] = c

json.dump(w, open(dst, 'w'), ensure_ascii=False, indent=1)
print('OK nodos', len(w['nodes']))
