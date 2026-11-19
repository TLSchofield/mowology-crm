/*
 * Ittai embeddable enquiry form.
 *
 * Paste on any page of a tenant's own website:
 *   <script src="https://mowology.ca/assets/js/embed.js" data-key="TENANT_KEY" async></script>
 *
 * Optional attributes: data-target="#element-id" (where to render; default: right after the script tag),
 * data-title, data-button, data-thanks, data-accent (a CSS colour for the button), data-accent-text (button text colour).
 * The key only names the tenant; the server also checks the page's Origin against the tenant's allowed sites.
 * The form inherits the host page's fonts and text colour. Visitor text is only ever set via textContent.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script) return;
  var key = script.getAttribute('data-key');
  if (!key) return;

  var endpoint = new URL('/crm/api/embed-lead.php', script.src).href;
  var title = script.getAttribute('data-title') || 'Send us a message';
  var buttonText = script.getAttribute('data-button') || 'Send';
  var thanks = script.getAttribute('data-thanks') || 'Thank you. We will be in touch soon.';
  var accent = script.getAttribute('data-accent') || '#1f6f4a';
  var accentText = script.getAttribute('data-accent-text') || '#ffffff';
  function safeColour(c) { return c.replace(/[^#a-zA-Z0-9(),.% -]/g, ''); }

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text) n.textContent = text;
    return n;
  }

  var uid = 'ittai-' + Math.random().toString(36).slice(2, 8);
  var style = el('style', {}, [
    '.' + uid + '{font:inherit;color:inherit;max-width:32rem}',
    '.' + uid + ' label{display:block;margin:.6em 0 .2em;font-size:.9em}',
    '.' + uid + ' input,.' + uid + ' textarea{box-sizing:border-box;width:100%;padding:.55em;font:inherit;color:inherit;background:transparent;border:1px solid currentColor;border-radius:4px}',
    '.' + uid + ' button{margin-top:1em;padding:.6em 1.4em;font:inherit;color:' + safeColour(accentText) + ';background:' + safeColour(accent) + ';border:0;border-radius:4px;cursor:pointer}',
    '.' + uid + ' button[disabled]{opacity:.6;cursor:default}',
    '.' + uid + ' .hp{position:absolute;left:-9999px;height:0;overflow:hidden}',
    '.' + uid + ' .msg{margin-top:.8em}'
  ].join(''));

  var form = el('form', { 'class': uid, novalidate: 'novalidate' });
  form.appendChild(el('h3', { style: 'margin:0 0 .4em' }, title));

  function field(name, label, tag, required) {
    var id = uid + '-' + name;
    form.appendChild(el('label', { 'for': id }, label + (required ? ' *' : '')));
    var input = el(tag, { id: id, name: name, maxlength: name === 'message' ? '5000' : '254' });
    if (tag === 'input') input.type = name === 'email' ? 'email' : (name === 'phone' ? 'tel' : 'text');
    if (tag === 'textarea') input.rows = 4;
    if (required) input.required = true;
    form.appendChild(input);
  }
  field('name', 'Name', 'input', true);
  field('email', 'Email', 'input', true);
  field('phone', 'Phone (optional)', 'input', false);
  field('message', 'Message', 'textarea', false);

  var hp = el('div', { 'class': 'hp', 'aria-hidden': 'true' });
  var hpInput = el('input', { name: 'website', tabindex: '-1', autocomplete: 'off' });
  hp.appendChild(hpInput);
  form.appendChild(hp);

  var button = el('button', { type: 'submit' }, buttonText);
  var msg = el('div', { 'class': 'msg', role: 'status', 'aria-live': 'polite' });
  form.appendChild(button);
  form.appendChild(msg);

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    msg.textContent = '';
    var name = form.elements.name.value.trim();
    var email = form.elements.email.value.trim();
    if (!name || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      msg.textContent = 'Please enter your name and a valid email address.';
      return;
    }
    var data = new FormData(form);
    data.append('key', key);
    data.append('source_url', location.href.slice(0, 500));
    button.disabled = true;
    fetch(endpoint, { method: 'POST', body: data })
      .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
      .then(function (j) {
        if (j && j.success) {
          form.reset();
          msg.textContent = thanks;
        } else {
          msg.textContent = (j && j.error) || 'Sorry, that did not send. Please try again.';
          button.disabled = false;
        }
      })
      .catch(function () {
        msg.textContent = 'Sorry, that did not send. Please try again.';
        button.disabled = false;
      });
  });

  var holder = document.createElement('div');
  holder.appendChild(style);
  holder.appendChild(form);
  var targetSel = script.getAttribute('data-target');
  var target = targetSel ? document.querySelector(targetSel) : null;
  if (target) target.appendChild(holder);
  else script.parentNode.insertBefore(holder, script.nextSibling);
})();
