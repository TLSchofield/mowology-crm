// pick.mjs — choose one shape for every brain stage 1–500 (owner, 2026-10-20). Run via brain-ladder.sh.
// From Monkey Mind's Shape Lab solveByFaceCount(N): named solids, geodesics and Sierpinski first, then the most
// interesting family not used in the last three stages; flat "lentil" solids left out; no exact shape → the next
// solid part-built. Spiritual symbols (hexagram, Sri Yantra, merkaba, pentagram) and plain fans are excluded.
import fs from 'fs';
const G = await import(process.env.WORK + '/gen.mjs');
const THREE = await import(process.env.WORK + '/three.module.js');
const SOL = JSON.parse(fs.readFileSync(process.env.WORK + '/solids.json'));
function trisOf(geo){const p=geo.attributes.position,idx=geo.index,n=idx?idx.count:p.count,T=[];for(let i=0;i<n;i+=3){const t=[];for(let j=0;j<3;j++){const k=idx?idx.getX(i+j):i+j;t.push([p.getX(k),p.getY(k),p.getZ(k)]);}T.push(t);}return T;}
function box(T){let mn=[1e9,1e9,1e9],mx=[-1e9,-1e9,-1e9];T.forEach(t=>t.forEach(v=>v.forEach((x,i)=>{mn[i]=Math.min(mn[i],x);mx[i]=Math.max(mx[i],x)})));return mx.map((x,i)=>x-mn[i]);}
const flat=T=>T.every(t=>t.every(v=>Math.abs(v[2])<1e-6));
// a 3D shape whose thinnest side is under 30% of its widest reads as a lentil when turned: leave it out
function lentil(T){if(flat(T))return false;const b=box(T).sort((a,c)=>a-c);return b[0]<0.3*b[2];}
const PRI=['deltahedron','geodesic','sierpinski','stellate','snowflake','whirlpool','droplet','ripple','mandala','rough','antiprism','bipyramid'];
const extra={12:'snub disphenoid',14:'triaugmented triangular prism',16:'gyroelongated square bipyramid'};
const memo={};
function cands(N){if(memo[N])return memo[N];let c=G.solveByFaceCount(N,{maxPerFamily:4,maxTotal:60}).filter(x=>x.build&&x.family!=='sacred'&&x.family!=='pyramid'&&!(x.family==='mandala'&&x.params.rings===1));
 if(extra[N])c.unshift({family:'deltahedron',label:extra[N],T:SOL[extra[N]]});
 const BASES=[['tetra',4,(d)=>new THREE.TetrahedronGeometry(1,d)],['octa',8,(d)=>new THREE.OctahedronGeometry(1,d)],['icosa',20,(d)=>new THREE.IcosahedronGeometry(1,d)]];
 for(const [nm,f,mk] of BASES)for(let d=1;d<=10;d++){const n=f*(d+1)*(d+1);
   if(n===N&&!c.some(x=>x.family==='geodesic'&&x.T===undefined&&x.label.includes(nm)))c.push({family:'geodesic',label:`${nm} geodesic, frequency ${d+1}`,expr:`${nm}:${d}`,build:()=>mk(d)});
   if(3*n===N)c.push({family:'stellate',label:`stellated ${nm} geodesic, frequency ${d+1}`,expr:`stellate(${nm}:${d})`,build:()=>G.stellate(mk(d),0.35)});}
 if(N===500)c.unshift({family:'geodesic',label:'geodesic sphere (icosahedron, detail 4)',expr:'new THREE.IcosahedronGeometry(1,4)',build:()=>new THREE.IcosahedronGeometry(1,4)});
 c.forEach(x=>{if(!x.T)x.T=trisOf(x.build())});
 c=c.filter(x=>x.T.length===N&&!lentil(x.T)).sort((a,b)=>PRI.indexOf(a.family)-PRI.indexOf(b.family));
 return memo[N]=c;}
const SOLIDF=['deltahedron','geodesic','stellate'];
const solidAt={};for(let N=4;N<=520;N++){const c=cands(N).find(x=>SOLIDF.includes(x.family));if(c)solidAt[N]={label:c.label,expr:c.expr||null,family:c.family};}
let recent=[],out=[];
for(let N=1;N<=500;N++){let p=null;
 if(N===1)p={family:'triangle',label:'one triangle'};
 else if(N===2)p={family:'open book',label:'open book'};
 else{const c=cands(N);const q=c.find(x=>['deltahedron','geodesic','sierpinski'].includes(x.family))||c.find(x=>!recent.includes(x.family));if(q)p={family:q.family,label:q.label,expr:q.expr||null,flat:flat(q.T)};}
 if(!p){const M=Object.keys(solidAt).map(Number).find(m=>m>N);p={family:'building',label:'building the '+solidAt[M].label,target:M,expr:solidAt[M].expr};}
 out.push({N,...p});recent=[p.family,...recent].slice(0,3);}
fs.writeFileSync(process.env.WORK + '/picks.json',JSON.stringify(out));
const fam={};out.forEach(o=>fam[o.family]=(fam[o.family]||0)+1);console.log(JSON.stringify(fam));
console.log(out.filter(o=>SOLIDF.includes(o.family)||o.family==='sierpinski').map(o=>o.N+':'+o.label).join(' | ').slice(0,1500));

