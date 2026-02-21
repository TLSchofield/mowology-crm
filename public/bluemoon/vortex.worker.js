// vortex.worker.js — Masterclass Edition
// ─────────────────────────────────────────────────────────────────────────────
'use strict';

// ── Physics tunables ──────────────────────────────────────────────────────────
const ORBIT_SPEED      = 68;
const ORBIT_INNER_MUL  = 2.6;
const INWARD_SPEED     = 10;
const INWARD_INNER_MUL = 4.2;
const DONE_PULL        = 2.8;
const FLOCK_RADIUS     = 38;
const SEP_RADIUS       = 14;
const W_SEP            = 1.6;
const W_ALI            = 0.7;
const W_COH            = 0.5;
const MAX_SPEED        = 170;
const MAX_FORCE        = 130;
const DRAG             = 0.88;
const WALL_FORCE       = 320;
const HORIZON_FRAC     = 0.88;
const RESEED_INNER     = 0.78;
const GOLDEN           = 2.399963229728653;
const FLASH_DUR        = 700;

// Done-particle colors — vivid, saturated
const STAGE_COLORS = [
  [255,  80,  80, 245],   // fresh red
  [255, 140,  60, 238],   // orange
  [255, 210,  70, 232],   // gold
  [140, 240, 100, 226],   // green
  [ 60, 220, 220, 226],   // cyan
  [100, 160, 255, 232],   // blue
  [160, 100, 255, 232],   // purple
];

// ── Helpers ───────────────────────────────────────────────────────────────────
const clamp = (n, a, b) => Math.max(a, Math.min(b, n));

function limitMag(ax, ay, max) {
  const m2 = ax*ax + ay*ay;
  if (m2 > max*max) { const s = max/Math.sqrt(m2); return [ax*s, ay*s]; }
  return [ax, ay];
}

function hash01(i) {
  let x = (i + 1) * 2654435761;
  x ^= x << 13; x ^= x >> 17; x ^= x << 5;
  return ((x >>> 0) % 100000) / 100000;
}

// ── Particle creation ─────────────────────────────────────────────────────────
function createParticles(count, wellR, outerR, cx, cy) {
  const n = Math.max(1, Math.floor(count));
  const span = Math.max(1, outerR - wellR);
  const out = [];
  for (let i = 0; i < n; i++) {
    const u1=hash01(i*7+1), u2=hash01(i*7+2), u3=hash01(i*7+3);
    const r     = wellR + Math.sqrt(u2) * span;
    const theta = u1 * Math.PI * 2;
    const px = cx + Math.cos(theta) * r;
    const py = cy + Math.sin(theta) * r;
    const norm   = Math.min(1, (r-wellR)/span);
    const tSpeed = ORBIT_SPEED*(1+ORBIT_INNER_MUL*(1-norm))*(0.6+0.6*u3);
    const vx=-Math.sin(theta)*tSpeed, vy=Math.cos(theta)*tSpeed;
    out.push({
      x:px, y:py, vx, vy,
      // trail history (ring buffer)
      tx: new Float32Array(12), ty: new Float32Array(12), trailHead: 0, trailFill: 0,
      wobbleA:1.0+2.5*hash01(i+777), wobbleF:0.4+1.4*hash01(i+333), wobbleP:Math.PI*2*hash01(i+555),
      done:false, ageSteps:0, colorIndex:0, streakGroupId:'', ordinal:0,
      size: 0.7 + 1.3*hash01(i+919),
    });
  }
  return out;
}

