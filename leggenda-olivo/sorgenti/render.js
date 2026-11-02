const {chromium}=require('playwright');const {spawn}=require('child_process');
const TL=require('./timeline.json');const FPS=30;const N=Math.ceil(TL.total*FPS);
const [w,nw]=process.argv.slice(2).map(Number);const a=Math.floor(N*w/nw),b=Math.floor(N*(w+1)/nw);
(async()=>{const br=await chromium.launch();const p=await br.newPage({viewport:{width:1080,height:1920}});
p.on('pageerror',e=>console.log('ERR',e.message));
await p.goto('file://'+__dirname+'/scene.html');await p.evaluate(()=>window.ready);
const ff=spawn('ffmpeg',['-y','-loglevel','error','-f','image2pipe','-c:v','mjpeg','-framerate',''+FPS,'-i','-','-c:v','libx264','-preset','medium','-crf','19','-pix_fmt','yuv420p',`seg_${w}.mp4`],{stdio:['pipe','inherit','inherit']});
for(let f=a;f<b;f++){const d=await p.evaluate(t=>{render(t);return document.getElementById('c').toDataURL('image/jpeg',0.92)},f/FPS);
  const buf=Buffer.from(d.split(',')[1],'base64');if(!ff.stdin.write(buf))await new Promise(r=>ff.stdin.once('drain',r));
  if((f-a)%300===0)console.log(`w${w} ${f-a}/${b-a}`);}
ff.stdin.end();await new Promise(r=>ff.on('close',r));await br.close();console.log(`w${w} done`)})();
