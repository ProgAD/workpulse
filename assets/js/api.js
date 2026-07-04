// api layer - frontend js -> api.js -> php backend -> mysql
// saari api calls isi file se, pages me direct fetch mat likhna

const API = {

  BASE_URL: '/workpulse/api',

  LOGIN_PAGE: '/workpulse/index.html',   // 401 aane pe yahan bhejte hai

  // endpoint -> php file mapping
  // naya backend file banao to yahan entry karna mat bhulna
  _endpointMap: {
    'health':          'health.php',

    'login':           'auth.php?action=login',
    'signup':          'auth.php?action=signup',
    'logout':          'auth.php?action=logout',
    'change_password': 'auth.php?action=change_password',
    'me':              'auth.php?action=me',

    'employees':        'employees/index.php',
    'employee_create':  'employees/index.php?action=create',
    'employee_update':  'employees/index.php?action=update',
    'employee_remove':  'employees/index.php?action=remove',
    'me_profile':       'employees/index.php?action=me_profile',
    'update_self':      'employees/index.php?action=update_self',
    'upload_photo':     'employees/index.php?action=upload_photo',

    'documents':        'documents/index.php',
    'document_upload':  'documents/index.php?action=upload',
    'document_remove':  'documents/index.php?action=remove',

    // niche wale abhi banne hai (php file ready hote hi kaam karenge)

    'punch_in':        'attendance/index.php?action=punch_in',
    'punch_out':       'attendance/index.php?action=punch_out',
    'att_today':       'attendance/index.php?action=today',
    'att_mine':        'attendance/index.php?action=mine',
    'attendance':      'attendance/index.php',                          // admin list
    'regularize':      'attendance/index.php?action=regularize',
    'regularizations': 'attendance/index.php?action=regularizations',   // admin
    'regularize_review': 'attendance/index.php?action=review',          // admin

    'leave_types':     'leaves/index.php?action=types',
    'leave_balances':  'leaves/index.php?action=balances',
    'leave_apply':     'leaves/index.php?action=apply',
    'leaves_mine':     'leaves/index.php?action=mine',
    'leaves':          'leaves/index.php',                              // admin list
    'leave_cancel':    'leaves/index.php?action=cancel',
    'leave_type_create': 'leaves/index.php?action=create_type',           // admin
    'leave_review':    'leaves/index.php?action=review',                // approve / reject

    'my_payslips':     'payroll/index.php?action=my_payslips',
    'payroll_runs':    'payroll/index.php?action=runs',                 // admin
    'payroll_create':  'payroll/index.php?action=create_run',           // admin
    'run_payslips':    'payroll/index.php?action=run_payslips',         // admin

    'salary_structure': 'salary/index.php?action=structure',              // ?user_id= admin ke liye
    'salary_save':      'salary/index.php?action=save',                   // admin

    'notifications':   'notifications/index.php',
    'notif_read':      'notifications/index.php?action=mark_read',
  },


  // core request method - session cookie har request ke saath jati hai

  async request(endpoint, method = 'GET', data = null) {
    // offline hai to server tak jane ka matlab hi nahi
    if (!navigator.onLine) {
      return Promise.reject(new Error('No internet connection'));
    }

    const url = this._buildURL(endpoint, method, data);

    const opts = {
      method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    };

    // body sirf POST/PUT me jati hai
    if (data && (method === 'POST' || method === 'PUT')) {
      if (data instanceof FormData) {
        opts.body = data;   // file upload - browser khud boundary set karega
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data);
      }
    }

    try {
      const res = await fetch(url, opts);

      let json;
      try {
        json = await res.json();
      } catch (parseErr) {
        // 404 matlab wo php file abhi bani hi nahi hai
        if (res.status === 404) {
          throw new Error('Ye API endpoint abhi bana nahi hai (' + endpoint + ')');
        }
        // warna php ne html error ya warning print kar di hogi
        throw new Error('Server returned invalid JSON — check PHP errors');
      }

      if (!res.ok) {
        // session khatam -> wapas login pe (login page pe ho to redirect mat karo, loop ban jayega)
        const onLoginPage = window.location.pathname.endsWith('/workpulse/') || window.location.pathname.endsWith(this.LOGIN_PAGE);
        if (res.status === 401 && endpoint !== 'login' && !onLoginPage) {
          if (!this._redirecting401) {
            this._redirecting401 = true;
            window.location.href = this.LOGIN_PAGE;
          }
          const authErr = new Error('Session expired — please login again');
          authErr.isAuthError = true;
          throw authErr;
        }
        const apiErr = new Error(json.message || `Server error (${res.status})`);
        apiErr.responseData = json;
        apiErr.responseStatus = res.status;
        throw apiErr;
      }

      return json;   // { success, message, data }

    } catch (err) {
      if (!err.isAuthError) {
        console.error(`API [${method}] ${endpoint}:`, err.message);
      }
      throw err;
    }
  },

  // url builder - 'employees/5' jaisa endpoint do to id=5 query me lag jayegi
  _buildURL(endpoint, method, data) {
    const parts = endpoint.split('/');
    const resource = parts[0];
    const id = parts[1] || null;

    const phpFile = this._endpointMap[resource];
    if (!phpFile) {
      console.warn(`No PHP mapping for endpoint: "${resource}"`);
      return `${this.BASE_URL}/${resource}.php`;
    }

    let url = `${this.BASE_URL}/${phpFile}`;

    if (id) {
      const sep = url.includes('?') ? '&' : '?';
      url += `${sep}id=${encodeURIComponent(id)}`;
    }

    // GET me data query params ban jata hai
    if (method === 'GET' && data && typeof data === 'object') {
      const params = new URLSearchParams(data).toString();
      if (params) {
        const sep = url.includes('?') ? '&' : '?';
        url += `${sep}${params}`;
      }
    }

    return url;
  },


  // shortcuts, usage:
  //   await API.post('login', { email, password })
  //   await API.get('att_mine', { month: 7, year: 2026 })
  //   await API.get('employees/5')

  get(endpoint, params = null) {
    return this.request(endpoint, 'GET', params);
  },

  post(endpoint, data = null) {
    return this.request(endpoint, 'POST', data);
  },

  put(endpoint, data = null) {
    return this.request(endpoint, 'PUT', data);
  },

  delete(endpoint) {
    return this.request(endpoint, 'DELETE');
  },
};

window.API = API;
