import { api, $, $$, esc, money, showError, formData, busy } from './common.js?v=2611121545';

let plans = {}, client = null;
let plan = new URLSearchParams(location.search).get('plan') || 'silver';

function go(n) {
  $$('[data-step]').forEach(el => el.classList.toggle('hidden', el.dataset.step != n));
  $$('#stepper span').forEach((s, i) => { s.className = i + 1 < n ? 'done' : i + 1 == n ? 'on' : ''; });
  if (n == 3) loadContract();
  if (n == 4) renderSummary();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
$$('[data-go]').forEach(b => b.onclick = () => go(b.dataset.go));

function renderPick() {
  $('#pick').innerHTML = Object.entries(plans).map(([k, p]) => `
    <label><input type="radio" name="plan" value="${k}" ${k === plan ? 'checked' : ''}>
      <div class="opt"><b>${esc(p.name)}</b><em>${money(p.price)}<small style="font-size:14px">/mo</small></em>
      <span>${p.hours} labor hours per year<br>Winter Inspection<br>${esc(p.priority)}</span></div></label>`).join('');
  $$('#pick input').forEach(i => i.onchange = () => { plan = i.value; });
}

$('#toDetails').onclick = e => busy(e.target, async () => {
  showError($('#err1'));
  if (!client) return go(2);
  try { client = (await api('set_plan', { plan })).client; go(3); } catch (err) { showError($('#err1'), err.message); }
});

$('#regForm').onsubmit = e => {
  e.preventDefault();
  busy(e.submitter, async () => {
    showError($('#err2'));
    try {
      client = (await api('register', { ...formData(e.target), plan })).client;
      go(3);
    } catch (err) { showError($('#err2'), err.message); }
  });
};

async function loadContract() {
  $('#contract').innerHTML = await (await fetch('api/?r=contract&fragment=1', { credentials: 'same-origin' })).text();
  if (!$('#s-name').value) $('#s-name').value = client?.name || '';
  sizePad();
}

// ---- signature pad ----
const pad = $('#sigpad'), ctx = pad.getContext('2d');
let drawing = false, signed = false, last = null;
function sizePad() {
  const r = pad.getBoundingClientRect();
  if (!r.width) return;
  const k = Math.min(devicePixelRatio || 1, 2);
  pad.width = r.width * k; pad.height = r.height * k;
  ctx.setTransform(k, 0, 0, k, 0, 0);
  ctx.lineWidth = 2.4; ctx.lineCap = ctx.lineJoin = 'round'; ctx.strokeStyle = '#0c1526';
  signed = false;
}
const pos = e => { const r = pad.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
pad.onpointerdown = e => { drawing = true; last = pos(e); pad.setPointerCapture(e.pointerId); };
pad.onpointermove = e => {
  if (!drawing) return;
  const p = pos(e);
  ctx.beginPath(); ctx.moveTo(...last); ctx.lineTo(...p); ctx.stroke();
  last = p; signed = true;
};
pad.onpointerup = pad.onpointercancel = () => { drawing = false; };
$('#clearSig').onclick = sizePad;
addEventListener('resize', () => { if (!signed) sizePad(); });

$('#signBtn').onclick = e => busy(e.target, async () => {
  showError($('#err3'));
  if (!signed) return showError($('#err3'), 'Please draw your signature in the box.');
  try {
    client = (await api('sign', { name: $('#s-name').value, signature: pad.toDataURL('image/png'), agree: $('#agree').checked })).client;
    go(4);
  } catch (err) { showError($('#err3'), err.message); }
});

// ---- payment ----
function renderSummary() {
  const p = plans[client.plan];
  $('#summary').innerHTML = `
    <div><span>Plan</span><b>${esc(p.name)}</b></div>
    <div><span>Included labor</span><b>${p.hours} hours per year</b></div>
    <div><span>Covered property</span><b>${esc(client.street)}, ${esc(client.city)}</b></div>
    <div><span>Monthly subscription</span><b>${money(p.price)}/month</b></div>`;
}
$('#payBtn').onclick = e => busy(e.target, async () => {
  showError($('#err4'));
  try {
    const r = await api('checkout', {});
    if (r.url) location.href = r.url;
    else if (r.demo) $('#demoBox').classList.remove('hidden');
  } catch (err) { showError($('#err4'), err.message); }
});
$('#demoBtn').onclick = e => busy(e.target, async () => {
  try { await api('demo_pay', {}); location.href = 'account.html?welcome=1'; } catch (err) { showError($('#err4'), err.message); }
});

// ---- start ----
(async () => {
  ({ plans } = await api('plans'));
  if (!plans[plan]) plan = 'silver';
  try { client = (await api('me')).client; } catch { client = null; }
  if (client) plan = client.plan;
  renderPick();
  if (!client) return go(1);
  if (client.status === 'contract') return go(new URLSearchParams(location.search).has('plan') ? 1 : 3);
  if (client.status === 'payment' || client.status === 'canceled') {
    go(4);
    if (location.search.includes('canceled')) showError($('#err4'), 'The payment was not completed. You can try again whenever you are ready.');
    return;
  }
  location.href = 'account.html';
})();
