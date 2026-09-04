// Arnes viejo-vs-nuevo de la regla "manda el ASIGNADO de la conversacion" (cwVentaApi01).
//   node docs/n8n/harness/asignado_manda.js /tmp/cwVentaApi01.v2.json      -> debe FALLAR (codigo actual)
//   node docs/n8n/harness/asignado_manda.js /tmp/cwVentaApi01.v2.new.json  -> debe pasar
// Simula el CABLEADO REAL: $json = salida del nodo anterior; lo demas por nombre.
const fs = require('fs');
const wf = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const w = Array.isArray(wf) ? wf[0] : wf;
const code = n => { const x = w.nodes.find(k => k.name === n); if (!x) throw new Error('no existe nodo ' + n); return x.parameters.jsCode; };
const run = (src, $json, nodes, input) => new Function('$json', '$', '$input', src + '\n')($json, name => {
  if (!(name in nodes)) throw new Error('nodo no simulado: ' + name);
  return { first: () => ({ json: nodes[name] }), item: { json: nodes[name] } };
}, input || { all: () => [] });

let f = 0;
const chk = (c, m) => { console.log((c ? 'ok   ' : 'FALLA') + ' ' + m); if (!c) f++; };

const HEIDER = 'heider@ccmtiendadelsonido.com', FARID = 'farid@ccmtiendadelsonido.com', CAMILO = 'gerencia@ccmtiendadelsonido.com';
// conversacion tal como la devuelve Chatwoot (forma verificada en vivo el 2026-09-03)
const conv = (email) => ({ id: 99001, status: 'open',
  meta: { sender: { id: 7, name: 'Cliente Prueba', phone_number: '+573000000000', email: '' },
          assignee: email ? { id: 1, name: 'X', email } : null, assignee_type: 'agent' } });
const PROD = { id: 4601, sku: 'CCM1119', name: 'Parlante de prueba', price: '100000', stock_status: 'instock', stock_quantity: 5 };

const agente = (email) => run(code('Agente → vendedor'), { body: { action: 'prefill', conv: '99001', agente: { email, name: 'X' } }, query: {} }, {})[0].json.agente_resuelto;

// ---- prefill: el vendedor sale del ASIGNADO, no de quien abre ----
function prefill(asignadoEmail, abrePopupEmail) {
  const ag = agente(abrePopupEmail);
  const base = { conversation_id: '99001', contact_id: 7, nombre: 'Cliente', apellido: 'Prueba',
    telefono: '573000000000', email: '', ciudad: '', monto_hint: null, skus: [], sku_query: 'NONE' };
  return run(code('Prefill build'), {}, {
    'Prefill parse': base, 'Agente → vendedor': { agente_resuelto: ag }, 'GET conv venta': conv(asignadoEmail),
  }, { all: () => [] })[0].json;
}

const CASOS = [
  ['asignada a Heider, la abre Heider',  HEIDER, HEIDER, 3, false],
  ['asignada a Heider, la abre Camilo',  HEIDER, CAMILO, 3, false],
  ['asignada a Farid,  la abre Camilo',  FARID,  CAMILO, 4, false],
  ['asignada a Camilo, la abre Camilo',  CAMILO, CAMILO, 9, true],
  ['asignada a Camilo, la abre Heider',  CAMILO, HEIDER, 9, true],
  ['SIN asignar (bot), la abre Camilo',  null,   CAMILO, 9, true],
  ['SIN asignar (bot), la abre Heider',  null,   HEIDER, 9, true],
  ['SIN asignar, sin contexto de agente', null,  '',     9, true],
];
console.log('caso'.padEnd(40), 'vendedor', 'ccosto', 'es_bot');
for (const [nom, asig, abre, espV, espBot] of CASOS) {
  const p = prefill(asig, abre);
  console.log('  ' + nom.padEnd(38), String(p.vendedor_id).padEnd(8), String(p.ccosto_id).padEnd(6), String(p.es_bot));
  chk(p.vendedor_id === espV && p.es_bot === espBot, nom + ' -> vendedor ' + espV + ', es_bot ' + espBot);
}
const pH = prefill(HEIDER, CAMILO);
chk(pH.vendedor_nombre === 'Heider Arrieta' && pH.ccosto_id === 3 && pH.ccosto_nombre === 'Ventas Virtuales Personas CCM', 'asesor lleva centro "Ventas Virtuales Personas CCM"');
const pB = prefill(null, HEIDER);
chk(pB.vendedor_nombre === 'Bot CCM IA' && pB.ccosto_nombre === 'IA CCM', 'bot lleva "IA CCM" (como antes)');
chk(prefill(CAMILO, CAMILO).agente_email === CAMILO && prefill(CAMILO, HEIDER).agente_email === HEIDER, 'agente_email sigue guardando QUIEN abrio el popup (auditoria)');
chk(prefill(HEIDER, CAMILO).asignado_email === HEIDER, 'asignado_email queda registrado');

// ---- crear: sin vendedor del popup, el respaldo es el BOT (antes: el agente) ----
const cp = (f) => ({ conv: '99001', f, items: [{ sku: 'CCM1119', qty: 1 }], sku_query: 'CCM1119' });
const FORM = { nombre: 'Prueba', documento: '1', telefono: '3000000000', ciudad: 'BARRANQUILLA (ATL) (08001000)',
  departamento: 'ATLANTICO', direccion: 'x', metodo_pago: 'Transferencia', entrega: 'recogida' };
const op = (form, ag) => run(code('Order payload'), {}, { 'Crear parse': cp(form), 'Agente → vendedor': { agente_resuelto: ag } }, { all: () => [{ json: PROD }] });
const meta = (o, k) => ((o.order_body || {}).meta_data || []).find(m => m.key === k)?.value;

const sinPopup = op(Object.assign({}, FORM, { vendedor_alegra_id: '', centro_costo_id: '' }), agente(HEIDER));
chk(meta(sinPopup, '_ccm_alegra_seller_id') === '9' && meta(sinPopup, '_ccm_canal_venta') === 'bot',
    'popup sin vendedor + lo abre Heider -> BOT (ANTES: se lo atribuia a Heider)');
chk(meta(sinPopup, '_ccm_alegra_cost_center_nombre') === 'IA CCM', 'respaldo bot lleva IA CCM');
const conPopup = op(Object.assign({}, FORM, { vendedor_alegra_id: '3', vendedor_nombre: 'Heider Arrieta', centro_costo_id: '3', centro_costo_nombre: 'Ventas Virtuales Personas CCM' }), agente(CAMILO));
chk(meta(conPopup, '_ccm_alegra_seller_id') === '3' && meta(conPopup, '_ccm_canal_venta') === 'asesor', 'el desplegable sigue mandando si el asesor lo cambia');
const botExplicito = op(Object.assign({}, FORM, { es_bot: true, vendedor_alegra_id: '3' }), agente(HEIDER));
chk(meta(botExplicito, '_ccm_alegra_seller_id') === '9' && meta(botExplicito, '_ccm_canal_venta') === 'bot', 'la casilla es_bot sigue ganando');

console.log(f ? '\n>>> ' + f + ' FALLOS' : '\n>>> todo verde');
process.exit(f ? 1 : 0);
