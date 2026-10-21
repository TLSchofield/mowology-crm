# solids.py — the eight convex deltahedra, exact, faces in growth order (owner, 2026-10-20). Run via brain-ladder.sh.
import numpy as np, json, itertools
from scipy.spatial import ConvexHull
def norm(P): P=np.array(P,float); P-=P.mean(0); return P/np.max(np.linalg.norm(P,axis=1))
def ngon(n,r,z,rot=0): return [[r*np.cos(2*np.pi*i/n+rot), z, r*np.sin(2*np.pi*i/n+rot)] for i in range(n)]
S={}
S['tetrahedron']=[[1,1,1],[1,-1,-1],[-1,1,-1],[-1,-1,1]]
r=1/np.sqrt(3); S['triangular bipyramid']=ngon(3,r,0)+[[0,np.sqrt(2/3),0],[0,-np.sqrt(2/3),0]]
S['octahedron']=[[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]]
r=1/(2*np.sin(np.pi/5)); S['pentagonal bipyramid']=ngon(5,r,0)+[[0,np.sqrt(1-r*r),0],[0,-np.sqrt(1-r*r),0]]
q=np.roots([2,11,4,-1]); q=[x.real for x in q if abs(x.imag)<1e-9 and 0<x.real<1][0]
rr=np.sqrt(q); s=np.sqrt((1-q)/(2*q)); t=np.sqrt(2-2*q)
S['snub disphenoid']=[[t,rr,0],[-t,rr,0],[0,-rr,t],[0,-rr,-t],[1,-s,0],[-1,-s,0],[0,s,1],[0,s,-1]]
# triaugmented triangular prism: prism edge 1
tri=ngon(3,1/np.sqrt(3),0); P=[[x,0.5,z] for x,_,z in tri]+[[x,-0.5,z] for x,_,z in tri]
for i in range(3):
    a=np.array(tri[i]); b=np.array(tri[(i+1)%3]); m=(a+b)/2; m[1]=0; d=m/np.linalg.norm(m); P.append(list(m+d/np.sqrt(2)))
S['triaugmented triangular prism']=P
R=1/np.sqrt(2); h=np.sqrt(1-(2*R*np.sin(np.pi/8))**2)
S['gyroelongated square bipyramid']=ngon(4,R,h/2)+ngon(4,R,-h/2,np.pi/4)+[[0,h/2+R,0],[0,-h/2-R,0]]
g=(1+5**.5)/2; S['icosahedron']=[list(p) for a in (1,-1) for b in (g,-g) for p in ([0,a,b],[a,b,0],[b,0,a])]
out={}
for name,P in S.items():
    P=norm(P); H=ConvexHull(P); F=H.simplices
    E=set()
    for f in F:
        for i,j in itertools.combinations(sorted(f),2): E.add((i,j))
    L=[np.linalg.norm(P[i]-P[j]) for i,j in E]
    # build order: start at the face facing up, then always add the face sharing an edge with the most built ones, nearest the top
    cen=P[F].mean(1); order=[int(np.argmax(cen[:,1]))]; left=set(range(len(F)))-set(order)
    def shares(a,b): return len(set(F[a])&set(F[b]))==2
    while left:
        best=max(left,key=lambda f:(sum(shares(f,o) for o in order), cen[f][1]))
        order.append(best); left.remove(best)
    # orient faces outward
    tris=[]
    for f in [F[i] for i in order]:
        a,b,c=P[f]; n=np.cross(b-a,c-a)
        if np.dot(n,(a+b+c)/3)<0: b,c=c,b
        tris.append([list(map(float,a)),list(map(float,b)),list(map(float,c))])
    out[name]=tris
    print(f"{name:32s} faces={len(F):2d} edges={len(E):2d} edge-length spread={max(L)/min(L)-1:.2e}")
import os
json.dump(out,open(os.environ['WORK']+'/solids.json','w'))
