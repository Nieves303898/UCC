const W=1080,H=1920,cv=document.getElementById('c'),ctx=cv.getContext('2d');
const items=TL.items, FPS=TL.fps;
const clamp=(v,a=0,b=1)=>Math.max(a,Math.min(b,v));
const lerp=(a,b,k)=>a+(b-a)*k;
const ease=k=>{k=clamp(k);return k<.5?2*k*k:1-Math.pow(-2*k+2,2)/2};
const easeOutBack=k=>{k=clamp(k);const c1=1.70158,c3=c1+1;return 1+c3*Math.pow(k-1,3)+c1*Math.pow(k-1,2)};
function hash(n){const x=Math.sin(n*127.1+311.7)*43758.5453;return x-Math.floor(x)}
function ev(name){return items.find(i=>i.event===name)}
function sceneStart(s){return items.find(i=>i.scene===s).start}
function sceneAt(t){let s=0;for(const i of items){if(i.start<=t)s=i.scene}return s}
function lineAt(t){return items.find(i=>i.type==='line'&&t>=i.start&&t<i.end)}
function since(name,t){const e=ev(name);return e?t-e.start:-1e9}
function talkOf(key,t){
  const l=lineAt(t);if(!l)return 0;
  const sp=l.speaker;
  const ok= sp===key || (sp==='abitanti'&&['ab1','ab2','ab3'].includes(key)) ||
           (sp==='tutti'&&['b1','b2','b3','narr','olivo','atena','pos','zeus','ab1','ab2','ab3'].includes(key));
  if(!ok)return 0;
  const idx=Math.floor((t-l.start)*FPS);return l.env[idx]||0;
}
const NAMES={narr:'Narratore',zeus:'Zeus',pos:'Poseidone',atena:'Atena',ab1:'Abitante 1',ab2:'Abitante 2',ab3:'Abitante 3',
  olivo:'Olivo',b1:'Bambina 1',b2:'Bambino 2',b3:'Bambina 3',abitanti:'Abitanti, tutti insieme',tutti:'Tutti insieme'};
const COLORS={narr:'#e08a1e',zeus:'#7b4bb7',pos:'#1b7fa6',atena:'#c0392b',ab1:'#3f8f3a',ab2:'#d6589a',ab3:'#e2731b',
  olivo:'#4d7c2a',b1:'#c94f7c',b2:'#2f78c4',b3:'#8e5bc9',abitanti:'#3f8f3a',tutti:'#4d7c2a'};

// ---------- primitive ----------
function rr(x,y,w,h,r){ctx.beginPath();ctx.moveTo(x+r,y);ctx.arcTo(x+w,y,x+w,y+h,r);ctx.arcTo(x+w,y+h,x,y+h,r);ctx.arcTo(x,y+h,x,y,r);ctx.arcTo(x,y,x+w,y,r);ctx.closePath()}
function circle(x,y,r,fill,stroke,lw){ctx.beginPath();ctx.arc(x,y,r,0,Math.PI*2);if(fill){ctx.fillStyle=fill;ctx.fill()}if(stroke){ctx.lineWidth=lw||4;ctx.strokeStyle=stroke;ctx.stroke()}}
function ell(x,y,rx,ry,fill,rot=0){ctx.beginPath();ctx.ellipse(x,y,rx,ry,rot,0,Math.PI*2);ctx.fillStyle=fill;ctx.fill()}
const OUT='#3a2a1e';

// ---------- sfondo ----------
function cloud(x,y,s,a=1){ctx.save();ctx.globalAlpha*=a;ctx.fillStyle='#fff';
  [[0,0,60],[55,-25,70],[120,0,58],[60,20,60],[-45,15,42],[165,18,40]].forEach(([dx,dy,r])=>{ctx.beginPath();ctx.arc(x+dx*s,y+dy*s,r*s,0,7);ctx.fill()});ctx.restore()}
