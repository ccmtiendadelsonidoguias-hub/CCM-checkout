// Arnes de la instrumentacion: viejo vs nuevo, cableado real.
const fs=require('fs');
const load=p=>{const d=JSON.parse(fs.readFileSync(p,'utf8'));return Array.isArray(d)?d[0]:d;};
const A_old=load(process.argv[2]), A_new=load(process.argv[3]);
const P_old=load(process.argv[4]), P_new=load(process.argv[5]);
const code=(w,n)=>w.nodes.find(x=>x.name===n).parameters.jsCode;
const html=w=>w.nodes.find(x=>x.name==='HTML').parameters.responseBody;
let f=0; const chk=(c,m)=>{console.log((c?'ok   ':'FALLA')+' '+m); if(!c)f++;};

const PROD={id:4601,sku:'CCM1119',name:'Parlante de prueba',price:'100000',stock_status:'instock',stock_quantity:5};
const cp=fm=>({conv:'99001',f:fm,items:[{sku:'CCM1119',qty:1}],sku_query:'CCM1119'});
const FORM={nombre:'Prueba',documento:'1',telefono:'3000000000',ciudad:'BARRANQUILLA (ATL) (08001000)',departamento:'ATLANTICO',direccion:'x',metodo_pago:'Transferencia',entrega:'recogida',vendedor_alegra_id:'3',vendedor_nombre:'Heider Arrieta',centro_costo_id:'3',centro_costo_nombre:'Ventas Virtuales Personas CCM'};
const AG={email:'heider@ccmtiendadelsonido.com',name:'H',conocido:true,vendedor_id:3,vendedor_nombre:'Heider Arrieta',ccosto_id:3,ccosto_nombre:'Ventas Virtuales Personas CCM',mapa:{},ccosto:{ccosto_id:3,ccosto_nombre:'Ventas Virtuales Personas CCM'},bot:{vendedor_id:9,vendedor_nombre:'Bot CCM IA',ccosto_id:10,ccosto_nombre:'IA CCM'}};
const op=(w,fm)=>new Function('$json','$','$input',code(w,'Order payload')+'\n')({},n=>({first:()=>({json:n==='Crear parse'?cp(fm):{agente_resuelto:AG}}),item:{json:n==='Crear parse'?cp(fm):{agente_resuelto:AG}}}),{all:()=>[{json:PROD}]});
const meta=(o,k)=>((o.order_body||{}).meta_data||[]).find(m=>m.key===k);

const P='[{"s":"CCM1143","q":2},{"s":"","q":1}]';
chk(!meta(op(A_old,Object.assign({},FORM,{scan_propuesta:P})),'_ccm_scan_propuesta'), 'ANTES: la propuesta se perdia (regresion falla contra el viejo)');
const conP=op(A_new,Object.assign({},FORM,{scan_propuesta:P}));
chk(meta(conP,'_ccm_scan_propuesta') && meta(conP,'_ccm_scan_propuesta').value===P, 'AHORA: guarda _ccm_scan_propuesta tal cual');
chk(!meta(op(A_new,Object.assign({},FORM,{scan_propuesta:''})),'_ccm_scan_propuesta'), 'sin escaneo NO escribe la meta (ausencia = no se uso)');
chk(!meta(op(A_new,FORM),'_ccm_scan_propuesta'), 'campo ausente tampoco escribe');
const largo=op(A_new,Object.assign({},FORM,{scan_propuesta:'x'.repeat(5000)}));
chk(meta(largo,'_ccm_scan_propuesta').value.length===2000, 'recorta a 2000 chars');
const sinInst=op(A_new,FORM), viejoSin=op(A_old,FORM);
const norm=o=>JSON.stringify(((o.order_body||{}).meta_data||[]).map(m=>m.key));
chk(norm(sinInst)===norm(viejoSin), 'sin propuesta, las metas son las mismas que antes');
chk(meta(op(A_new,Object.assign({},FORM,{scan_propuesta:P})),'_ccm_canal_venta').value==='asesor', 'la atribucion sigue intacta');

const ho=html(P_old), hn=html(P_new);
chk(!/scan_propuesta/.test(ho) && /scan_propuesta/.test(hn), 'popup: el campo es nuevo');
chk(/SCAN_PROPUESTA = JSON.stringify/.test(hn), 'popup: guarda la propuesta del escaneo');
chk(hn.indexOf('SCAN_PROPUESTA = JSON.stringify') < hn.indexOf('fill(d, {overwrite:true'), 'popup: la guarda ANTES de que fill la pinte (asi no recoge ediciones)');
chk(/form.scan_propuesta = SCAN_PROPUESTA;/.test(hn), 'popup: la manda al crear');
chk(hn.length - ho.length < 700, 'popup: crecimiento acotado ('+(hn.length-ho.length)+' bytes)');
console.log(f?('\n>>> '+f+' FALLOS'):'\n>>> todo verde'); process.exit(f?1:0);
