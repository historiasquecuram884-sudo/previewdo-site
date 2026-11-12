import { api, $, $$, esc, money, date, hours, pill, showError, formData, busy, STATUS } from './common.js?v=2611122024';

let D = null; // last admin_data payload
const today = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD in local time
const daysUntil = d => Math.round((new Date(d + 'T12:00:00') - new Date(today + 'T12:00:00')) / 864e5);
const tel = p => p ? `<a href="tel:${esc(p.replace(/[^\d+]/g, ''))}">${esc(p)}</a>` : '-';

// ---- tabs ----
$$('#tabs button').forEach(b => b.onclick = () => {
  $$('#tabs button').forEach(x => x.classList.toggle('on', x === b));
  $$('[data-pane]').forEach(p => p.classList.toggle('hidden', p.dataset.pane !== b.dataset.tab));
  if (b.dataset.tab === 'settings') loadSettings();
});

// ---- data ----
async function load() {
  try { D = await api('admin_data'); }
  catch (e) {
    if (e.status === 401) { $('#app').classList.add('hidden'); $('#logout').classList.add('hidden'); $('#loginForm').classList.remove('hidden'); return; }
    return showError($('#appErr'), e.message);
  }
  $('#loginForm').classList.add('hidden'); $('#app').classList.remove('hidden'); $('#logout').classList.remove('hidden');
  $('#demoNote').classList.toggle('hidden', D.stripe);
  renderOverview(); renderClients();
}

function renderOverview() {
  const C = D.clients, active = C.filter(c => c.status === 'active' || c.status === 'past_due');
  const pending = C.filter(c => c.status === 'contract' || c.status === 'payment');
  const ren = active.filter(c => c.termEnd && daysUntil(c.termEnd) <= 60).sort((a, b) => a.termEnd.localeCompare(b.termEnd));
  $('#kpis').innerHTML = `
    <div class="kpi"><b>${active.length}</b><span>Active members</span></div>
    <div class="kpi"><b>${money(active.reduce((s, c) => s + c.price, 0))}</b><span>Monthly recurring revenue</span></div>
    <div class="kpi"><b>${ren.length}</b><span>Renewals in 60 days</span></div>
    <div class="kpi"><b>${pending.length}</b><span>Unfinished sign-ups</span></div>`;

  const rows = [
    ...C.filter(c => c.status === 'past_due').map(c => [pill('past_due'), esc(c.name), tel(c.phone), 'Payment failed. Ask the client to update the card.', c.id]),
    ...pending.map(c => [pill(c.status), esc(c.name), tel(c.phone), `Started sign-up on ${date(c.createdAt)} (${esc(c.planName)}). Follow up.`, c.id]),
  ];
  $('#attention').innerHTML = rows.length ? `<table class="list"><tr><th>What</th><th>Client</th><th>Phone</th><th>Details</th></tr>
    ${rows.map(r => `<tr class="click" data-id="${r[4]}"><td>${r[0]}</td><td>${r[1]}</td><td>${r[2]}</td><td>${r[3]}</td></tr>`).join('')}</table>`
    : '<p class="muted">All clear. Nothing waiting for you.</p>';

  $('#renewals').innerHTML = ren.length ? `<table class="list"><tr><th>Client</th><th>Plan</th><th>Contract year ends</th><th>Hours used</th></tr>
    ${ren.map(c => `<tr class="click" data-id="${c.id}"><td>${esc(c.name)}</td><td>${esc(c.planName)}</td><td>${date(c.termEnd)} (${daysUntil(c.termEnd)} days)</td><td>${hours(c.hoursUsed)} of ${hours(c.hoursIncluded)}</td></tr>`).join('')}</table>`
    : '<p class="muted">No renewals in the next 60 days. Agreements renew automatically; the 30 day notice window starts 30 days before the end date.</p>';
}

function renderClients() {
  const s = $('#search').value.trim().toLowerCase(), f = $('#filter').value;
  const list = D.clients.filter(c => (!f || c.status === f) && (!s || [c.name, c.email, c.phone, c.street, c.city].join(' ').toLowerCase().includes(s)));
  $('#clientTable').innerHTML = list.length ? `<table class="list"><tr><th>Client</th><th>Plan</th><th>Status</th><th>Hours left</th><th>Contract year ends</th><th>Next charge</th></tr>
    ${list.map(c => `<tr class="click" data-id="${c.id}">
      <td><b>${esc(c.name)}</b><br><span class="small muted">${esc(c.street)}, ${esc(c.city)}</span></td>
      <td>${esc(c.planName)}<br><span class="small muted">${money(c.price)}/mo</span></td>
      <td>${pill(c.status)} ${c.demo ? pill('demo', 'Preview') : ''}</td>
      <td>${c.startDate ? hours(c.hoursLeft) + ' of ' + hours(c.hoursIncluded) : '-'}</td>
      <td>${date(c.termEnd)}</td><td>${date(c.nextBilling)}</td></tr>`).join('')}</table>`
    : '<p class="muted">No clients found.</p>';
}
$('#search').oninput = $('#filter').onchange = renderClients;

