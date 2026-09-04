// Arnés viejo-vs-nuevo para cwVentaApi01. Uso:
//   node docs/n8n/harness/ventas_asesores_api.js /tmp/cwVentaApi01.json            -> debe FALLAR (codigo viejo)
//   node docs/n8n/harness/ventas_asesores_api.js /tmp/cwVentaApi01.new.json        -> debe pasar
// Simula el CABLEADO REAL: $json es lo que emite el nodo anterior; lo demas se lee por nombre.
const fs = require('fs');
const wf = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const w = Array.isArray(wf) ? wf[0] : wf;
const code = n => { const x = w.nodes.find(k => k.name === n); if (!x) throw new Error('no existe nodo ' + n); return x.parameters.jsCode; };
const run = (src, $json, nodes, input) => new Function('$json', '$', '$input', src + '\n')($json, name => {
  if (!(name in nodes)) throw new Error('nodo no simulado: ' + name);
  return { first: () => ({ json: nodes[name] }), item: { json: nodes[name] } };
}, input || { all: () => [] });

let fallos = 0;
const chk = (c, m) => { console.log((c ? 'ok   ' : 'FALLA') + ' ' + m); if (!c) fallos++; };

// ---- fixtures inventados (nada de clientes reales) ----
const PROD = { id: 4601, sku: 'CCM1119', name: 'Parlante de prueba', price: '100000', stock_status: 'instock', stock_quantity: 5 };
const cp = (f) => ({ conv: '99001', f, items: [{ sku: 'CCM1119', qty: 1 }], sku_query: 'CCM1119' });
const FORM_BASE = { nombre: 'Prueba', apellido: 'Arnes', documento: '1', telefono: '3000000000', ciudad: 'BARRANQUILLA (ATL) (08001000)',
  departamento: 'ATLANTICO', direccion: 'x', metodo_pago: 'Transferencia', entrega: 'recogida', items: [{ sku: 'CCM1119', qty: 1 }] };
const meta = (out, k) => (((out || {}).order_body || {}).meta_data || []).find(m => m.key === k)?.value;

// ---- Agente → vendedor ----
let ag;
try {
  const agentSrc = code('Agente → vendedor');
  ag = (email) => run(agentSrc, { body: { action: 'prefill', conv: '99001', agente: { email, name: 'X' } }, query: {} }, {})[0].json.agente_resuelto;
  chk(ag('heider@ccmtiendadelsonido.com').vendedor_id === 3 && ag('heider@ccmtiendadelsonido.com').ccosto_id === 3, 'heider -> vendedor 3, ccosto 3');
  chk(ag('FARID@ccmtiendadelsonido.com').vendedor_id === 4, 'farid (mayusculas) -> 4');
  // 2026-09-03 (regla "manda el asignado"): Camilo sale del mapa de ASESORES — sus chats se
  // facturan por el bot. Sigue siendo agente conocido para no disparar el aviso de Resultado.
  chk(ag('gerencia@ccmtiendadelsonido.com').vendedor_id === null && ag('gerencia@ccmtiendadelsonido.com').conocido === true,
      'gerencia: fuera del mapa de asesores pero conocido (sus ventas van al bot)');
  chk(Object.keys(ag('heider@ccmtiendadelsonido.com').mapa || {}).length === 2, 'el mapa de asesores tiene exactamente 2 (Heider, Farid)');
  chk(ag('nadie@otro.com').vendedor_id === null && ag('nadie@otro.com').conocido === false, 'desconocido -> null, conocido=false');
} catch (e) { chk(false, 'nodo Agente → vendedor existe (' + e.message + ')'); ag = () => ({}); }

// ---- Order payload ----
const opSrc = code('Order payload');
const op = (form, agente) => run(opSrc, {}, { 'Crear parse': cp(form), 'Agente → vendedor': { agente_resuelto: agente } }, { all: () => [{ json: PROD }] });

