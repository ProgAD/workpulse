// endpoint mapping + use session
// sab api calls isi file se jaayengi. usage:
//   const res = await API.auth.login({ email, password });
//   if (res.success) ...
// session cookie (wp_session) har request ke saath khud jaati hai.

const API_BASE = '/workpulse/api';

// core request helper. path: '/auth/login', options: { method, body }
async function apiRequest(path, { method = 'GET', body = null } = {}) {
  const opts = {
    method,
    credentials: 'same-origin', // send session cookie
    headers: {},
  };
  if (body !== null) {
    if (body instanceof FormData) {
      opts.body = body; // browser sets multipart boundary itself
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
  }

  let res;
  try {
    res = await fetch(API_BASE + path, opts);
  } catch (e) {
    return { success: false, message: 'Network error. Server down?', status: 0 };
  }

  // 401 anywhere = session expired -> back to login page
  if (res.status === 401 && !path.startsWith('/auth/login')) {
    window.location.href = '/workpulse/index.html';
    return { success: false, message: 'Session expired', status: 401 };
  }

  let data;
  try {
    data = await res.json();
  } catch (e) {
    data = { success: false, message: 'Invalid server response' };
  }
  data.status = res.status;
  return data; // { success, message, data, status }
}

const get  = (path)       => apiRequest(path);
const post = (path, body) => apiRequest(path, { method: 'POST', body });
const put  = (path, body) => apiRequest(path, { method: 'PUT', body });
const del  = (path)       => apiRequest(path, { method: 'DELETE' });

// ---------------------------------------------------------------
// endpoint map. backend route add karo to yahan bhi entry karo
// ---------------------------------------------------------------

const API = {
  health: () => get('/health'),

  auth: {
    login:  (creds) => post('/auth/login', creds),   // { email, password }
    logout: ()      => post('/auth/logout'),
    me:     ()      => get('/auth/me'),
  },

  employees: {
    list:    (q = '')      => get('/employees' + (q ? '?' + q : '')),
    getById: (id)          => get(`/employees/${id}`),
    create:  (data)        => post('/employees', data),
    update:  (id, data)    => put(`/employees/${id}`, data),
    remove:  (id)          => del(`/employees/${id}`),
  },

  attendance: {
    punchIn:  ()           => post('/attendance/punch-in'),
    punchOut: ()           => post('/attendance/punch-out'),
    today:    ()           => get('/attendance/today'),
    mine:     (month, year)=> get(`/attendance/mine?month=${month}&year=${year}`),
    all:      (q = '')     => get('/attendance' + (q ? '?' + q : '')),      // admin
    regularize: (data)     => post('/attendance/regularize', data),
    regularizations: ()    => get('/attendance/regularizations'),           // admin
    reviewRegularization: (id, data) => post(`/attendance/regularizations/${id}/review`, data),
  },

  leaves: {
    types:    ()           => get('/leaves/types'),
    balances: ()           => get('/leaves/balances'),
    apply:    (data)       => post('/leaves', data),
    mine:     ()           => get('/leaves/mine'),
    all:      (q = '')     => get('/leaves' + (q ? '?' + q : '')),          // admin
    cancel:   (id)         => post(`/leaves/${id}/cancel`),
    review:   (id, data)   => post(`/leaves/${id}/review`, data),           // approve/reject
  },

  payroll: {
    myPayslips: ()         => get('/payroll/payslips/mine'),
    runs:       ()         => get('/payroll/runs'),                         // admin
    createRun:  (data)     => post('/payroll/runs', data),
    payslips:   (runId)    => get(`/payroll/runs/${runId}/payslips`),
  },

  notifications: {
    list:    ()            => get('/notifications'),
    markRead:(id)          => post(`/notifications/${id}/read`),
  },
};

window.API = API;