// any row/link with data-id opens the client drawer
document.addEventListener('click', e => {
  const el = e.target.closest('[data-id]');
  if (el && !e.target.closest('a,select')) openClient(+el.dataset.id);
});

// ---- client drawer ----
async function openClient(id) {
  const { client: c, visits } = await api('admin_client', undefined, '&id=' + id);
  const stripeBase = 'https://dashboard.stripe.com/' + (D.stripeMode === 'test' ? 'test/' : '');
  $('#drawerBody').innerHTML = `
    <button class="close" aria-label="Close" id="dClose">×</button>
    <h2>${esc(c.name)}</h2>
    <p>${pill(c.status)} ${c.demo ? pill('demo', 'Preview payment') : ''}</p>
    <div class="card">
      <dl class="dl">
        <dt>Email</dt><dd><a href="mailto:${esc(c.email)}">${esc(c.email)}</a></dd>
        <dt>Phone</dt><dd>${tel(c.phone)}</dd>
        <dt>Property</dt><dd>${esc(c.street)}, ${esc(c.city)}, ${esc(c.state)} ${esc(c.zip)}</dd>
        <dt>Plan</dt><dd>${esc(c.planName)} · ${money(c.price)}/month · ${c.hoursIncluded} h/year</dd>
        <dt>Signed up</dt><dd>${date(c.createdAt)}</dd>
        <dt>Agreement</dt><dd>${c.signedAt ? `Signed ${date(c.signedAt)} by "${esc(c.signedName)}" · <a href="api/?r=contract&id=${c.id}" target="_blank" rel="noopener">Open</a>` : 'Not signed'}</dd>
        <dt>Start date</dt><dd>${date(c.startDate)}</dd>
        <dt>Contract year</dt><dd>${c.termStart ? date(c.termStart) + ' to ' + date(c.termEnd) : '-'}</dd>
        <dt>Hours this year</dt><dd>${hours(c.hoursUsed)} used · ${hours(c.hoursLeft)} left${c.extraHours ? ` · <b style="color:var(--warn)">${hours(c.extraHours)} extra to bill ($100/h)</b>` : ''}</dd>
        <dt>Next charge</dt><dd>${date(c.nextBilling)}${c.stripeStatus ? ' · Stripe: ' + esc(c.stripeStatus) : ''}</dd>
        ${c.stripeCustomer ? `<dt>Stripe</dt><dd><a href="${stripeBase}customers/${esc(c.stripeCustomer)}" target="_blank" rel="noopener">Open customer in Stripe</a></dd>` : ''}
      </dl>
    </div>

    <div class="card">
      <h2>Log a visit</h2>
      <form id="visitForm">
        <div class="grid2">
          <div class="field"><label for="v-date">Date</label><input id="v-date" name="date" type="date" value="${today}" required></div>
          <div class="field"><label for="v-h">Labor hours (min. 1)</label><input id="v-h" name="hours" type="number" min="1" step="0.25" value="1" required></div>
        </div>
        <div class="field"><label for="v-d">Work done</label><input id="v-d" name="description" placeholder="e.g. Winter Inspection, fixed kitchen faucet"></div>
        <button class="btn btn-gold btn-sm">Save visit</button>
      </form>
      <div class="table-wrap" style="margin-top:16px">${visits.length ? `<table class="list"><tr><th>Date</th><th>Hours</th><th>Work</th><th></th></tr>
        ${visits.map(v => `<tr><td>${date(v.date)}</td><td>${hours(v.hours)}</td><td>${esc(v.description)}</td><td><button class="link small" data-delvisit="${v.id}">Delete</button></td></tr>`).join('')}</table>` : '<p class="muted small">No visits logged.</p>'}</div>
    </div>

    <div class="card">
      <h2>Notes and status</h2>
      <form id="noteForm">
        <div class="field"><label for="n-st">Status</label><select id="n-st" name="status">${Object.entries(STATUS).map(([k, v]) => `<option value="${k}" ${k === c.status ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
        <div class="field"><label for="n-ph">Phone</label><input id="n-ph" name="phone" value="${esc(c.phone)}"></div>
        <div class="field"><label for="n-notes">Private notes</label><textarea id="n-notes" name="notes">${esc(c.notes)}</textarea></div>
        <button class="btn btn-navy btn-sm">Save</button>
      </form>
      <p class="small muted">Status changes here do not charge or cancel anything in Stripe.</p>
    </div>

    <div class="card">
      <h2>Account actions</h2>
      <div class="err hidden" id="dErr"></div><div class="okmsg hidden" id="dOk"></div>
      <div class="row">
        <button class="btn btn-ghost btn-sm" id="dReset">Reset client password</button>
        ${c.stripeSub && c.status !== 'canceled' ? `<button class="btn btn-ghost btn-sm" id="dCancel" style="color:var(--bad)">Cancel subscription</button>` : ''}
        ${!c.stripeSub || c.status === 'canceled' ? `<button class="btn btn-ghost btn-sm" id="dDelete" style="color:var(--bad)">Delete client</button>` : ''}
      </div>
    </div>`;
  $('#drawer').classList.remove('hidden');
  const err = m => showError($('#dErr'), m), ok = m => { $('#dOk').textContent = m; $('#dOk').classList.remove('hidden'); };
  const reopen = async () => { await load(); openClient(id); };

  $('#dClose').onclick = closeDrawer;
  $('#visitForm').onsubmit = e => { e.preventDefault(); busy(e.submitter, async () => { await api('visit_add', { ...formData(e.target), client_id: id }); reopen(); }); };
  $$('[data-delvisit]').forEach(b => b.onclick = async () => { if (confirm('Delete this visit?')) { await api('visit_delete', { id: +b.dataset.delvisit }); reopen(); } });
  $('#noteForm').onsubmit = e => { e.preventDefault(); busy(e.submitter, async () => { await api('admin_update', { ...formData(e.target), id }); reopen(); }); };
  $('#dReset').onclick = e => busy(e.target, async () => {
    if (!confirm('Create a temporary password for this client?')) return;
    try { const r = await api('reset_password', { id }); ok(`Temporary password: ${r.password}  (send it to the client; they can change it in their member area)`); } catch (x) { err(x.message); }
  });
  $('#dCancel') && ($('#dCancel').onclick = e => busy(e.target, async () => {
    if (!confirm('Cancel this subscription in Stripe now? Future charges stop immediately.')) return;
    try { await api('cancel_subscription', { id }); reopen(); } catch (x) { err(x.message); }
  }));
  $('#dDelete') && ($('#dDelete').onclick = e => busy(e.target, async () => {
    if (!confirm('Delete this client and all their history? This cannot be undone.')) return;
    try { await api('delete_client', { id }); closeDrawer(); load(); } catch (x) { err(x.message); }
  }));
}
function closeDrawer() { $('#drawer').classList.add('hidden'); }
$('#drawer').onclick = e => { if (e.target.id === 'drawer') closeDrawer(); };
addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

// ---- settings ----
async function loadSettings() {
  const s = await api('settings');
  $('#stripeState').innerHTML = s.stripeKey
    ? `Connected with key <code>${esc(s.stripeKey)}</code>${s.account ? ` · Account: <b>${esc(s.account)}</b>` : ''}${s.stripeKey.includes('_test_') ? ' · <b>TEST mode</b> (no real charges)' : ''}`
    : 'Not connected. The site is in preview mode and payments are simulated.';
}
$('#stripeForm').onsubmit = e => {
  e.preventDefault();
  busy(e.submitter, async () => {
    showError($('#stErr')); $('#stOk').classList.add('hidden');
    try { await api('settings_save', { stripe_key: $('#st-key').value.trim() }); $('#st-key').value = ''; $('#stOk').classList.remove('hidden'); loadSettings(); load(); }
    catch (x) { showError($('#stErr'), x.message); }
  });
};
$('#stRemove').onclick = async () => { if (confirm('Disconnect Stripe? New sign-ups will go back to preview mode.')) { await api('settings_save', { stripe_key: '' }); loadSettings(); load(); } };
$('#pwForm').onsubmit = e => {
  e.preventDefault();
  busy(e.submitter, async () => {
    showError($('#apErr')); $('#apOk').classList.add('hidden');
    try { await api('settings_save', formData(e.target)); e.target.reset(); $('#apOk').classList.remove('hidden'); } catch (x) { showError($('#apErr'), x.message); }
  });
};

// ---- auth ----
$('#loginForm').onsubmit = e => {
  e.preventDefault();
  busy(e.submitter, async () => {
    showError($('#loginErr'));
    try { await api('admin_login', formData(e.target)); load(); } catch (x) { showError($('#loginErr'), x.message); }
  });
};
$('#logout').onclick = async () => { await api('logout'); location.reload(); };
load();
