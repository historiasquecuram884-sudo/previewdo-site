import { api, $, esc, money, date, hours, pill, showError, formData, busy } from './common.js?v=2611121545';

const params = new URLSearchParams(location.search);

function render({ client: c, visits, requests, stripe }) {
  $('#loginForm').classList.add('hidden');
  $('#dash').classList.remove('hidden');
  $('#logout').classList.remove('hidden');

  const live = c.status === 'active' || c.status === 'past_due';
  $('#requestCard').classList.toggle('hidden', !live);
  const pend = $('#pending');
  pend.classList.toggle('hidden', live);
  if (c.status === 'contract') pend.innerHTML = `<h2>Finish your sign-up</h2><p class="muted">Your agreement is not signed yet.</p><a class="btn btn-gold" href="join.html">Review and sign</a>`;
  else if (c.status === 'payment') pend.innerHTML = `<h2>One step left</h2><p class="muted">Your agreement is signed. Complete the payment to activate your plan.</p><a class="btn btn-gold" href="join.html">Go to payment</a>`;
  else if (c.status === 'canceled') pend.innerHTML = `<h2>Your membership is canceled</h2><p class="muted">Want to come back? Call us at (774) 462-9003 or restart your subscription.</p><a class="btn btn-gold" href="join.html">Restart subscription</a>`;

  const pct = Math.min(100, c.hoursIncluded ? (c.hoursUsed / c.hoursIncluded) * 100 : 0);
  $('#planCard').innerHTML = `
    <div class="row" style="justify-content:space-between;align-items:flex-start">
      <div><span class="eyebrow">Hello, ${esc(c.name.split(' ')[0])}</span><h2 style="margin:0">${esc(c.planName)} Plan</h2>
        <p class="muted" style="margin:4px 0 0">${esc(c.street)}, ${esc(c.city)}, ${esc(c.state)} ${esc(c.zip)}</p></div>
      <div>${pill(c.status)} ${c.demo ? pill('demo', 'Preview') : ''}</div>
    </div>
    ${c.status === 'past_due' ? `<div class="err" style="margin-top:16px">Your last payment did not go through. Please update your card to keep your plan active.</div>` : ''}
    <div style="margin-top:22px">
      <div class="row" style="justify-content:space-between"><b>Labor hours this contract year</b><span>${hours(c.hoursLeft)} left of ${hours(c.hoursIncluded)}</span></div>
      <div class="meter"><i style="width:${pct}%"></i></div>
      ${c.extraHours ? `<p class="small" style="color:var(--warn);margin:0">${hours(c.extraHours)} beyond your plan, billed at $100/hour.</p>` : ''}
    </div>
    <dl class="dl" style="margin-top:18px">
      <dt>Monthly price</dt><dd>${money(c.price)}/month</dd>
      <dt>Contract year</dt><dd>${c.termStart ? date(c.termStart) + ' to ' + date(c.termEnd) : '-'}</dd>
      <dt>Next charge</dt><dd>${date(c.nextBilling)}</dd>
      <dt>Agreement</dt><dd>${c.signedAt ? `Signed ${date(c.signedAt)} · <a href="api/?r=contract" target="_blank" rel="noopener">View or print</a>` : 'Not signed yet'}</dd>
    </dl>
    ${stripe && !c.demo && live ? `<div class="row" style="margin-top:18px"><button class="btn btn-navy btn-sm" id="portal">Manage billing and card</button></div>` : ''}`;
  $('#portal')?.addEventListener('click', e => busy(e.target, async () => {
    try { location.href = (await api('portal', {})).url; } catch (err) { showError($('#dashErr'), err.message); }
  }));

  $('#reqList').innerHTML = requests.length ? `<table class="list"><tr><th>Date</th><th>Type</th><th>Request</th><th>Status</th></tr>
    ${requests.map(r => `<tr><td>${date(r.created_at)}</td><td>${pill(r.kind, r.kind)}</td><td>${esc(r.message)}</td><td>${pill(r.status, r.status)}</td></tr>`).join('')}</table>`
    : '<p class="muted">No requests yet.</p>';
  $('#visitList').innerHTML = visits.length ? `<table class="list"><tr><th>Date</th><th>Hours</th><th>Work done</th></tr>
    ${visits.map(v => `<tr><td>${date(v.date)}</td><td>${hours(v.hours)}</td><td>${esc(v.description)}</td></tr>`).join('')}</table>`
    : '<p class="muted">No visits yet. Your Winter Inspection will show up here.</p>';
}

async function load() {
  try { render(await api('me')); }
  catch (e) {
    if (e.status !== 401) return showError($('#dashErr'), e.message);
    $('#dash').classList.add('hidden'); $('#logout').classList.add('hidden');
    $('#loginForm').classList.remove('hidden');
  }
}

$('#loginForm').onsubmit = e => {
  e.preventDefault();
  busy(e.submitter, async () => {
    showError($('#loginErr'));
    try { await api('login', formData(e.target)); await load(); } catch (err) { showError($('#loginErr'), err.message); }
  });
};
$('#logout').onclick = async () => { await api('logout'); location.href = './'; };

$('#reqForm').onsubmit = e => {
  e.preventDefault();
  busy(e.submitter, async () => {
    showError($('#reqErr')); $('#reqOk').classList.add('hidden');
    try { await api('request', formData(e.target)); e.target.reset(); $('#reqOk').classList.remove('hidden'); load(); }
    catch (err) { showError($('#reqErr'), err.message); }
  });
};
$('#pwForm').onsubmit = e => {
  e.preventDefault();
  busy(e.submitter, async () => {
    showError($('#pwErr')); $('#pwOk').classList.add('hidden');
    try { await api('password', formData(e.target)); e.target.reset(); $('#pwOk').classList.remove('hidden'); }
    catch (err) { showError($('#pwErr'), err.message); }
  });
};

(async () => {
  if (params.get('paid')) {
    try { await api('confirm', { session_id: params.get('paid') }); showWelcome(); }
    catch (e) { showError($('#dashErr'), 'We could not confirm the payment yet: ' + e.message + ' If you were charged, please contact us.'); }
    history.replaceState(null, '', 'account.html');
  } else if (params.has('welcome')) { showWelcome(); history.replaceState(null, '', 'account.html'); }
  load();
})();
function showWelcome() { const w = $('#welcome'); w.textContent = 'Welcome to Safety Care Home! Your plan is active. We will contact you to schedule your Winter Inspection.'; w.classList.remove('hidden'); }
