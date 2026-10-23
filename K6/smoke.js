import http from 'k6/http';
import { check } from 'k6';
import { API, BASE_URL } from './config.js';
import { loginAll, authHeaders } from './lib/auth.js';

export const options = {
  vus: 1,
  iterations: 1,
  thresholds: {
    checks: ['rate==1.0'],
    http_req_failed: ['rate==0'],
  },
};

export function setup() {
  return loginAll();
}

function get(path, token, name, validate) {
  const res = http.get(`${API}${path}`, { ...authHeaders(token), tags: { name } });
  check(res, {
    [`${name} 200`]: (r) => r.status === 200,
    [`${name} shape`]: (r) => {
      try { return validate(r.json()); } catch (e) { return false; }
    },
  });
  return res;
}

const isList = (b) => b.status === 'success' && Array.isArray(b.data.items);
const isArray = (b) => b.status === 'success' && Array.isArray(b.data);
const isOk = (b) => b.status === 'success';

export default function (tokens) {
  // health
  const up = http.get(`${BASE_URL}/up`, { tags: { name: 'health' } });
  check(up, { 'health 200': (r) => r.status === 200 });

  // owner: كل حاجة
  const o = tokens.owner;
  get('/auth/me', o, 'me', isOk);
  const patients = get('/patients', o, 'patients_list', isList);
  get('/appointments', o, 'appointments_list', isList);
  get('/invoices', o, 'invoices_list', isList);
  get('/doctors', o, 'doctors', isArray);
  get('/doctors/availability', o, 'doctors_availability', isArray);
  get('/dashboard/financial-analytics', o, 'dash_financial', isOk);
  get('/dashboard/income-expense', o, 'dash_income_expense', isOk);
  get('/expenses', o, 'expenses_list', isList);
  get('/inventory-items', o, 'inventory_list', isOk);

  const firstPatient = patients.json('data.items.0.id');
  if (firstPatient) {
    get(`/patients/${firstPatient}`, o, 'patient_show', isOk);
  }

  // doctor: القراءة بس
  get('/patients', tokens.doctor, 'doctor_patients', isList);
  get('/appointments', tokens.doctor, 'doctor_appointments', isList);

  // receptionist
  get('/patients', tokens.receptionist, 'recep_patients', isList);
  get('/invoices', tokens.receptionist, 'recep_invoices', isList);
}