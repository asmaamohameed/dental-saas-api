export const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8000').replace(/\/$/, '');
export const API = `${BASE_URL}/api/v1`;

const T = __ENV.TENANT || 1;
const PASSWORD = __ENV.PASSWORD || 'password';

export const USERS = {
  owner: { email: `owner${T}@loadtest.com`, password: PASSWORD },
  doctor: { email: `doctor${T}_1@loadtest.com`, password: PASSWORD },
  receptionist: { email: `receptionist${T}_1@loadtest.com`, password: PASSWORD },
};