// ── Physics tick ──────────────────────────────────────────────────────────────
function tickVortex(particles, dt, wellR, outerR, timeSec, cx, cy) {
  const span   = Math.max(1, outerR - wellR);
  const horizon= wellR * HORIZON_FRAC;
  const n      = particles.length;
  const drag   = Math.pow(DRAG, dt);

  for (let i = 0; i < n; i++) {
    const p = particles[i];
    const dx=p.x-cx, dy=p.y-cy;
    const r = Math.sqrt(dx*dx+dy*dy);
    if (r < 0.5) continue;
    const ix=-dx/r, iy=-dy/r;

    const norm=clamp((r-wellR)/span,0,1), proximity=1-norm;
    const tx=-dy/r, ty=dx/r;
    const orbitV =ORBIT_SPEED *(1+ORBIT_INNER_MUL *proximity*proximity);
    const inwardV=INWARD_SPEED*(1+INWARD_INNER_MUL*proximity*proximity);
    let pullMul=1;
    if (p.done) { const af=1+Math.min(p.ageSteps,7)/7; pullMul=DONE_PULL*af; }
    const tvx=tx*orbitV+ix*inwardV*pullMul, tvy=ty*orbitV+iy*inwardV*pullMul;
    let fvx=(tvx-p.vx)*6.0, fvy=(tvy-p.vy)*6.0;
    ;[fvx,fvy]=limitMag(fvx,fvy,MAX_FORCE*3);

    let sepX=0,sepY=0,sepN=0, aliX=0,aliY=0,aliN=0, cohX=0,cohY=0,cohN=0;
    if (p.done) {
      for (let j=0;j<n;j++) {
        if (j===i) continue;
        const q=particles[j],ex=p.x-q.x,ey=p.y-q.y,d2=ex*ex+ey*ey;
        if (d2<FLOCK_RADIUS*FLOCK_RADIUS) {
          const d=Math.sqrt(d2); cohX+=q.x;cohY+=q.y;cohN++; aliX+=q.vx;aliY+=q.vy;aliN++;
          if (d2<SEP_RADIUS*SEP_RADIUS&&d>0.01){sepX+=(ex/d)*(SEP_RADIUS-d);sepY+=(ey/d)*(SEP_RADIUS-d);sepN++;}
        }
      }
    } else {
      const QS=SEP_RADIUS*0.8;
      for (let j=Math.max(0,i-4);j<Math.min(n,i+5);j++) {
        if (j===i) continue;
        const q=particles[j],ex=p.x-q.x,ey=p.y-q.y,d2=ex*ex+ey*ey;
        if (d2<QS*QS&&d2>0.0001){const d=Math.sqrt(d2);sepX+=(ex/d)*(QS-d);sepY+=(ey/d)*(QS-d);sepN++;}
      }
    }
    if (sepN>0){let[sx,sy]=limitMag(sepX,sepY,MAX_FORCE);fvx+=sx*W_SEP;fvy+=sy*W_SEP;}
    if (aliN>0){let[ax,ay]=limitMag(aliX/aliN-p.vx,aliY/aliN-p.vy,MAX_FORCE);fvx+=ax*W_ALI;fvy+=ay*W_ALI;}
    if (cohN>0){let[cx2,cy2]=limitMag(cohX/cohN-p.x,cohY/cohN-p.y,MAX_FORCE);fvx+=cx2*W_COH;fvy+=cy2*W_COH;}
    if (r>outerR){const over=r-outerR;fvx+=ix*WALL_FORCE*(over/span+1);fvy+=iy*WALL_FORCE*(over/span+1);}

    p.vx=(p.vx+fvx*dt)*drag; p.vy=(p.vy+fvy*dt)*drag;
    ;[p.vx,p.vy]=limitMag(p.vx,p.vy,MAX_SPEED);

    // record trail
    p.tx[p.trailHead]=p.x; p.ty[p.trailHead]=p.y;
    p.trailHead=(p.trailHead+1)%12;
    if(p.trailFill<12) p.trailFill++;

    p.x+=p.vx*dt; p.y+=p.vy*dt;

    const ndx=p.x-cx,ndy=p.y-cy,newR=Math.sqrt(ndx*ndx+ndy*ndy);
    if (newR<horizon) {
      const ra=((i*GOLDEN)+timeSec*0.09)%(Math.PI*2);
      const band=i%5, bf=RESEED_INNER+(1-RESEED_INNER)*(band/5), rr=wellR+bf*span;
      p.x=cx+Math.cos(ra)*rr; p.y=cy+Math.sin(ra)*rr;
      const ts=ORBIT_SPEED*(1+ORBIT_INNER_MUL*(1-bf)*(1-bf));
      p.vx=-Math.sin(ra)*ts; p.vy=Math.cos(ra)*ts;
      p.trailFill=0;
    }
  }
}

// ── Worker state ──────────────────────────────────────────────────────────────
let ctx = null, offscreenCanvas = null;
let W=0, H=0, cx=0, cy=0, wellR=0, outerR=0, horizonR=0;
let particles=[], prevDist=new Float32Array(0);
const flashes=[];
let lastTime=0, rafId=null, dpr=1;
let nebulaTime=0;

