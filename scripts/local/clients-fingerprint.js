// Fingerprint every view of the Clients page (run through Playwright MCP browser_run_code_unsafe with filename=...; signed in on localhost:8000).
// Same output before and after a refactor = same rendered HTML (tokens, hashes and asset versions are blanked first).
async (page) => {
  await page.goto('http://localhost:8000/crm/clients_appstack.php');
  const urls = {list:'', new:'?action=new', edit_contact:'?action=edit_contact&id=1', view_contact:'?action=view_contact&id=1', view_contact2:'?action=view_contact&id=2', edit_company:'?action=edit_company&id=1', view_company:'?action=view_company&id=1', view_company2:'?action=view_company&id=2', dup:'?view=duplicates'};
  return await page.evaluate(async (urls) => {
    const out = {};
    for (const [k, q] of Object.entries(urls)) {
      const r = await fetch('/crm/clients_appstack.php' + q, {credentials: 'same-origin'});
      let t = await r.text();
      t = t.replace(/(csrf[_-]?token["'\s:=]+)[A-Za-z0-9+\/=_-]{16,}/gi, '$1X').replace(/value="[a-f0-9]{32,}"/g, 'value="X"').replace(/\b[a-f0-9]{40,64}\b/g, 'H').replace(/\?v=\d+/g, '?v=0').replace(/nonce="[^"]+"/g, 'nonce="N"').replace(/\d+ (seconds?|minutes?|hours?|days?) ago/g, 'T ago');
      const h = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(t));
      out[k] = [r.status, t.length, [...new Uint8Array(h)].slice(0, 6).map(b => b.toString(16).padStart(2, '0')).join('')];
    }
    return out;
  }, urls);
}
