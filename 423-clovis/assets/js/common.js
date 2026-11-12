// Shared helpers for join / account / admin pages.
export async function api(route, body, query = '') {
  const res = await fetch('api/?r=' + route + query, body === undefined ? { credentials: 'same-origin' } : {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-Req': '1' }, body: JSON.stringify(body),
  });
  let data = {};
  try { data = await res.json(); } catch { data = { error: 'Server error. Please try again.' }; }
  if (!res.ok) { const e = new Error(data.error || 'Something went wrong.'); e.status = res.status; throw e; }
  return data;
}
export const $ = (s, el = document) => el.querySelector(s);
export const $$ = (s, el = document) => [...el.querySelectorAll(s)];
export const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const money = n => '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
export function date(s) {
  if (!s) return '-';
  const d = new Date(s.length === 10 ? s + 'T12:00:00' : s.replace(' ', 'T') + 'Z');
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
export const hours = n => (Number(n) % 1 ? Number(n).toFixed(2).replace(/0$/, '') : Number(n)) + ' h';
export const STATUS = { contract: 'Awaiting signature', payment: 'Awaiting payment', active: 'Active', past_due: 'Payment past due', canceled: 'Canceled' };
export const pill = (st, label) => `<span class="pill ${esc(st)}">${esc(label ?? STATUS[st] ?? st)}</span>`;
export function showError(el, msg) { el.textContent = msg || ''; el.classList.toggle('hidden', !msg); }
// Reads a form into an object.
export const formData = f => Object.fromEntries(new FormData(f).entries());
// Disables the submit button while a promise runs.
export async function busy(btn, fn) { btn.disabled = true; try { return await fn(); } finally { btn.disabled = false; } }