function buildEventMeta(events) {
  const DAY=86400000, now=Date.now();
  return [...events].sort((a,b)=>a.ts-b.ts).map((ev,i)=>{
    const ageDays=Math.floor((now-ev.ts)/DAY), ageSteps=Math.min(ageDays,6);
    return { habitId:ev.habitId, ageSteps, colorIndex:ageSteps, streakGroupId:ev.habitId, ordinal:i };
  });
}

function syncEvents(events) {
  const metas=buildEventMeta(events);
  for (let i=0;i<particles.length;i++) {
    const p=particles[i];
    if (i<metas.length) {
      const m=metas[i], justEarned=!p.done;
      if (justEarned) {
        const angle=Math.random()*Math.PI*2, spawnR=outerR-6;
        p.x=cx+Math.cos(angle)*spawnR; p.y=cy+Math.sin(angle)*spawnR;
        p.vx=-Math.sin(angle)*130; p.vy=Math.cos(angle)*130;
      }
      p.done=true; p.ageSteps=m.ageSteps; p.colorIndex=m.colorIndex;
      p.streakGroupId=m.streakGroupId; p.ordinal=m.ordinal;
    } else {
      p.done=false; p.ageSteps=0; p.colorIndex=0; p.streakGroupId=''; p.ordinal=0;
    }
  }
}

function initLayout(offscreen, width, height, devicePixelRatio, events) {
  dpr=devicePixelRatio||1; W=width; H=height;
  offscreen.width=Math.round(W*dpr); offscreen.height=Math.round(H*dpr);
  offscreenCanvas=offscreen; ctx=offscreen.getContext('2d');
  const base=Math.min(W,H);
  cx=W*0.5; cy=H*0.5;
  wellR=base*0.10; outerR=base*0.42; horizonR=wellR;
  particles=createParticles(900,wellR,outerR,cx,cy);
  prevDist=new Float32Array(particles.length);
  syncEvents(events); startLoop();
}

// ── Nebula background ─────────────────────────────────────────────────────────
function drawNebula(t) {
  // Deep space base
  const bg = ctx.createRadialGradient(cx,cy,0, cx,cy,Math.max(W,H)*0.75);
  bg.addColorStop(0,  'rgba(4,  8, 28, 1)');
  bg.addColorStop(0.4,'rgba(2,  5, 18, 1)');
  bg.addColorStop(1,  'rgba(0,  0,  6, 1)');
  ctx.fillStyle=bg; ctx.fillRect(0,0,W,H);

  // Drifting nebula clouds
  const clouds = [
    { ox:0.28, oy:0.20, rx:0.42, ry:0.30, r:80, g:30,  b:140, a:0.055, spd:0.018 },
    { ox:0.72, oy:0.65, rx:0.38, ry:0.28, r:20, g:60,  b:150, a:0.045, spd:0.011 },
    { ox:0.18, oy:0.72, rx:0.35, ry:0.22, r:60, g:20,  b:100, a:0.040, spd:0.022 },
    { ox:0.78, oy:0.22, rx:0.30, ry:0.25, r:30, g:80,  b:160, a:0.038, spd:0.015 },
    { ox:0.50, oy:0.50, rx:0.60, ry:0.50, r:10, g:20,  b: 80, a:0.028, spd:0.007 },
  ];
  ctx.save();
  ctx.globalCompositeOperation='screen';
  for (const c of clouds) {
    const px = (c.ox + Math.sin(t*c.spd + c.ox*6)*0.06) * W;
    const py = (c.oy + Math.cos(t*c.spd*0.7 + c.oy*5)*0.05) * H;
    const gr = ctx.createRadialGradient(px,py,0, px,py,Math.min(W,H)*Math.max(c.rx,c.ry));
    gr.addColorStop(0,   `rgba(${c.r},${c.g},${c.b},${c.a})`);
    gr.addColorStop(0.5, `rgba(${c.r},${c.g},${c.b},${(c.a*0.4).toFixed(4)})`);
    gr.addColorStop(1,   'rgba(0,0,0,0)');
    ctx.fillStyle=gr; ctx.fillRect(0,0,W,H);
  }
  ctx.restore();

  // Star field — static, drawn once via math
  ctx.save();
  ctx.globalCompositeOperation='screen';
  ctx.fillStyle='rgba(255,255,255,0.9)';
  for (let i=0;i<320;i++) {
    const sx=hash01(i*3+1)*W, sy=hash01(i*3+2)*H;
    const sr=0.25+hash01(i*3+3)*0.9;
    const twinkle=0.4+0.6*Math.abs(Math.sin(t*0.8+i*1.37));
    ctx.globalAlpha=twinkle*(0.3+hash01(i*3)*0.55);
    ctx.beginPath(); ctx.arc(sx,sy,sr,0,Math.PI*2); ctx.fill();
  }
  ctx.globalAlpha=1;
  ctx.restore();
}