const outAsesor = op(Object.assign({}, FORM_BASE, { vendedor_alegra_id: '3', vendedor_nombre: 'Heider Arrieta', centro_costo_id: '3', centro_costo_nombre: 'Ventas Virtuales Personas CCM' }), ag('heider@ccmtiendadelsonido.com'));
chk(meta(outAsesor, '_ccm_canal_venta') === 'asesor', 'vendedor 3 -> canal asesor');
chk(meta(outAsesor, '_ccm_agente_chatwoot') === 'heider@ccmtiendadelsonido.com', 'guarda el agente de Chatwoot');
chk(meta(outAsesor, '_ccm_alegra_seller_id') === '3' && meta(outAsesor, '_ccm_alegra_cost_center_id') === '3', 'metas Alegra del asesor');
chk(meta(outAsesor, '_ccm_origen') === 'chatwoot_venta', '_ccm_origen intacto');

const outBot = op(Object.assign({}, FORM_BASE, { es_bot: true, vendedor_alegra_id: '3', vendedor_nombre: 'Heider Arrieta' }), ag('heider@ccmtiendadelsonido.com'));
chk(meta(outBot, '_ccm_canal_venta') === 'bot' && meta(outBot, '_ccm_alegra_seller_id') === '9' && meta(outBot, '_ccm_alegra_cost_center_id') === '10', 'casilla es_bot gana: 9 / IA CCM / canal bot');

// 2026-09-03: sin vendedor explicito la venta es del BOT (antes: error sin_vendedor).
const outSinVend = op(Object.assign({}, FORM_BASE, { vendedor_alegra_id: '', centro_costo_id: '' }), ag('nadie@otro.com'));
chk(!outSinVend.error && meta(outSinVend, '_ccm_alegra_seller_id') === '9' && meta(outSinVend, '_ccm_canal_venta') === 'bot',
    'sin vendedor ni agente -> BOT sin error');

// 2026-09-03: quien ABRE el popup ya no decide; decide a quien esta ASIGNADA la conversacion
// (ver docs/n8n/harness/asignado_manda.js). Con el popup vacio, respaldo = BOT.
const outSoloAgente = op(Object.assign({}, FORM_BASE, { vendedor_alegra_id: '', centro_costo_id: '' }), ag('farid@ccmtiendadelsonido.com'));
chk(meta(outSoloAgente, '_ccm_alegra_seller_id') === '9' && meta(outSoloAgente, '_ccm_canal_venta') === 'bot',
    'popup vacio aunque lo abra un asesor -> BOT (manda el asignado, no quien abre)');

// ---- Resultado en rama de error debe llevar conv ----
const resSrc = code('Resultado');
const res = run(resSrc, { error: 'sin_stock: x' }, { 'Order payload': { error: 'sin_stock: x' }, 'Crear parse': cp(FORM_BASE), 'Agente → vendedor': { agente_resuelto: ag('heider@ccmtiendadelsonido.com') } });
chk(res.ok === false && res.conv === '99001', 'Resultado en error lleva conv (ANTES: undefined -> nota perdida)');

// ---- cableado: el error NO llega a WC crear pedido ----
const to = (from) => (w.connections[from]?.main || []).map(b => b.map(c => c.node));
chk(JSON.stringify(to('Order payload')) === JSON.stringify([['¿Payload OK?']]), 'Order payload -> ¿Payload OK? (no directo a WC)');
chk(JSON.stringify(to('¿Payload OK?')) === JSON.stringify([['WC crear pedido'], ['Resultado']]), '¿Payload OK?: true -> WC crear pedido, false -> Resultado');
chk(JSON.stringify(to('WH Venta')) === JSON.stringify([['Agente → vendedor']]) && JSON.stringify(to('Agente → vendedor')) === JSON.stringify([['¿Prefill?']]), 'Agente → vendedor va entre WH Venta y ¿Prefill?');

console.log(fallos ? '\n>>> ' + fallos + ' FALLOS' : '\n>>> todo verde');
process.exit(fallos ? 1 : 0);