function background(t,sc){
  const g=ctx.createLinearGradient(0,0,0,1000);
  g.addColorStop(0,'#5fb4ee');g.addColorStop(.6,'#a8dcf7');g.addColorStop(1,'#fde9c0');
  ctx.fillStyle=g;ctx.fillRect(0,0,W,H);
  // sole
  ctx.save();ctx.translate(880,330);ctx.rotate(t*.15);
  for(let i=0;i<12;i++){ctx.rotate(Math.PI/6);ctx.fillStyle='rgba(255,220,90,.55)';ctx.beginPath();ctx.moveTo(85,-14);ctx.lineTo(150,0);ctx.lineTo(85,14);ctx.fill()}
  ctx.restore();circle(880,330,78,'#ffd23f');circle(880,330,78,null,'#f5b300',6);
  // nuvole
  for(let i=0;i<4;i++){const sp=12+i*5;const x=((i*330+t*sp)%(W+500))-250;cloud(x,180+i*110,.7+.15*(i%2),.9)}
  // mare
  const sy=930;const sg=ctx.createLinearGradient(0,sy,0,1010);sg.addColorStop(0,'#2d8fc9');sg.addColorStop(1,'#62c0e8');
  ctx.fillStyle=sg;ctx.fillRect(0,sy,W,90);
  ctx.strokeStyle='rgba(255,255,255,.6)';ctx.lineWidth=3;
  for(let r=0;r<3;r++){ctx.beginPath();for(let x=0;x<=W;x+=20){const y=sy+20+r*25+Math.sin(x/40+t*2+r)*4;x?ctx.lineTo(x,y):ctx.moveTo(x,y)}ctx.stroke()}
  // collina con la città (acropoli)
  ctx.fillStyle='#c9a46b';ctx.beginPath();ctx.moveTo(380,1010);ctx.bezierCurveTo(470,820,560,800,640,790);ctx.lineTo(900,790);ctx.bezierCurveTo(980,800,1040,880,1110,1010);ctx.closePath();ctx.fill();
  ctx.fillStyle='#b38d55';ctx.beginPath();ctx.moveTo(520,1010);ctx.bezierCurveTo(580,900,640,880,700,870);ctx.lineTo(760,1010);ctx.fill();
  // tempio
  const tx=650,ty=790;ctx.fillStyle='#fffaf0';ctx.fillRect(tx,ty-16,230,16);
  for(let i=0;i<7;i++){ctx.fillRect(tx+10+i*33,ty-96,16,80)}
  ctx.fillRect(tx-6,ty-110,242,16);ctx.beginPath();ctx.moveTo(tx-10,ty-110);ctx.lineTo(tx+115,ty-155);ctx.lineTo(tx+240,ty-110);ctx.fill();
  ctx.strokeStyle='#d8c9a8';ctx.lineWidth=3;ctx.strokeRect(tx-6,ty-110,242,16);
  // casette bianche
  const houses=[[430,960],[480,925],[545,950],[600,900],[920,930],[980,960],[860,905],[1020,920],[700,960],[780,940]];
  houses.forEach(([x,y],i)=>{ctx.fillStyle='#fffdf6';ctx.fillRect(x,y,46,36);ctx.fillStyle=i%2?'#d9653b':'#c4542e';ctx.beginPath();ctx.moveTo(x-5,y);ctx.lineTo(x+23,y-18);ctx.lineTo(x+51,y);ctx.fill();ctx.fillStyle='#6d8fb3';ctx.fillRect(x+17,y+14,12,22)});
  // cartello del nome
  const at=since('atene',t);
  ctx.save();ctx.translate(765,620);
  if(sc>=4&&at>1.0){
    const k=easeOutBack((at-1.0)/0.8);ctx.scale(k,k);
    ctx.shadowColor='rgba(255,215,0,.9)';ctx.shadowBlur=30+10*Math.sin(t*4);
    rr(-210,-62,420,124,24);ctx.fillStyle='#fff6d8';ctx.fill();ctx.shadowBlur=0;ctx.lineWidth=8;ctx.strokeStyle='#d4a017';ctx.stroke();
    ctx.fillStyle='#8a5a00';ctx.font='700 86px Cinzel';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText('ATENE',0,8);
  } else if(sc>=1&&sc<=4){
    rr(-120,-50,240,100,20);ctx.fillStyle='rgba(255,250,235,.9)';ctx.fill();ctx.lineWidth=6;ctx.strokeStyle='#a07a3a';ctx.stroke();
    ctx.fillStyle='#a07a3a';ctx.font='700 70px Fredoka';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText('? ? ?',0,4);
  }
  ctx.restore();
  // terreno
  const gg=ctx.createLinearGradient(0,1000,0,H);gg.addColorStop(0,'#9ccc5a');gg.addColorStop(.5,'#7fb447');gg.addColorStop(1,'#5f9a35');
  ctx.fillStyle=gg;ctx.beginPath();ctx.moveTo(0,1030);ctx.bezierCurveTo(300,990,700,1040,W,1000);ctx.lineTo(W,H);ctx.lineTo(0,H);ctx.fill();
  // fiori ed erba
  for(let i=0;i<40;i++){const x=hash(i)*W,y=1060+hash(i+50)*420;
    ctx.strokeStyle='#4d8a2a';ctx.lineWidth=3;ctx.beginPath();ctx.moveTo(x,y);ctx.lineTo(x-6,y-16);ctx.moveTo(x,y);ctx.lineTo(x+6,y-18);ctx.stroke();
    if(i%3==0){circle(x,y-20,7,['#fff','#ffd23f','#ff8fb1'][i%3===0?(i/3)%3:0])}}
}