// ── Accretion disk glow ───────────────────────────────────────────────────────
function drawAccretionDisk(t) {
  const span = outerR - wellR;

  // Outer disk ambient glow — wide, faint blue haze
  ctx.save();
  ctx.globalCompositeOperation='screen';
  const haze = ctx.createRadialGradient(cx,cy,wellR*0.8, cx,cy,outerR*1.35);
  haze.addColorStop(0,   'rgba(40, 80,200, 0.00)');
  haze.addColorStop(0.25,'rgba(40, 80,200, 0.06)');
  haze.addColorStop(0.55,'rgba(20, 50,160, 0.09)');
  haze.addColorStop(0.80,'rgba(10, 30,100, 0.04)');
  haze.addColorStop(1,   'rgba(0,  0,  0, 0.00)');
  ctx.fillStyle=haze; ctx.beginPath(); ctx.arc(cx,cy,outerR*1.35,0,Math.PI*2); ctx.fill();
  ctx.restore();

  // Density wave rings — 3 bands pulsing
  ctx.save();
  ctx.globalCompositeOperation='screen';
  for (let band=0;band<3;band++) {
    const phase = t*0.4 + band*Math.PI*0.66;
    const pulse = 0.5 + 0.5*Math.sin(phase);
    const r1 = wellR + span*(0.20 + band*0.25);
    const r2 = r1 + span*0.10;
    const alpha = (0.04 + 0.06*pulse).toFixed(4);
    const wave = ctx.createRadialGradient(cx,cy,r1, cx,cy,r2);
    wave.addColorStop(0,   `rgba(80,140,255,0)`);
    wave.addColorStop(0.4, `rgba(80,140,255,${alpha})`);
    wave.addColorStop(1,   `rgba(80,140,255,0)`);
    ctx.fillStyle=wave;
    ctx.beginPath(); ctx.arc(cx,cy,r2,0,Math.PI*2); ctx.fill();
  }
  ctx.restore();

  // Hot inner ring — warm orange/white near event horizon
  ctx.save();
  ctx.globalCompositeOperation='screen';
  const hotR1 = wellR*1.0, hotR2 = wellR*2.8;
  const hot = ctx.createRadialGradient(cx,cy,hotR1, cx,cy,hotR2);
  const pulse2 = 0.5+0.5*Math.sin(t*1.1);
  hot.addColorStop(0,   'rgba(255,200,120, 0.00)');
  hot.addColorStop(0.3, `rgba(255,180, 80, ${(0.12+0.06*pulse2).toFixed(4)})`);
  hot.addColorStop(0.7, 'rgba(200,120, 40, 0.06)');
  hot.addColorStop(1,   'rgba(0,0,0,0)');
  ctx.fillStyle=hot; ctx.beginPath(); ctx.arc(cx,cy,hotR2,0,Math.PI*2); ctx.fill();
  ctx.restore();
}

// ── Photon ring & gravitational lensing arcs ──────────────────────────────────
function drawLensing(t) {
  ctx.save();
  ctx.globalCompositeOperation='screen';

  // Photon ring — bright thin arc just outside event horizon
  const pR = wellR * 1.12;
  const pulse = 0.7 + 0.3*Math.sin(t*2.3);
  ctx.strokeStyle = `rgba(255,240,200,${(0.22*pulse).toFixed(4)})`;
  ctx.lineWidth = 1.2;
  ctx.shadowColor = 'rgba(255,220,120,0.6)';
  ctx.shadowBlur = 8;
  ctx.beginPath(); ctx.arc(cx,cy,pR,0,Math.PI*2); ctx.stroke();
  ctx.shadowBlur=0;

  // Secondary photon ring
  ctx.strokeStyle = `rgba(180,200,255,${(0.10*pulse).toFixed(4)})`;
  ctx.lineWidth = 0.6;
  ctx.beginPath(); ctx.arc(cx,cy,pR*1.06,0,Math.PI*2); ctx.stroke();

  // Lensing arcs — 3 warped light streaks curving around BH
  for (let arc=0;arc<3;arc++) {
    const baseAngle = t*0.15 + arc*(Math.PI*2/3);
    const arcR = wellR * (1.3 + arc*0.15);
    const sweep = Math.PI * (0.35 + 0.15*Math.sin(t*0.3+arc));
    ctx.strokeStyle = `rgba(140,180,255,${(0.07+arc*0.02).toFixed(4)})`;
    ctx.lineWidth = 0.8 - arc*0.15;
    ctx.beginPath();
    ctx.arc(cx, cy, arcR, baseAngle, baseAngle+sweep);
    ctx.stroke();
    // mirror arc
    ctx.beginPath();
    ctx.arc(cx, cy, arcR, baseAngle+Math.PI, baseAngle+Math.PI+sweep*0.7);
    ctx.stroke();
  }
  ctx.restore();
}

