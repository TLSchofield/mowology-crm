// vortex.fallback.js
// Main-thread Canvas2D fallback for browsers without OffscreenCanvas (iOS Safari).
// Same physics and rendering as the worker, runs on main thread.
'use strict';

(function(){

const ORBIT_SPEED=65,ORBIT_INNER_MUL=2.4,INWARD_SPEED=9,INWARD_INNER_MUL=4.0,DONE_PULL=2.8;
const FLOCK_RADIUS=38,SEP_RADIUS=14,W_SEP=1.6,W_ALI=0.7,W_COH=0.5;
const MAX_SPEED=160,MAX_FORCE=120,DRAG=0.88,WALL_FORCE=320,HORIZON_FRAC=0.88,RESEED_INNER=0.78;
const GOLDEN=2.399963229728653,FLASH_DUR=500;
const STAGE_COLORS=[[255,90,90,242],[255,145,90,235],[255,205,90,230],[170,235,120,224],[90,220,210,224],[120,170,255,230],[140,120,255,230]];

const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
function limitMag(ax,ay,max){const m2=ax*ax+ay*ay;if(m2>max*max){const s=max/Math.sqrt(m2);return[ax*s,ay*s];}return[ax,ay];}
function hash01(i){let x=(i+1)*2654435761;x^=x<<13;x^=x>>17;x^=x<<5;return((x>>>0)%100000)/100000;}

function createParticles(count,wellR,outerR,cx,cy){
  const n=Math.max(1,Math.floor(count)),span=Math.max(1,outerR-wellR),out=[];
  for(let i=0;i<n;i++){
    const u1=hash01(i*7+1),u2=hash01(i*7+2),u3=hash01(i*7+3),u4=hash01(i*7+4),u5=hash01(i*7+5);
    const r=wellR+Math.sqrt(u2)*span,theta=u1*Math.PI*2;
    const px=cx+Math.cos(theta)*r,py=cy+Math.sin(theta)*r;
    const gravityFree=u4<0.80;let vx,vy;
    if(gravityFree){const ds=12+u5*22,da=u3*Math.PI*2;vx=Math.cos(da)*ds;vy=Math.sin(da)*ds;}
    else{const norm=Math.min(1,(r-wellR)/span),ts=ORBIT_SPEED*(1+ORBIT_INNER_MUL*(1-norm))*(0.6+0.6*u3);vx=-Math.sin(theta)*ts;vy=Math.cos(theta)*ts;}
    out.push({x:px,y:py,vx,vy,wobbleA:1+2.5*hash01(i+777),wobbleF:0.4+1.4*hash01(i+333),wobbleP:Math.PI*2*hash01(i+555),gravityFree,done:false,ageSteps:0,colorIndex:0,streakGroupId:'',ordinal:0});
  }
  return out;
}

function tickVortex(particles,dt,wellR,outerR,timeSec,cx,cy){
  const eff_dt=dt,span=Math.max(1,outerR-wellR),horizon=wellR*HORIZON_FRAC,n=particles.length,drag=Math.pow(DRAG,eff_dt);
  for(let i=0;i<n;i++){
    const p=particles[i],dx=p.x-cx,dy=p.y-cy,r=Math.sqrt(dx*dx+dy*dy);
    if(r<0.5)continue;
    const ix=-dx/r,iy=-dy/r;
    if(p.gravityFree&&!p.done){
      const fd=Math.pow(0.995,eff_dt*60);let fvx=0,fvy=0;
      if(r>outerR){const o=r-outerR;fvx+=ix*WALL_FORCE*(o/span+1);fvy+=iy*WALL_FORCE*(o/span+1);}
      if(r<wellR*1.3){const push=(wellR*1.3-r)/(wellR*0.3);fvx-=ix*WALL_FORCE*push;fvy-=iy*WALL_FORCE*push;}
      const QS=SEP_RADIUS*0.8;
      for(let j=Math.max(0,i-4);j<Math.min(n,i+5);j++){if(j===i)continue;const q=particles[j],ex=p.x-q.x,ey=p.y-q.y,d2=ex*ex+ey*ey;if(d2<QS*QS&&d2>0.0001){const d=Math.sqrt(d2),sf=(QS-d)*W_SEP;fvx+=(ex/d)*sf;fvy+=(ey/d)*sf;}}
      p.vx=(p.vx+fvx*eff_dt)*fd;p.vy=(p.vy+fvy*eff_dt)*fd;
      ;[p.vx,p.vy]=limitMag(p.vx,p.vy,MAX_SPEED*0.5);p.x+=p.vx*eff_dt;p.y+=p.vy*eff_dt;continue;
    }
    const norm=clamp((r-wellR)/span,0,1),proximity=1-norm,tx=-dy/r,ty=dx/r;
    const orbitV=ORBIT_SPEED*(1+ORBIT_INNER_MUL*proximity*proximity),inwardV=INWARD_SPEED*(1+INWARD_INNER_MUL*proximity*proximity);
    let pullMul=1;if(p.done){const af=1+Math.min(p.ageSteps,7)/7;pullMul=DONE_PULL*af;}
    const tvx=tx*orbitV+ix*inwardV*pullMul,tvy=ty*orbitV+iy*inwardV*pullMul;
    let fvx=(tvx-p.vx)*6,fvy=(tvy-p.vy)*6;
    ;[fvx,fvy]=limitMag(fvx,fvy,MAX_FORCE*3);
    let sepX=0,sepY=0,sepN=0,aliX=0,aliY=0,aliN=0,cohX=0,cohY=0,cohN=0;
    if(p.done){for(let j=0;j<n;j++){if(j===i)continue;const q=particles[j],ex=p.x-q.x,ey=p.y-q.y,d2=ex*ex+ey*ey;if(d2<FLOCK_RADIUS*FLOCK_RADIUS){const d=Math.sqrt(d2);cohX+=q.x;cohY+=q.y;cohN++;aliX+=q.vx;aliY+=q.vy;aliN++;if(d2<SEP_RADIUS*SEP_RADIUS&&d>0.01){sepX+=(ex/d)*(SEP_RADIUS-d);sepY+=(ey/d)*(SEP_RADIUS-d);sepN++;}}}}
    else{const QS=SEP_RADIUS*0.8;for(let j=Math.max(0,i-4);j<Math.min(n,i+5);j++){if(j===i)continue;const q=particles[j],ex=p.x-q.x,ey=p.y-q.y,d2=ex*ex+ey*ey;if(d2<QS*QS&&d2>0.0001){const d=Math.sqrt(d2);sepX+=(ex/d)*(QS-d);sepY+=(ey/d)*(QS-d);sepN++;}}}
    if(sepN>0){let[sx,sy]=limitMag(sepX,sepY,MAX_FORCE);fvx+=sx*W_SEP;fvy+=sy*W_SEP;}
    if(aliN>0){let[ax,ay]=limitMag(aliX/aliN-p.vx,aliY/aliN-p.vy,MAX_FORCE);fvx+=ax*W_ALI;fvy+=ay*W_ALI;}
    if(cohN>0){let[cx2,cy2]=limitMag(cohX/cohN-p.x,cohY/cohN-p.y,MAX_FORCE);fvx+=cx2*W_COH;fvy+=cy2*W_COH;}
    if(r>outerR){const o=r-outerR;fvx+=ix*WALL_FORCE*(o/span+1);fvy+=iy*WALL_FORCE*(o/span+1);}
    p.vx=(p.vx+fvx*eff_dt)*drag;p.vy=(p.vy+fvy*eff_dt)*drag;
    ;[p.vx,p.vy]=limitMag(p.vx,p.vy,MAX_SPEED);p.x+=p.vx*eff_dt;p.y+=p.vy*eff_dt;
    const ndx=p.x-cx,ndy=p.y-cy,newR=Math.sqrt(ndx*ndx+ndy*ndy);
    if(newR<horizon){const ra=((i*GOLDEN)+timeSec*0.09)%(Math.PI*2),band=i%5,bf=RESEED_INNER+(1-RESEED_INNER)*(band/5),rr=wellR+bf*span;p.x=cx+Math.cos(ra)*rr;p.y=cy+Math.sin(ra)*rr;const ts=ORBIT_SPEED*(1+ORBIT_INNER_MUL*(1-bf)*(1-bf));p.vx=-Math.sin(ra)*ts;p.vy=Math.cos(ra)*ts;}
  }
}

function buildEventMeta(events){
  const DAY=86400000,now=Date.now();
  return [...events].sort((a,b)=>a.ts-b.ts).map((ev,i)=>{const ag=Math.min(Math.floor((now-ev.ts)/DAY),6);return{habitId:ev.habitId,ageSteps:ag,colorIndex:ag,streakGroupId:ev.habitId,ordinal:i};});
}

function syncParticles(particles,events,outerR,cx,cy){
  const metas=buildEventMeta(events);
  for(let i=0;i<particles.length;i++){
    const p=particles[i];
    if(i<metas.length){const m=metas[i],je=!p.done;if(je){const a=Math.random()*Math.PI*2,sr=outerR-6;p.x=cx+Math.cos(a)*sr;p.y=cy+Math.sin(a)*sr;p.vx=-Math.sin(a)*130;p.vy=Math.cos(a)*130;}p.gravityFree=false;p.done=true;p.ageSteps=m.ageSteps;p.colorIndex=m.colorIndex;p.streakGroupId=m.streakGroupId;p.ordinal=m.ordinal;}
    else{p.done=false;p.ageSteps=0;p.colorIndex=0;p.streakGroupId='';p.ordinal=0;}
  }
}

window.VortexFallback = {
  init(canvas, initEvents, habits) {
    const dpr = window.devicePixelRatio||1;
    let W,H,canvasH,cx,cy,wellR,outerR,horizonR,particles,prevDist,currentEvents=initEvents||[];
    const flashes=[];let lastTime=0;
    const ctx=canvas.getContext('2d');

    function layout(){
      W=window.innerWidth;H=window.innerHeight;canvasH=H*0.667;
      canvas.width=W*dpr;canvas.height=canvasH*dpr;
      canvas.style.width=W+'px';canvas.style.height=canvasH+'px';
      ctx.scale(dpr,dpr);
      const base=Math.min(W,canvasH),w=base*0.11;
      cx=W*0.5;cy=canvasH*0.5;wellR=w;outerR=base*0.44;horizonR=w;
      particles=createParticles(900,wellR,outerR,cx,cy);
      prevDist=new Float32Array(particles.length);
      syncParticles(particles,currentEvents,outerR,cx,cy);
    }

    window.addEventListener('resize',()=>{ctx.setTransform(1,0,0,1,0,0);layout();});

    // Expose event update
    window.VortexFallback.updateEvents = (evs) => {
      currentEvents=evs;
      syncParticles(particles,currentEvents,outerR,cx,cy);
    };

    function drawBH(){
      const grad=ctx.createRadialGradient(cx,cy,wellR*0.5,cx,cy,wellR*2.2);
      grad.addColorStop(0,'rgba(80,120,200,0.0)');grad.addColorStop(0.5,'rgba(80,120,200,0.04)');grad.addColorStop(1,'rgba(80,120,200,0.0)');
      ctx.fillStyle=grad;ctx.beginPath();ctx.arc(cx,cy,wellR*2.2,0,Math.PI*2);ctx.fill();
      ctx.strokeStyle='rgba(120,180,255,0.07)';ctx.lineWidth=3;ctx.beginPath();ctx.arc(cx,cy,horizonR*1.05,0,Math.PI*2);ctx.stroke();
      ctx.fillStyle='rgba(0,0,0,0.60)';ctx.beginPath();ctx.arc(cx,cy,wellR,0,Math.PI*2);ctx.fill();
      ctx.fillStyle='rgba(0,0,0,0.78)';ctx.beginPath();ctx.arc(cx,cy,wellR*0.92,0,Math.PI*2);ctx.fill();
      ctx.fillStyle='rgba(0,0,0,0.92)';ctx.beginPath();ctx.arc(cx,cy,wellR*0.78,0,Math.PI*2);ctx.fill();
      ctx.fillStyle='#000';ctx.beginPath();ctx.arc(cx,cy,wellR*0.55,0,Math.PI*2);ctx.fill();
    }

    function loop(nowMs){
      const dt=lastTime===0?0.016:Math.min(0.05,(nowMs-lastTime)/1000);lastTime=nowMs;
      tickVortex(particles,dt,wellR,outerR,nowMs/1000,cx,cy);
      ctx.clearRect(0,0,W,canvasH);ctx.fillStyle='#070B18';ctx.fillRect(0,0,W,canvasH);
      const span=Math.max(1,outerR-wellR);
      if(prevDist.length!==particles.length)prevDist=new Float32Array(particles.length);
      for(let i=0;i<particles.length;i++){
        const p=particles[i];if(p.done)continue;
        const dx=p.x-cx,dy=p.y-cy,dist=Math.sqrt(dx*dx+dy*dy);
        const prev=prevDist[i];
        if(prev>0&&prev<horizonR*1.4&&dist>outerR*0.65){flashes.push({x:p.x,y:p.y,born:nowMs});if(flashes.length>80)flashes.splice(0,20);}
        prevDist[i]=dist;
        if(dist<horizonR+2)continue;
        const norm=clamp((dist-wellR)/span,0,1),r=2.5-norm*0.5,a=0.55+(1-norm)*0.35;
        ctx.fillStyle=`rgba(255,255,255,${a.toFixed(3)})`;ctx.beginPath();ctx.arc(p.x,p.y,r,0,Math.PI*2);ctx.fill();
      }
      for(let i=flashes.length-1;i>=0;i--){
        const fl=flashes[i],age=nowMs-fl.born;if(age>=FLASH_DUR){flashes.splice(i,1);continue;}
        const t=age/FLASH_DUR,ease=1-t*t,alpha=ease*0.9,radius=3.5+t*8;
        ctx.fillStyle=`rgba(255,255,255,${alpha.toFixed(3)})`;ctx.beginPath();ctx.arc(fl.x,fl.y,radius*0.4,0,Math.PI*2);ctx.fill();
        ctx.fillStyle=`rgba(180,220,255,${(alpha*0.45).toFixed(3)})`;ctx.beginPath();ctx.arc(fl.x,fl.y,radius,0,Math.PI*2);ctx.fill();
      }
      for(let i=0;i<particles.length;i++){
        const p=particles[i];if(!p.done)continue;
        const dx=p.x-cx,dy=p.y-cy,dist=Math.sqrt(dx*dx+dy*dy);if(dist<horizonR+2)continue;
        const norm=clamp((dist-wellR)/span,0,1),proximity=1-norm,sizeBoost=1+proximity*2,baseR=3.2*sizeBoost;
        const ci=clamp(p.colorIndex,0,6),[cr,cg,cb,ca]=STAGE_COLORS[ci];
        ctx.fillStyle=`rgba(${cr},${cg},${cb},0.07)`;ctx.beginPath();ctx.arc(p.x,p.y,baseR*2.8,0,Math.PI*2);ctx.fill();
        ctx.fillStyle=`rgba(${cr},${cg},${cb},0.22)`;ctx.beginPath();ctx.arc(p.x,p.y,baseR*1.7,0,Math.PI*2);ctx.fill();
        ctx.fillStyle=`rgba(${cr},${cg},${cb},${(ca/255).toFixed(3)})`;ctx.beginPath();ctx.arc(p.x,p.y,baseR,0,Math.PI*2);ctx.fill();
      }
      drawBH();
      requestAnimationFrame(loop);
    }

    layout();
    requestAnimationFrame(loop);
  }
};

})();