// ---------- oggetti ----------
function trident(len){ctx.save();ctx.strokeStyle='#d4a017';ctx.lineCap='round';ctx.lineWidth=12;ctx.beginPath();ctx.moveTo(0,40);ctx.lineTo(0,-len);ctx.stroke();
  ctx.lineWidth=10;ctx.beginPath();ctx.moveTo(-40,-len+10);ctx.lineTo(-40,-len-50);ctx.moveTo(40,-len+10);ctx.lineTo(40,-len-50);ctx.moveTo(0,-len);ctx.lineTo(0,-len-70);ctx.moveTo(-40,-len+10);ctx.quadraticCurveTo(0,-len+40,40,-len+10);ctx.stroke();
  ctx.fillStyle='#d4a017';[[-40,-len-50],[40,-len-50],[0,-len-70]].forEach(([x,y])=>{ctx.beginPath();ctx.moveTo(x-12,y+10);ctx.lineTo(x,y-18);ctx.lineTo(x+12,y+10);ctx.fill()});ctx.restore()}
function bolt(){ctx.save();ctx.fillStyle='#ffd23f';ctx.strokeStyle='#e09b00';ctx.lineWidth=5;ctx.beginPath();
  ctx.moveTo(-10,-120);ctx.lineTo(30,-120);ctx.lineTo(8,-55);ctx.lineTo(38,-55);ctx.lineTo(-25,40);ctx.lineTo(-5,-30);ctx.lineTo(-32,-30);ctx.closePath();ctx.fill();ctx.stroke();ctx.restore()}
function leaf(x,y,ang,len=34,col='#5b8f2e'){ctx.save();ctx.translate(x,y);ctx.rotate(ang);ctx.fillStyle=col;ctx.beginPath();ctx.moveTo(0,0);ctx.quadraticCurveTo(len*.5,-len*.28,len,0);ctx.quadraticCurveTo(len*.5,len*.28,0,0);ctx.fill();ctx.restore()}
function branch(){ctx.save();ctx.strokeStyle='#7a5230';ctx.lineWidth=6;ctx.lineCap='round';ctx.beginPath();ctx.moveTo(0,0);ctx.quadraticCurveTo(20,-60,10,-120);ctx.stroke();
  for(let i=0;i<7;i++){const y=-20-i*15;leaf(8+Math.sin(i)*4,y,i%2?-0.6:-2.5,36,i%2?'#6f9e3b':'#4f7f28')}circle(22,-70,8,'#3d4f22');circle(-6,-95,8,'#3d4f22');ctx.restore()}
function scroll(){ctx.save();ctx.fillStyle='#f6e7c1';ctx.strokeStyle='#b08a4a';ctx.lineWidth=4;rr(-50,-30,100,60,8);ctx.fill();ctx.stroke();
  circle(-50,0,32,'#e8d29d','#b08a4a',4);circle(50,0,32,'#e8d29d','#b08a4a',4);ctx.strokeStyle='#b8a070';ctx.lineWidth=3;for(let i=0;i<3;i++){ctx.beginPath();ctx.moveTo(-30,-12+i*12);ctx.lineTo(30,-12+i*12);ctx.stroke()}ctx.restore()}
function owl(){ctx.save();ell(0,0,30,38,'#9a6b3f');ell(0,8,20,24,'#d9b98a');circle(-12,-14,12,'#fff');circle(12,-14,12,'#fff');circle(-12,-14,6,'#222');circle(12,-14,6,'#222');
  ctx.fillStyle='#f0a020';ctx.beginPath();ctx.moveTo(-5,-4);ctx.lineTo(5,-4);ctx.lineTo(0,6);ctx.fill();ctx.fillStyle='#9a6b3f';ctx.beginPath();ctx.moveTo(-28,-28);ctx.lineTo(-18,-44);ctx.lineTo(-10,-30);ctx.moveTo(28,-28);ctx.lineTo(18,-44);ctx.lineTo(10,-30);ctx.fill();ctx.restore()}
function paperOlive(){ell(0,0,16,22,'#556b2f');ell(-5,-6,5,7,'rgba(255,255,255,.35)')}