// ── Black hole ────────────────────────────────────────────────────────────────
function drawBlackHole(t) {
  ctx.save();

  // Outer glow ring
  ctx.globalCompositeOperation='screen';
  const outerGlow = ctx.createRadialGradient(cx,cy,wellR*0.6, cx,cy,wellR*3.0);
  outerGlow.addColorStop(0,   'rgba(60,100,200,0.00)');
  outerGlow.addColorStop(0.4, 'rgba(60,100,200,0.07)');
  outerGlow.addColorStop(0.7, 'rgba(30, 60,160,0.04)');
  outerGlow.addColorStop(1,   'rgba(0,0,0,0)');
  ctx.fillStyle=outerGlow; ctx.beginPath(); ctx.arc(cx,cy,wellR*3,0,Math.PI*2); ctx.fill();
  ctx.globalCompositeOperation='source-over';

  // Void — pure black
  ctx.fillStyle='#000'; ctx.beginPath(); ctx.arc(cx,cy,wellR,0,Math.PI*2); ctx.fill();
  ctx.fillStyle='rgba(0,0,0,0.85)'; ctx.beginPath(); ctx.arc(cx,cy,wellR*0.92,0,Math.PI*2); ctx.fill();
  ctx.fillStyle='rgba(0,0,0,0.95)'; ctx.beginPath(); ctx.arc(cx,cy,wellR*0.78,0,Math.PI*2); ctx.fill();
  ctx.fillStyle='#000'; ctx.beginPath(); ctx.arc(cx,cy,wellR*0.55,0,Math.PI*2); ctx.fill();

  ctx.restore();
}

