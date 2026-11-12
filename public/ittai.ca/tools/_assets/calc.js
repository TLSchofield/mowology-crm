const fields={
  'labour-cost':['wage','vacation','stat','cpp','ei','wcb','billable','truck','rate'],
  'markup-margin':['mode','percent','cost'],
  'break-even':['truck_payment','insurance','phone_software','yard_storage','own_pay','other','rate','cost_per_billed_hour'],
  'mow-price':['onsite','crew','drive','cost_paid','margin','minimum']
};
export function allowlist(tool,raw){if(!fields[tool])throw Error('Unknown tool');const o={};for(const k of fields[tool]){if(k==='mode'){o[k]=raw[k]==='margin'?'margin':'markup';continue}if(raw[k]!==''&&raw[k]!=null&&Number.isFinite(Number(raw[k])))o[k]=Number(raw[k])}return o}
function need(i,k,min,max){if(i[k]==null||i[k]<min||i[k]>max)throw Error(`Invalid ${k}`);return i[k]}
function opt(i,k,min,max){return i[k]==null?null:need(i,k,min,max)}
function pack(tool,inputs,result){return{tool,version:1,inputs,result}}
export function calculate(tool,raw){const i=allowlist(tool,raw);
 if(tool==='labour-cost'){const wage=need(i,'wage',.01,1000),vac=need(i,'vacation',0,100),stat=need(i,'stat',0,100),cpp=need(i,'cpp',0,100),ei=need(i,'ei',0,100),wcb=need(i,'wcb',0,100),bill=need(i,'billable',.01,100),truck=need(i,'truck',0,10000),rate=opt(i,'rate',0,10000);const withLeave=wage*(1+(vac+stat)/100),withPayroll=withLeave*(1+(cpp+ei+wcb)/100),burden=(1+(vac+stat)/100)*(1+(cpp+ei+wcb)/100)-1,costPaid=wage*(1+burden)+truck,costBilled=costPaid/(bill/100);return pack(tool,i,{burden,withLeave,withPayroll,costPaid,costBilled,rate,difference:rate==null?null:Math.abs(rate-costBilled)})}
 if(tool==='markup-margin'){const percent=need(i,'percent',0,100000),cost=opt(i,'cost',0,1e8),mode=i.mode||'markup';let markup,margin;if(mode==='margin'){if(percent>=100)throw Error('Margin must be below 100');margin=percent/100;markup=margin/(1-margin)}else{markup=percent/100;margin=markup/(1+markup)}const price=cost==null?null:cost*(1+markup);return pack(tool,i,{mode,markup,margin,price,profit:price==null?null:price-cost})}
 if(tool==='break-even'){let fixed=0;for(const k of fields[tool].slice(0,6))fixed+=opt(i,k,0,1e8)||0;const rate=need(i,'rate',0,1e6),cost=need(i,'cost_per_billed_hour',0,1e6),contribution=rate-cost,hours=contribution>0?fixed/contribution:null;return pack(tool,i,{fixed,contribution,hours,perWeek:hours==null?null:hours*12/52})}
 if(tool==='mow-price'){const onsite=need(i,'onsite',.01,1e5),crew=need(i,'crew',1,1000),drive=need(i,'drive',0,1e5),cost=need(i,'cost_paid',0,1e6),margin=need(i,'margin',0,99.99),minimum=opt(i,'minimum',0,1e6)||0,labour=(onsite+drive)*crew*cost/60,price=labour/(1-margin/100),final=Math.max(price,minimum);return pack(tool,i,{labour,price,final,perOnsiteMinute:final/onsite,minimumApplied:minimum>price})}
 throw Error('Unknown tool')}
