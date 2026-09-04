// Mide el resolutor del scan ejecutando el JS REAL del nodo `Elegir producto`
// contra WooCommerce en vivo. Corre en el VPS.
//   node /root/elegir_eval.js /root/truth_setC.json /root/resolver_eval_llm_setC.json /root/api_scan.new.json
const fs = require('fs'), https = require('https');
const [truthP, llmP, wfP] = process.argv.slice(2);
const TRUTH = JSON.parse(fs.readFileSync(truthP, 'utf8'));
const LLM   = JSON.parse(fs.readFileSync(llmP, 'utf8'));
const wfRaw = JSON.parse(fs.readFileSync(wfP, 'utf8'));
const wf = Array.isArray(wfRaw) ? wfRaw[0] : wfRaw;
const CODE = wf.nodes.find(n => n.name === 'Elegir producto').parameters.jsCode;
const CK = fs.readFileSync('/root/.woo_ck', 'utf8').trim(), CS = fs.readFileSync('/root/.woo_cs', 'utf8').trim();
const AUTH = 'Basic ' + Buffer.from(CK + ':' + CS).toString('base64');

const cache = {};
let ERRORES = 0;
const dormir = (ms) => new Promise(r => setTimeout(r, ms));
// NO cachear fallos: un 5xx o un corte del WAF se veia igual que "cero resultados" y
// contaba como vacio, inventando regresiones que no se reproducian. Reintenta y, si no
// hay forma, aborta la medicion en vez de mentir con un vacio.
async function wc(qs) {
  if (cache[qs]) return cache[qs];
  for (let intento = 1; intento <= 4; intento++) {
    const r = await new Promise((res) => {
      const req = https.request({ host: 'ccmtiendadelsonido.com',
        path: '/wp-json/wc/v3/products?_fields=id,name,sku,price,stock_status,stock_quantity&' + qs,
        headers: { Authorization: AUTH, 'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) ccm-elegir-eval' } },
        (resp) => { let b = ''; resp.on('data', d => b += d); resp.on('end', () => res({ code: resp.statusCode, body: b })); });
      req.on('error', (e) => res({ code: 0, body: String(e.message) }));
      req.setTimeout(30000, () => { req.destroy(); res({ code: 0, body: 'timeout' }); });
      req.end();
    });
    if (r.code === 200) {
      let j; try { j = JSON.parse(r.body); } catch (e) { j = null; }
      if (Array.isArray(j)) { cache[qs] = j; await dormir(120); return j; }
    }
    await dormir(400 * intento);
  }
  ERRORES++;
  throw new Error('WC no respondio tras 4 intentos: ' + qs);
}
// misma construccion de URL que el nodo HTTP parchado / el anterior
const urlNueva = (p) => p.__empty ? 'sku=NONE'
  : (p.sku ? 'sku=' + encodeURIComponent(p.sku)
           : 'search=' + encodeURIComponent(String(p.nombre || '').replace(/\([^)]*\)/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 60)) + '&per_page=5');
const urlVieja = (p) => p.__empty ? 'sku=NONE'
  : (p.sku ? 'sku=' + encodeURIComponent(p.sku)
           : 'search=' + encodeURIComponent(String(p.nombre || '').slice(0, 60)) + '&per_page=1');

const elegir = new Function('$json', '$', CODE + '\n');
const runNuevo = (p, body) => {
  try { const o = elegir({ body }, (n) => { if (n !== 'Split prods') throw new Error('nodo ' + n); return { item: { json: p } }; });
        return { sku: (o.sku_elegido && o.sku_elegido !== 'NONE') ? o.sku_elegido : '', motivo: o.motivo }; }
  catch (e) { return { sku: '', motivo: 'EXCEPCION: ' + e.message }; }
};
const runViejo = (p, body) => { const w0 = (body || [])[0] || null;
  return { sku: (w0 && w0.sku) ? String(w0.sku).toUpperCase() : String(p.sku || ''), motivo: 'primer_resultado' }; };

(async () => {
  const rows = [];
  for (const order of Object.keys(TRUTH)) {
    const truth = TRUTH[order][1];
    const prods = LLM[order] || [];
    const gotN = {}, gotV = {}, motivos = [];
    for (const p of prods) {
      const bN = await wc(urlNueva(p)), bV = await wc(urlVieja(p));
      const n = runNuevo(p, bN), v = runViejo(p, bV);
      if (n.sku) gotN[n.sku] = (gotN[n.sku] || 0) + (Number(p.qty) || 1);
      if (v.sku) gotV[v.sku] = (gotV[v.sku] || 0) + (Number(p.qty) || 1);
      motivos.push(n.motivo);
    }
    const tag = (got) => { const T = Object.keys(truth), G = Object.keys(got);
      if (G.length === T.length && T.every(k => got[k] === truth[k])) return 'EXACTO';
      return T.some(k => G.indexOf(k) !== -1) ? 'PARCIAL' : 'FALLO'; };
    rows.push({ order, v: tag(gotV), n: tag(gotN),
      real: Object.entries(truth).map(([k, q]) => k + 'x' + q).join(' '),
      antes: Object.entries(gotV).map(([k, q]) => k + 'x' + q).join(' ') || '(vacio)',
      ahora: Object.entries(gotN).map(([k, q]) => k + 'x' + q).join(' ') || '(vacio)',
      motivos: motivos.join(',') });
  }
  const R = { EXACTO: 2, PARCIAL: 1, FALLO: 0 };
  const cnt = (k, t) => rows.filter(r => r[k] === t).length;
  console.log('pedido'.padEnd(8) + 'ANTES'.padEnd(9) + 'AHORA'.padEnd(9) + 'real'.padEnd(24) + 'antes'.padEnd(24) + 'ahora'.padEnd(24) + 'motivo');
  for (const r of rows) {
    const m = r.v !== r.n ? '  <=' : (r.antes !== r.ahora ? '  ~' : '');
    console.log(r.order.padEnd(8) + r.v.padEnd(9) + r.n.padEnd(9) + r.real.padEnd(24) + r.antes.padEnd(24) + r.ahora.padEnd(24) + r.motivos.slice(0, 40) + m);
  }
  console.log('\nANTES: exacto %d  parcial %d  fallo %d   (de %d)', cnt('v','EXACTO'), cnt('v','PARCIAL'), cnt('v','FALLO'), rows.length);
  console.log('AHORA: exacto %d  parcial %d  fallo %d', cnt('n','EXACTO'), cnt('n','PARCIAL'), cnt('n','FALLO'));
  const vac = k => rows.filter(r => r[k === 'v' ? 'antes' : 'ahora'] === '(vacio)').length;
  const malo = k => rows.filter(r => r[k] !== 'EXACTO' && r[k === 'v' ? 'antes' : 'ahora'] !== '(vacio)').length;
  console.log('vacios (los llena el asesor):        antes %d -> ahora %d', vac('v'), vac('n'));
  console.log('con SKU pero NO exacto (factura mal): antes %d -> ahora %d', malo('v'), malo('n'));
  console.log('bajan de categoria:', rows.filter(r => R[r.n] < R[r.v]).map(r => r.order).join(' ') || 'ninguno');
  console.log('vacio -> SKU equivocado:', rows.filter(r => r.antes === '(vacio)' && r.ahora !== '(vacio)' && r.n !== 'EXACTO').map(r => r.order).join(' ') || 'ninguno');
  if (ERRORES) console.log('\n*** ATENCION:', ERRORES, 'consultas fallaron: la medicion NO es fiable ***');
})().catch(e => { console.error('\nMEDICION ABORTADA:', e.message); process.exit(2); });