// ── Main frame ────────────────────────────────────────────────────────────────
function frame(nowMs) {
  const dt = lastTime===0 ? 0.016 : Math.min(0.05,(nowMs-lastTime)/1000);
  lastTime = nowMs;
  nebulaTime += dt;
  const t = nebulaTime;

  tickVortex(particles, dt, wellR, outerR, nowMs/1000, cx, cy);

  ctx.clearRect(0,0,W*dpr,H*dpr);
  ctx.save();
  ctx.scale(dpr,dpr);

  // ── Background ──
  drawNebula(t);
  drawAccretionDisk(t);
  drawLensing(t);

  const span=Math.max(1,outerR-wellR);
  if (prevDist.length!==particles.length) prevDist=new Float32Array(particles.length);

  // ── Constellation lines ──
  ctx.save();
  ctx.globalCompositeOperation='screen';
  const LINK_DIST=55, LINK_DIST2=LINK_DIST*LINK_DIST;
  const linkCounts=new Uint8Array(particles.length);
  ctx.lineCap='round';
  for (let i=0;i<particles.length;i++) {
    const p=particles[i]; if (p.done) continue;
    const pdx=p.x-cx, pdy=p.y-cy;
    if (pdx*pdx+pdy*pdy < (horizonR+2)*(horizonR+2)) continue;
    if (linkCounts[i]>=3) continue;
    for (let j=i+1;j<particles.length;j++) {
      if (particles[j].done) continue;
      if (linkCounts[i]>=3||linkCounts[j]>=3) continue;
      const qdx=particles[j].x-cx, qdy=particles[j].y-cy;
      if (qdx*qdx+qdy*qdy<(horizonR+2)*(horizonR+2)) continue;
      const ex=p.x-particles[j].x, ey=p.y-particles[j].y, d2=ex*ex+ey*ey;
      if (d2>LINK_DIST2) continue;
      const frac=1-Math.sqrt(d2)/LINK_DIST;
      // proximity tint — deeper blue closer to center
      const avgDist=Math.sqrt(((p.x+particles[j].x)*0.5-cx)**2+((p.y+particles[j].y)*0.5-cy)**2);
      const proximity=clamp(1-(avgDist-wellR)/span,0,1);
      const alpha=(frac*frac*(0.18+proximity*0.14)).toFixed(4);
      const blue=Math.round(200+proximity*55);
      ctx.strokeStyle=`rgba(160,${Math.round(180+proximity*40)},${blue},${alpha})`;
      ctx.lineWidth=0.5+proximity*0.5;
      ctx.beginPath(); ctx.moveTo(p.x,p.y); ctx.lineTo(particles[j].x,particles[j].y); ctx.stroke();
      linkCounts[i]++; linkCounts[j]++;
    }
  }
  ctx.restore();

  // ── Particle trails ──
  ctx.save();
  ctx.globalCompositeOperation='screen';
  for (let i=0;i<particles.length;i++) {
    const p=particles[i];
    if (p.trailFill<2) continue;
    const dx=p.x-cx, dy=p.y-cy, dist=Math.sqrt(dx*dx+dy*dy);
    if (dist<horizonR+2) continue;
    const norm=clamp((dist-wellR)/span,0,1);
    const speed=Math.sqrt(p.vx*p.vx+p.vy*p.vy);
    const speedNorm=clamp(speed/MAX_SPEED,0,1);
    const trailLen=Math.min(p.trailFill, 3+Math.floor(speedNorm*6));
    if (p.done) continue; // done particles use glow instead
    for (let s=0;s<trailLen-1;s++) {
      const idxA=(p.trailHead-1-s+12)%12;
      const idxB=(p.trailHead-2-s+12)%12;
      const t0=1-(s/trailLen);
      const alpha=(t0*t0*0.22*(1-norm*0.5)).toFixed(4);
      ctx.strokeStyle=`rgba(180,210,255,${alpha})`;
      ctx.lineWidth=0.6+speedNorm*0.5;
      ctx.beginPath();
      ctx.moveTo(p.tx[idxA],p.ty[idxA]);
      ctx.lineTo(p.tx[idxB],p.ty[idxB]);
      ctx.stroke();
    }
  }
  ctx.restore();

  // ── Undone particles ──
  ctx.save();
  ctx.globalCompositeOperation='screen';
  for (let i=0;i<particles.length;i++) {
    const p=particles[i]; if (p.done) continue;
    const dx=p.x-cx, dy=p.y-cy, dist=Math.sqrt(dx*dx+dy*dy);
    const prev=prevDist[i];
    if (prev>0 && prev<horizonR*1.4 && dist>outerR*0.65) {
      flashes.push({x:p.x,y:p.y,born:nowMs,r:255,g:255,b:255});
      if (flashes.length>120) flashes.splice(0,30);
    }
    prevDist[i]=dist;
    if (dist<horizonR+2) continue;
    const norm=clamp((dist-wellR)/span,0,1);
    const proximity=1-norm;
    const r=p.size*(1.8+proximity*1.2);
    const alpha=0.45+proximity*0.45;

    // Soft outer glow
    const glowA=(alpha*0.12).toFixed(4);
    ctx.fillStyle=`rgba(180,210,255,${glowA})`;
    ctx.beginPath(); ctx.arc(p.x,p.y,r*2.5,0,Math.PI*2); ctx.fill();
    // Core
    ctx.fillStyle=`rgba(220,235,255,${alpha.toFixed(4)})`;
    ctx.beginPath(); ctx.arc(p.x,p.y,r,0,Math.PI*2); ctx.fill();
    // Bright center
    ctx.fillStyle=`rgba(255,255,255,${Math.min(1,alpha+0.3).toFixed(4)})`;
    ctx.beginPath(); ctx.arc(p.x,p.y,r*0.4,0,Math.PI*2); ctx.fill();
  }
  ctx.restore();

  // ── Flash events ──
  ctx.save();
  ctx.globalCompositeOperation='screen';
  for (let i=flashes.length-1;i>=0;i--) {
    const fl=flashes[i], age=nowMs-fl.born;
    if (age>=FLASH_DUR){flashes.splice(i,1);continue;}
    const t2=age/FLASH_DUR, ease=1-t2*t2*t2;
    // expanding ring
    const ringR=wellR*0.3+t2*outerR*0.35;
    const ringA=(ease*0.20).toFixed(4);
    ctx.strokeStyle=`rgba(200,230,255,${ringA})`; ctx.lineWidth=1.5-t2;
    ctx.beginPath(); ctx.arc(fl.x,fl.y,ringR,0,Math.PI*2); ctx.stroke();
    // bright core flash
    const coreA=(ease*0.85).toFixed(4);
    ctx.fillStyle=`rgba(255,255,255,${coreA})`;
    ctx.beginPath(); ctx.arc(fl.x,fl.y,2+t2*3,0,Math.PI*2); ctx.fill();
    // warm bloom
    const bloomA=(ease*0.30).toFixed(4);
    ctx.fillStyle=`rgba(255,200,100,${bloomA})`;
    ctx.beginPath(); ctx.arc(fl.x,fl.y,4+t2*12,0,Math.PI*2); ctx.fill();
  }
  ctx.restore();

  // ── Done particles — multi-layer bloom ──
  ctx.save();
  ctx.globalCompositeOperation='screen';
  for (let i=0;i<particles.length;i++) {
    const p=particles[i]; if (!p.done) continue;
    const dx=p.x-cx, dy=p.y-cy, dist=Math.sqrt(dx*dx+dy*dy);
    if (dist<horizonR+2) continue;
    const norm=clamp((dist-wellR)/span,0,1), proximity=1-norm;
    const ci=clamp(p.colorIndex,0,6);
    const [cr,cg,cb]=STAGE_COLORS[ci];
    const baseR=(2.8+proximity*2.5)*p.size;

    // Wide outer corona
    ctx.fillStyle=`rgba(${cr},${cg},${cb},0.04)`;
    ctx.beginPath(); ctx.arc(p.x,p.y,baseR*5.0,0,Math.PI*2); ctx.fill();
    // Mid bloom
    ctx.fillStyle=`rgba(${cr},${cg},${cb},0.10)`;
    ctx.beginPath(); ctx.arc(p.x,p.y,baseR*2.8,0,Math.PI*2); ctx.fill();
    // Inner glow
    ctx.fillStyle=`rgba(${cr},${cg},${cb},0.28)`;
    ctx.beginPath(); ctx.arc(p.x,p.y,baseR*1.6,0,Math.PI*2); ctx.fill();
    // Core
    ctx.fillStyle=`rgba(${cr},${cg},${cb},0.85)`;
    ctx.beginPath(); ctx.arc(p.x,p.y,baseR,0,Math.PI*2); ctx.fill();
    // Bright white center
    ctx.fillStyle=`rgba(255,255,255,0.70)`;
    ctx.beginPath(); ctx.arc(p.x,p.y,baseR*0.38,0,Math.PI*2); ctx.fill();
  }
  ctx.restore();

  // ── Black hole on top ──
  drawBlackHole(t);

  // ── Vignette ──
  ctx.save();
  const vig=ctx.createRadialGradient(cx,cy,outerR*0.6, cx,cy,Math.max(W,H)*0.75);
  vig.addColorStop(0,'rgba(0,0,0,0)');
  vig.addColorStop(1,'rgba(0,0,10,0.55)');
  ctx.fillStyle=vig; ctx.fillRect(0,0,W,H);
  ctx.restore();

  ctx.restore(); // main dpr scale
  rafId=requestAnimationFrame(frame);
}

function startLoop() {
  if (rafId) cancelAnimationFrame(rafId);
  lastTime=0;
  rafId=requestAnimationFrame(frame);
}

// ── Message handler ───────────────────────────────────────────────────────────
self.onmessage = (e) => {
  const { type } = e.data;
  if (type==='init') {
    const { canvas, width, height, devicePixelRatio, events } = e.data;
    initLayout(canvas, width, height, devicePixelRatio, events);
  } else if (type==='events') {
    syncEvents(e.data.events);
  } else if (type==='resize') {
    const { width, height, devicePixelRatio } = e.data;
    dpr=devicePixelRatio||1; W=width; H=height;
    if (offscreenCanvas) {
      offscreenCanvas.width=Math.round(W*dpr);
      offscreenCanvas.height=Math.round(H*dpr);
    }
    const base=Math.min(W,H);
    cx=W*0.5; cy=H*0.5; wellR=base*0.10; outerR=base*0.42; horizonR=wellR;
    particles=createParticles(900,wellR,outerR,cx,cy);
    prevDist=new Float32Array(particles.length);
    syncEvents(e.data.events||[]);
  }
};