// ---------- personaggio ----------
function person(o,t){
  const s=o.s||1;ctx.save();ctx.translate(o.x,o.y+(o.dy||0));ctx.scale(s,s);
  const talk=o.talk||0, bob=Math.sin(t*2+(o.seed||0))*3 - (talk>0.05?Math.abs(Math.sin(t*9))*5*talk:0);
  ctx.translate(0,bob);
  const squat=o.squat||0;
  // ombra
  ctx.save();ctx.translate(0,-bob);ell(0,4,90,18,'rgba(0,0,0,.18)');ctx.restore();
  ctx.lineJoin='round';ctx.lineCap='round';
  if(!o.noLegs){
    ctx.fillStyle=o.skin;ctx.strokeStyle=OUT;ctx.lineWidth=4;
    rr(-38,-60+squat*30,26,60-squat*30,10);ctx.fill();ctx.stroke();rr(12,-60+squat*30,26,60-squat*30,10);ctx.fill();ctx.stroke();
    ctx.fillStyle='#8a5a2b';rr(-46,-12,40,16,8);ctx.fill();ctx.stroke();rr(6,-12,40,16,8);ctx.fill();ctx.stroke();
  }
  ctx.translate(0,squat*40);
  // mantello
  if(o.cape){ctx.fillStyle=o.cape;ctx.beginPath();ctx.moveTo(-55,-255);ctx.quadraticCurveTo(-110,-120,-95,-40);ctx.lineTo(95,-40);ctx.quadraticCurveTo(110,-120,55,-255);ctx.fill();ctx.strokeStyle=OUT;ctx.lineWidth=4;ctx.stroke()}
  // braccia dietro (sinistro)
  arm(o,-1,o.armL??0.25,t);
  // tunica
  ctx.fillStyle=o.tunic;ctx.strokeStyle=OUT;ctx.lineWidth=5;ctx.beginPath();
  ctx.moveTo(-52,-262);ctx.lineTo(52,-262);ctx.lineTo(80,-48);ctx.quadraticCurveTo(0,-36,-80,-48);ctx.closePath();ctx.fill();ctx.stroke();
  ctx.strokeStyle=o.trim||'#e8c35a';ctx.lineWidth=10;ctx.beginPath();ctx.moveTo(-76,-60);ctx.quadraticCurveTo(0,-48,76,-60);ctx.stroke();
  ctx.lineWidth=8;ctx.beginPath();ctx.moveTo(-60,-160);ctx.lineTo(60,-160);ctx.stroke();
  if(o.sash){ctx.strokeStyle=o.sash;ctx.lineWidth=14;ctx.beginPath();ctx.moveTo(-45,-258);ctx.lineTo(55,-100);ctx.stroke()}
  // collo + testa
  const hx=0,hy=-335;
  ctx.fillStyle=o.skin;ctx.fillRect(-16,-280,32,26);
  // capelli dietro
  ctx.fillStyle=o.hair;
  if(o.hairStyle==='long'){ctx.beginPath();ctx.moveTo(-78,hy-10);ctx.quadraticCurveTo(-95,hy+90,-70,hy+120);ctx.lineTo(70,hy+120);ctx.quadraticCurveTo(95,hy+90,78,hy-10);ctx.fill()}
  if(o.hairStyle==='pony'){ctx.save();ctx.translate(70,hy-30);ctx.rotate(Math.sin(t*3+(o.seed||0))*.15);ell(25,40,24,55,o.hair,.3);ctx.restore()}
  if(o.hairStyle==='braids'){ell(-74,hy+55,16,40,o.hair);ell(74,hy+55,16,40,o.hair);circle(-74,hy+98,9,'#e94f64');circle(74,hy+98,9,'#e94f64')}
  if(o.hairStyle==='bun'){circle(0,hy-82,32,o.hair)}
  circle(hx,hy,75,o.skin,OUT,5);
  // orecchie
  circle(-74,hy+5,14,o.skin,OUT,4);circle(74,hy+5,14,o.skin,OUT,4);
  // capelli davanti
  ctx.fillStyle=o.hair;ctx.beginPath();
  if(o.hairStyle==='short'||o.hairStyle==='curly'||o.hairStyle==='god'){
    ctx.arc(hx,hy-8,77,Math.PI*1.02,Math.PI*1.98);ctx.quadraticCurveTo(40,hy-50,0,hy-48);ctx.quadraticCurveTo(-40,hy-50,-77,hy-14);ctx.fill();
    if(o.hairStyle!=='short'){for(let i=0;i<7;i++){circle(-66+i*22,hy-60+Math.abs(i-3)*7,18,o.hair)}}
    if(o.hairStyle==='god'){circle(-74,hy-20,22,o.hair);circle(74,hy-20,22,o.hair);circle(-78,hy+12,18,o.hair);circle(78,hy+12,18,o.hair)}
  } else {
    ctx.arc(hx,hy-6,78,Math.PI*1.0,Math.PI*2.0);ctx.quadraticCurveTo(50,hy-40,10,hy-50);ctx.quadraticCurveTo(-30,hy-30,-78,hy-6);ctx.fill();
  }
  // occhi
  const blink=((t+(o.seed||0)*1.7)%3.7)<0.13;
  for(const sx of [-27,27]){
    if(blink){ctx.strokeStyle=OUT;ctx.lineWidth=5;ctx.beginPath();ctx.moveTo(hx+sx-12,hy-5);ctx.lineTo(hx+sx+12,hy-5);ctx.stroke()}
    else{ell(hx+sx,hy-6,13,16,'#fff');circle(hx+sx+(o.look||0)*4,hy-4,8,'#2b1d12');circle(hx+sx+3+(o.look||0)*4,hy-8,3,'#fff')}
  }
  // sopracciglia
  ctx.strokeStyle=o.brow||o.hair;ctx.lineWidth=6;ctx.beginPath();ctx.moveTo(hx-40,hy-30);ctx.lineTo(hx-16,hy-34);ctx.moveTo(hx+16,hy-34);ctx.lineTo(hx+40,hy-30);ctx.stroke();
  circle(hx-45,hy+18,12,'rgba(255,120,120,.35)');circle(hx+45,hy+18,12,'rgba(255,120,120,.35)');
  // barba
  if(o.beard){ctx.fillStyle=o.beard;ctx.beginPath();ctx.moveTo(-70,hy+5);ctx.quadraticCurveTo(-75,hy+o.beardLen,0,hy+o.beardLen+30);ctx.quadraticCurveTo(75,hy+o.beardLen,70,hy+5);ctx.quadraticCurveTo(40,hy+50,0,hy+50);ctx.quadraticCurveTo(-40,hy+50,-70,hy+5);ctx.fill();
    ctx.strokeStyle=o.beardLine||'rgba(0,0,0,.15)';ctx.lineWidth=3;for(let i=-2;i<=2;i++){ctx.beginPath();ctx.moveTo(i*18,hy+55);ctx.quadraticCurveTo(i*22,hy+o.beardLen,i*12,hy+o.beardLen+18);ctx.stroke()}
    // baffi
    ctx.fillStyle=o.beard;ell(-18,hy+26,22,10,o.beard,.2);ell(18,hy+26,22,10,o.beard,-.2);}
  // naso + bocca
  ell(hx,hy+10,8,6,'rgba(0,0,0,.15)');
  const my=hy+(o.beard?40:32);
  if(talk>0.06){const oh=5+talk*22;ell(hx,my,13+talk*5,oh/2+2,'#7a2320');ell(hx,my+oh/4,8,oh/5,'#e2747a')}
  else{ctx.strokeStyle='#7a2320';ctx.lineWidth=5;ctx.beginPath();ctx.arc(hx,my-12,16,Math.PI*.2,Math.PI*.8);ctx.stroke()}
  // copricapo
  if(o.crown==='gold'){ctx.fillStyle='#ffcc33';ctx.strokeStyle='#c99500';ctx.lineWidth=4;ctx.beginPath();ctx.moveTo(-55,hy-55);for(let i=0;i<=5;i++){const x=-55+i*22;ctx.lineTo(x,hy-55-(i%2?20:45))}ctx.lineTo(55,hy-55);ctx.lineTo(55,hy-40);ctx.lineTo(-55,hy-40);ctx.closePath();ctx.fill();ctx.stroke();circle(0,hy-48,7,'#e74c3c')}
  if(o.crown==='laurel'){for(let i=0;i<9;i++){const a=Math.PI*(1.05+i*0.11);const x=Math.cos(a)*76,y=hy-10+Math.sin(a)*70;leaf(x,y,a+Math.PI/2,30,'#e2b33c');leaf(x,y,a-Math.PI/2+.3,30,'#c99a20')}}
  if(o.crown==='helmet'){
    ctx.fillStyle='#d9a441';ctx.strokeStyle='#8a6420';ctx.lineWidth=5;ctx.beginPath();ctx.arc(0,hy-18,82,Math.PI*1.05,Math.PI*1.95);ctx.lineTo(80,hy-10);ctx.lineTo(-80,hy-10);ctx.closePath();ctx.fill();ctx.stroke();
    ctx.fillStyle='#c0392b';ctx.beginPath();ctx.moveTo(-70,hy-80);ctx.quadraticCurveTo(0,hy-190,90,hy-110);ctx.quadraticCurveTo(30,hy-140,-60,hy-70);ctx.fill();
    ctx.strokeStyle='#8a6420';ctx.lineWidth=6;ctx.beginPath();ctx.moveTo(-80,hy-12);ctx.lineTo(80,hy-12);ctx.stroke();}
  if(o.owl){ctx.save();ctx.translate(-68,-275);ctx.scale(.9,.9);owl();ctx.restore()}
  // braccio destro (davanti)
  arm(o,1,o.armR??0.25,t);
  ctx.restore();
}
function arm(o,side,ang,t){
  ctx.save();ctx.translate(side*55,-240);ctx.rotate(-side*ang);
  ctx.strokeStyle=OUT;ctx.lineWidth=30;ctx.beginPath();ctx.moveTo(0,0);ctx.lineTo(0,120);ctx.stroke();
  ctx.strokeStyle=o.skin;ctx.lineWidth=22;ctx.beginPath();ctx.moveTo(0,0);ctx.lineTo(0,120);ctx.stroke();
  ctx.strokeStyle=o.tunic;ctx.lineWidth=34;ctx.beginPath();ctx.moveTo(0,-4);ctx.lineTo(0,40);ctx.stroke();
  circle(0,128,17,o.skin,OUT,4);
  const prop=side>0?o.prop:o.propL;
  if(prop){ctx.translate(0,128);ctx.rotate(side*ang);
    if(prop==='trident')trident(260);if(prop==='bolt')bolt();if(prop==='branch')branch();if(prop==='scroll')scroll();
    if(prop==='leaf'){leaf(0,0,-1.4,70,'#5b8f2e');leaf(0,0,-1.9,60,'#6fa23a')}
    if(prop==='olive'){paperOlive()}
  }
  ctx.restore();
}

