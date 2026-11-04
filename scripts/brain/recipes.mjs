// recipes.mjs — turn the picks into head-brain.js's short recipe per stage. Run via brain-ladder.sh.
import fs from 'fs';
const picks = JSON.parse(fs.readFileSync(process.env.WORK + '/picks.json'));
const DEL = {'tetrahedron':'T4','triangular bipyramid':'T6','octahedron':'T8','pentagonal bipyramid':'T10','snub disphenoid':'T12','triaugmented triangular prism':'T14','gyroelongated square bipyramid':'T16','icosahedron':'T20'};
function rec(expr, label){
  if (label && DEL[label]) return DEL[label];
  let m;
  if (!expr) throw new Error('no expr for '+label);
  if ((m=expr.match(/^stellate\((.*),0\.35\)$/))) return 's:'+rec(m[1]);
  if ((m=expr.match(/^stellate\((tetra|octa|icosa):(\d+)\)$/))) return 's:g:'+m[1]+','+(+m[2]+1);
  if ((m=expr.match(/^(tetra|octa|icosa):(\d+)$/))) return 'g:'+m[1]+','+(+m[2]+1);
  if ((m=expr.match(/^new THREE\.IcosahedronGeometry\(1,(\d+)\)$/))) return 'g:icosa,'+(+m[1]+1);
  if (expr==='new THREE.TetrahedronGeometry(1)') return 'T4';
  if (expr==='new THREE.OctahedronGeometry(1)') return 'T8';
  if (expr==='new THREE.IcosahedronGeometry(1)') return 'T20';
  if ((m=expr.match(/^sierpinskiGeo\((\d+)\)$/))) return 'si:'+m[1];
  if ((m=expr.match(/^snowflakeGeo\((\d+),(\d+)\)$/))) return 'sn:'+m[1]+','+m[2];
  if ((m=expr.match(/^whirlpoolGeo\((\d+),(\d+),(\d+),1\)$/))) return 'w:'+m[1]+','+m[2]+','+m[3];
  if ((m=expr.match(/^mandalaGeo\((\d+),(\d+)\)$/))) return 'm:'+m[1]+','+m[2];
  if ((m=expr.match(/^dropletGeo\((\d+)\)$/))) return 'd:'+m[1];
  if ((m=expr.match(/^jitterGeo\(bipyramid\((\d+),1\),0\.35,(\d+)\)$/))) return 'rb:'+m[1]+','+m[2];
  if ((m=expr.match(/^jitterGeo\(antiprism\((\d+),0\.6\),0\.35,(\d+)\)$/))) return 'ra:'+m[1]+','+m[2];
  if ((m=expr.match(/^bipyramid\((\d+),1\)$/))) return 'b:'+m[1];
  if ((m=expr.match(/^antiprism\((\d+),0\.6\)$/))) return 'a:'+m[1];
  throw new Error('unknown '+expr);
}
const out = picks.map(p => {
  if (p.N===1) return '1';
  if (p.N===2) return 'book';
  if (p.family==='building') return 'u:'+p.target;   // the next solid, part-built
  return rec(p.expr, p.label);
});
// building targets must themselves have a recipe
const targets=[...new Set(picks.filter(p=>p.family==='building').map(p=>p.target))];
const extra={}; for(const t of targets){ if(t>500){ throw new Error('target past 500: '+t);} }
fs.writeFileSync(process.env.WORK + '/recipes.json', JSON.stringify(out));
console.log(out.length, 'recipes;', new Set(out.map(r=>r.split(':')[0])).size, 'kinds');
console.log(out.slice(0,60).join(' '));
