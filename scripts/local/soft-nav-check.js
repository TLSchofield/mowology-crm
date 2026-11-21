// Soft-navigation check (run with the Playwright MCP run_code tool, signed in to the local site, it_design=1).
// Loads each page normally (it_soft=0) and by soft navigation (it_soft=1) and compares text, nodes, body class, head card and selected head.
// A page joins SAFE in public/crm/js/ittai-soft.js only when every entry reads textSame/bodySame true and equal cards.
async (page) => {
  const B='http://localhost:8000', pages=['/crm/quotes_appstack.php','/crm/kaname_appstack.php','/crm/quotes/','/crm/jobs/','/crm/invoices/','/crm/dashboard_appstack.php'];
  const snap = () => page.evaluate(()=>{const m=document.querySelector('main.content');const kids=[...m.querySelectorAll('*')].length;
    const card=document.querySelector('.it-hp.is-open, .it-fw-card .it-fwc-box');const r=card&&card.getBoundingClientRect();
    return {text:m.innerText.replace(/\s+/g,' ').slice(0,4000), nodes:kids, body:document.body.className.split(' ').filter(c=>!/^it-rail$/.test(c)).sort().join(' '), card:r?[Math.round(r.left),Math.round(r.top)]:null, sel:(document.querySelector('.it-grp.is-sel')||{dataset:{}}).dataset.head, sheets:document.styleSheets.length}});
  const res={};
  // hard loads, soft off
  await page.context().addCookies([{name:'it_soft',value:'0',url:B}]);
  const hardErr=[]; const h=m=>{if(m.type()==='error')hardErr.push(m.text().slice(0,100))}; page.on('console',h);
  for (const p of pages){ await page.goto(B+p); await page.waitForTimeout(1500); res[p]={hard:await snap()}; }
  page.off('console',h);
  // soft
  await page.context().addCookies([{name:'it_soft',value:'1',url:B}]);
  await page.goto(B+'/crm/dashboard_appstack.php'); await page.waitForTimeout(1200);
  for (const p of pages){ await page.evaluate(u=>navigation.navigate(u),p); await page.waitForTimeout(1800); res[p].soft=await snap(); }
  const diff={};
  for (const p of pages){ const a=res[p].hard,b=res[p].soft; diff[p]={textSame:a.text===b.text, nodes:[a.nodes,b.nodes], bodySame:a.body===b.body, body:a.body===b.body?undefined:[a.body,b.body], card:[a.card,b.card], sel:[a.sel,b.sel], sheets:[a.sheets,b.sheets]}; if(a.text!==b.text){let i=0;while(a.text[i]===b.text[i])i++;diff[p].firstDiff=[a.text.slice(i-30,i+60),b.text.slice(i-30,i+60)];} }
  return {diff, hardErr};
}