// ---------- Zeus sulla nuvola ----------
function zeus(x,y,s,t,talk,alpha=1){
  ctx.save();ctx.globalAlpha=alpha;
  const fl=Math.sin(t*1.5)*10;
  person({x,y:y+fl,s,skin:'#f4cfa6',hair:'#f2f2f2',hairStyle:'god',beard:'#fafafa',beardLen:120,beardLine:'rgba(120,120,160,.25)',brow:'#d0d0d0',
    tunic:'#ffffff',trim:'#e8b923',cape:'#7b4bb7',crown:'laurel',prop:'bolt',armR:0.9,armL:0.3,talk,seed:3,noLegs:true},t);
  // nuvola
  ctx.save();ctx.translate(x,y+fl);ctx.scale(s,s);
  ctx.fillStyle='#fff';ctx.strokeStyle='#cfe3f2';ctx.lineWidth=6;
  [[-150,-40,70],[-80,-70,85],[0,-60,95],[85,-70,85],[155,-40,70],[-60,-10,70],[60,-10,70],[0,0,60]].forEach(([dx,dy,r])=>{ctx.beginPath();ctx.arc(dx,dy,r,0,7);ctx.fill()});
  ctx.restore();ctx.restore();
}
function poseidone(x,y,s,t,talk,extra={}){
  person(Object.assign({x,y,s,skin:'#e9c29b',hair:'#1f8a9e',hairStyle:'god',beard:'#1f8a9e',beardLen:95,brow:'#156b7a',
    tunic:'#2f6fb0',trim:'#7fe0e6',cape:'#0f4c6e',crown:'gold',prop:'trident',armR:0.15,armL:0.25,talk,seed:1,sash:'#7fe0e6'},extra),t);
}
function atena(x,y,s,t,talk,extra={}){
  person(Object.assign({x,y,s,skin:'#f6d3b0',hair:'#6b3e1f',hairStyle:'long',tunic:'#fbf7ee',trim:'#2d6cb5',cape:'#2d6cb5',crown:'helmet',prop:'branch',armR:0.35,armL:0.2,talk,seed:2,owl:true},extra),t);
}
const FOLK={
  ab1:{skin:'#e3b48a',hair:'#5a3a1e',hairStyle:'short',beard:'#5a3a1e',beardLen:55,tunic:'#5aa850',trim:'#f2e28a',seed:4},
  ab2:{skin:'#f3c9a5',hair:'#2e1c10',hairStyle:'bun',tunic:'#f28bb8',trim:'#ffffff',seed:5},
  ab3:{skin:'#d9a27a',hair:'#c9772b',hairStyle:'braids',tunic:'#f39a3a',trim:'#fff3c4',seed:6},
  b1:{skin:'#f5cfae',hair:'#3b2412',hairStyle:'pony',tunic:'#ff8fb1',trim:'#fff',seed:7,prop:'leaf',propL:'olive'},
  b2:{skin:'#c98e62',hair:'#1d120a',hairStyle:'curly',tunic:'#5aa0e6',trim:'#ffe066',seed:8,prop:'olive',propL:'leaf'},
  b3:{skin:'#efc19a',hair:'#d4a23a',hairStyle:'braids',tunic:'#b08ae6',trim:'#fff',seed:9,prop:'leaf',propL:'olive'},
  narr:{skin:'#f5cfae',hair:'#4a2a14',hairStyle:'pony',tunic:'#ffcf4a',trim:'#e0731b',seed:10,prop:'scroll',armR:0.5}
};
function folk(key,x,y,s,t,extra={}){person(Object.assign({x,y,s,talk:talkOf(key,t)},FOLK[key],extra),t)}

