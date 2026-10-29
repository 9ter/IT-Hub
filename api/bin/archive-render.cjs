// Offline rendering: no login/session, HTTP server, external requests or public document URL.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
(async()=>{
 let browser;
 const watchdog=setTimeout(()=>{if(browser)browser.close().finally(()=>process.exit(1));else process.exit(1)},75000);
 try{
  let data='';for await(const chunk of process.stdin){data+=chunk;if(data.length>16777216)throw Error('size')}
  const input=JSON.parse(data),{chromium}=require(input.runtime),root=fs.realpathSync(input.dist);
  browser=await chromium.launch({headless:true,executablePath:input.chromium,args:['--no-sandbox','--disable-background-networking']});
  const page=await browser.newPage({viewport:{width:1280,height:1000}});let pageError=false;page.on('pageerror',()=>{pageError=true});
  await page.addInitScript(payload=>{window.__archive=payload;window.__printed=false;window.print=()=>{window.__printed=true}},input.payload);
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());if(url.origin!=='http://archive.invalid')return route.abort();
   const api=url.pathname.startsWith('/it-hub/api/')?url.pathname.slice('/it-hub/api'.length):null;
   if(api){if(route.request().method()!=='GET')return route.abort();if(api===`/admin/tickets/${input.payload.ticket.id}`)return route.fulfill({json:input.payload.ticket});if(api==='/admin/admins'||api==='/approvers')return route.fulfill({json:[]});return route.abort()}
   const relative=decodeURIComponent(url.pathname).replace(/^\/it-hub\//,'');const file=path.resolve(root,relative);
   if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()||!fs.realpathSync(file).startsWith(root+path.sep))return route.abort();
   const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png','.woff2':'font/woff2','.woff':'font/woff'};
   return route.fulfill({path:file,contentType:mime[path.extname(file)]||'application/octet-stream'});
  });
  await page.goto('http://archive.invalid/it-hub/archive-print.html');
  await page.getByRole('button',{name:'พิมพ์ฟอร์ม',exact:true}).click({timeout:30000});await page.waitForFunction(()=>window.__printed,{timeout:30000});
  await page.evaluate(async()=>{await document.fonts.ready;await Promise.all([...document.images].map(i=>i.decode().catch(()=>{})))});
  if(pageError)throw Error('render');await page.emulateMedia({media:'print'});
  const temp=input.output+'.tmp-'+crypto.randomBytes(6).toString('hex');
  const generic=!input.payload.document&&!['service','purchase'].includes(input.payload.ticket.type);
  try{await page.pdf({path:temp,preferCSSPageSize:true,format:'A4',printBackground:true,displayHeaderFooter:false,...(generic?{margin:{top:'10mm',bottom:'10mm',left:'12mm',right:'12mm'}}:{})});fs.chmodSync(temp,0o660);fs.renameSync(temp,input.output)}finally{if(fs.existsSync(temp))fs.unlinkSync(temp)}
  process.stdout.write(JSON.stringify({ok:true}));
 }catch{process.stdout.write(JSON.stringify({ok:false,code:'render'}));process.exitCode=1}
 finally{clearTimeout(watchdog);if(browser)await browser.close()}
})();
