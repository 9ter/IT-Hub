// Usage: PLAYWRIGHT_MODULE=/path/to/playwright CHROMIUM_EXECUTABLE=/path/to/chrome node WI/tools/print_wi.cjs
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {pathToFileURL}=require('node:url');
const path=require('node:path');
const fs=require('node:fs');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{}),args:['--no-sandbox']});
 try{
  const page=await browser.newPage({viewport:{width:794,height:1123}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route(/^https?:/,r=>{errors.push('Unexpected remote request');return r.abort()});
  const result=[];
  for(const name of ['WI-IT-003','SYSTEM-CHECK-2026-10-28']){
   const revision='00';
   await page.goto(pathToFileURL(path.join(root,name+'.html')).href);
   await page.evaluate(()=>document.fonts.ready);
   await page.emulateMedia({media:'print'});
   const checks=await page.locator('.page').evaluateAll(pages=>pages.map((p,i)=>({section:i+1,title:p.querySelector('h1').textContent,height:Math.ceil(p.getBoundingClientRect().height),overflow:p.scrollWidth>p.clientWidth})));
   const max=282*96/25.4;
   for(const p of checks)if(p.height>max+1||p.overflow)throw Error('Page overflow: '+name+' '+JSON.stringify(p));
   await page.pdf({path:path.join(root,name+'.pdf'),preferCSSPageSize:true,printBackground:true,displayHeaderFooter:true,headerTemplate:'<span></span>',footerTemplate:`<div style="font-size:8px;color:#60758a;width:100%;padding:0 56px;display:flex;justify-content:space-between;"><span>IT Hub · 28/10/2026 · Rev.${revision}</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`});
   result.push({file:name+'.pdf',sections:checks});
  }
  if(errors.length)throw Error(errors.join('; '));
  fs.writeFileSync(path.join(root,'validation','print-layout.json'),JSON.stringify({result,errors},null,2));
  console.log('PASS: generated both PDFs offline; page blocks fit A4, no overflow or JavaScript errors');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
