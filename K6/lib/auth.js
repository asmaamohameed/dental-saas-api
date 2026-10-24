import http from 'k6/http';
import { check, fail } from 'k6';
import { API, USERS } from '../config.js';

export function login(user) {
  const res = http.post(
    `${API}/auth/login`,
    JSON.stringify({ email: user.email, password: user.password }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { name: 'login' } }
  );
  const ok = check(res, { 'login 200': (r) => r.status === 200 });
  if (!ok) fail(`login failed for ${user.email}: ${res.status} ${res.body}`);
  return res.json('data.access_token');
}

export function authHeaders(token) {
  return { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } };
}

// login مرة واحدة لكل دور (الـ login محدود بـ 5/دقيقة لكل email+IP)
export function loginAll() {
  return {
    owner: login(USERS.owner),
    doctor: login(USERS.doctor),
    receptionist: login(USERS.receptionist),
  };
}