// ---------- olivo ----------
function olivo(x,y,s,t,g,olives,talk){
  if(g<=0)return;ctx.save();ctx.translate(x,y);ctx.scale(s,s);
  ell(0,6,150*g,24*g,'rgba(0,0,0,.18)');
  const k=easeOutBack(g);ctx.scale(k,k);
  const sway=Math.sin(t*1.3)*0.03;ctx.rotate(sway);
  // tronco
  ctx.fillStyle='#8b5a2b';ctx.strokeStyle=OUT;ctx.lineWidth=5;ctx.beginPath();
  ctx.moveTo(-55,0);ctx.bezierCurveTo(-40,-80,-60,-160,-35,-250);ctx.lineTo(35,-250);ctx.bezierCurveTo(60,-160,40,-80,55,0);ctx.closePath();ctx.fill();ctx.stroke();
  ctx.strokeStyle='#6b431f';ctx.lineWidth=4;ctx.beginPath();ctx.moveTo(-20,-30);ctx.quadraticCurveTo(-30,-90,-15,-140);ctx.moveTo(22,-50);ctx.quadraticCurveTo(28,-100,15,-150);ctx.stroke();
  // rami-braccia
  const open=clamp(g*1.2-0.2);
  ctx.strokeStyle='#8b5a2b';ctx.lineWidth=22;ctx.lineCap='round';
  for(const sd of [-1,1]){ctx.beginPath();ctx.moveTo(sd*30,-210);ctx.quadraticCurveTo(sd*(80+60*open),-250-40*open,sd*(120+70*open),-300-90*open);ctx.stroke()}
  // chioma
  const cl=[[0,-390,120],[-110,-340,95],[110,-340,95],[-170,-420,80],[170,-420,80],[-70,-470,95],[70,-470,95],[0,-300,90]];
  cl.forEach(([cx,cy,r],i)=>{const w=Math.sin(t*1.5+i)*4;circle(cx+w,cy,r,i%2?'#6f9e3b':'#5b8f2e')});
  for(let i=0;i<60;i++){const a=hash(i)*Math.PI*2,rad=Math.sqrt(hash(i+9))*190;const lx=Math.cos(a)*rad,ly=-400+Math.sin(a)*rad*0.55;leaf(lx,ly,a+t*.2*Math.sin(i),34,i%3?'#8fbf55':'#4a7a25')}
  // olive
  const n=Math.floor(olives*22);for(let i=0;i<n;i++){const a=hash(i+100)*Math.PI*2,rad=Math.sqrt(hash(i+200))*170;ell(Math.cos(a)*rad,-400+Math.sin(a)*rad*0.55,13,17,'#4b5320');ell(Math.cos(a)*rad-4,-405+Math.sin(a)*rad*0.55,4,5,'rgba(255,255,255,.4)')}
  // faccia sul tronco
  const fy=-150;const blink=((t*1.1)%4.1)<0.13;
  for(const sx of [-18,18]){if(blink){ctx.strokeStyle=OUT;ctx.lineWidth=4;ctx.beginPath();ctx.moveTo(sx-9,fy);ctx.lineTo(sx+9,fy);ctx.stroke()}else{ell(sx,fy,10,13,'#fff');circle(sx,fy+2,6,'#2b1d12');circle(sx+2,fy-1,2,'#fff')}}
  circle(-32,fy+22,8,'rgba(255,120,120,.35)');circle(32,fy+22,8,'rgba(255,120,120,.35)');
  if(talk>0.06){ell(0,fy+32,10+talk*4,4+talk*10,'#5a1a14')}else{ctx.strokeStyle='#5a1a14';ctx.lineWidth=4;ctx.beginPath();ctx.arc(0,fy+20,12,Math.PI*.2,Math.PI*.8);ctx.stroke()}
  ctx.restore();
}
// ---------- sorgente ----------
function spring(x,y,s,t,age,hl=0){
  if(age<0)return;ctx.save();ctx.translate(x,y);ctx.scale(s,s);
  const k=clamp(age/0.6);
  ell(0,0,140*k,34*k,'#2f8fd0');ell(0,-4,120*k,26*k,'#5fc3f0');
  ctx.strokeStyle='rgba(255,255,255,.7)';ctx.lineWidth=3;for(let r=0;r<2;r++){const rr2=((t*0.6+r*0.5)%1);ctx.globalAlpha=1-rr2;ctx.beginPath();ctx.ellipse(0,-4,120*k*rr2,26*k*rr2,0,0,7);ctx.stroke()}ctx.globalAlpha=1;
  // rocce
  [[-150,4,26],[-120,20,20],[140,8,28],[115,22,18]].forEach(([rx,ry,r])=>{circle(rx*k,ry,r*k,'#9a9a9a','#666',3)});
  // getto
  const hgt=(age<1?age:1)*(260+hl*80);
  for(let i=0;i<70;i++){const ph=(t*1.1+hash(i))%1;const vx=(hash(i+7)-.5)*180;const px=vx*ph;const py=-hgt*4*ph*(1-ph);
    ctx.globalAlpha=0.8;circle(px,py-10,6+hash(i+3)*6,i%3?'#8fd8ff':'#ffffff')}
  ctx.globalAlpha=1;
  if(hl>0){ctx.globalAlpha=hl;ctx.fillStyle='#fff';ctx.font='600 40px Fredoka';ctx.textAlign='center';ctx.fillText('acqua salata',0,-hgt-30);ctx.globalAlpha=1}
  ctx.restore();
}
// ---------- cavallo ----------
function horse(x,y,s,a,t){
  if(a<=0)return;ctx.save();ctx.translate(x,y);ctx.scale(s,s);ctx.globalAlpha=a;
  ctx.shadowColor='#bff3ff';ctx.shadowBlur=40;
  const W1='#ffffff',L='#7fbcd6';ctx.fillStyle=W1;ctx.strokeStyle=L;ctx.lineWidth=6;
  const step=Math.sin(t*6)*0.15;
  // zampe
  [[-80,step],[-45,-step],[60,-step],[95,step]].forEach(([lx,r])=>{ctx.save();ctx.translate(lx,-90);ctx.rotate(r);rr(-12,0,24,95,10);ctx.fill();ctx.stroke();ctx.fillStyle='#9fb6c2';rr(-14,82,28,16,6);ctx.fill();ctx.fillStyle=W1;ctx.restore()});
  // coda
  ctx.strokeStyle='#bfe6f5';ctx.lineWidth=18;ctx.beginPath();ctx.moveTo(-120,-150);ctx.quadraticCurveTo(-180,-120+Math.sin(t*4)*15,-170,-60);ctx.stroke();
  ctx.strokeStyle=L;ctx.lineWidth=6;
  // corpo
  ctx.beginPath();ctx.ellipse(0,-140,130,62,0,0,7);ctx.fill();ctx.stroke();
  // collo e testa
  ctx.beginPath();ctx.moveTo(70,-180);ctx.lineTo(120,-280);ctx.lineTo(165,-270);ctx.lineTo(130,-150);ctx.closePath();ctx.fill();ctx.stroke();
  ctx.beginPath();ctx.ellipse(170,-275,52,30,0.5,0,7);ctx.fill();ctx.stroke();
  ctx.beginPath();ctx.moveTo(120,-300);ctx.lineTo(128,-335);ctx.lineTo(142,-302);ctx.fill();ctx.stroke();
  ctx.shadowBlur=0;circle(160,-290,7,'#2b3a4a');
  ctx.fillStyle='#bfe6f5';for(let i=0;i<5;i++){ell(105+i*8,-285+i*28,16,22,'#bfe6f5',-.4)}
  ctx.restore();
}
// ---------- particelle ----------
function sparkles(x,y,r,t,n,col='#fff6a0'){for(let i=0;i<n;i++){const a=hash(i)*7+t*(0.5+hash(i+1));const d=r*(0.3+0.7*((t*0.8+hash(i+2))%1));const sx=x+Math.cos(a)*d,sy=y+Math.sin(a)*d*0.8;const k=Math.sin(((t*2+hash(i+3)*6)%Math.PI));
  ctx.save();ctx.translate(sx,sy);ctx.rotate(t);ctx.globalAlpha=Math.abs(k);ctx.fillStyle=col;ctx.beginPath();for(let j=0;j<8;j++){const rr2=j%2?4:14;const aa=j*Math.PI/4;ctx.lineTo(Math.cos(aa)*rr2,Math.sin(aa)*rr2)}ctx.fill();ctx.restore()}}
function confetti(t0,t){if(t0<0||t0>6)return;const cols=['#e74c3c','#f1c40f','#2ecc71','#3498db','#9b59b6','#ff8fb1'];
  for(let i=0;i<120;i++){const x=hash(i)*W+Math.sin(t0*2+i)*40;const y=-50+(t0*(250+hash(i+5)*250))+hash(i+9)*-400;if(y>1500||y<-60)continue;
    ctx.save();ctx.translate(x,y);ctx.rotate(t0*4+i);ctx.fillStyle=cols[i%6];ctx.fillRect(-10,-5,20,10);ctx.restore()}}
function leavesFloat(t,n=14){for(let i=0;i<n;i++){const x=((hash(i)*W+t*(20+hash(i+1)*30))%(W+100))-50;const y=((hash(i+2)*1500+t*(40+hash(i+3)*40))%1600)+100;leaf(x,y,t*(0.5+hash(i))+i,40,i%2?'#6f9e3b':'#4f7f28')}}

