-- dev seed, local only. login: admin@workpulse.test (ya WDADUS20260001) / admin123
USE hrms;

INSERT INTO companies (name, legal_name, email) VALUES
('WorkPulse Demo Co', 'WorkPulse Demo Private Limited', 'hello@workpulse.test');

-- login id format ke hisab se: WD (WorkPulse Demo) + ADUS (ADmin USer) + 2026 + 0001
INSERT INTO users (company_id, emp_code, email, password, role_id, status)
SELECT c.id, 'WDADUS20260001', 'admin@workpulse.test', '$2y$10$JZOjnym3B19SbNeNcH52d.qPCpa5QvVtrHv7ru/dbi2VkbJs7ryjK', r.id, 'active'
FROM companies c, roles r WHERE c.name = 'WorkPulse Demo Co' AND r.name = 'admin';

INSERT INTO employee_profiles (user_id, first_name, last_name)
SELECT id, 'Admin', 'User' FROM users WHERE email = 'admin@workpulse.test';

-- default leave types demo company ke liye (signup se banao to ye khud aate hai)
INSERT INTO leave_types (company_id, name, code, is_paid, annual_quota)
SELECT c.id, 'Paid Leave', 'PL', 1, 12 FROM companies c WHERE c.name = 'WorkPulse Demo Co';
INSERT INTO leave_types (company_id, name, code, is_paid, annual_quota)
SELECT c.id, 'Sick Leave', 'SL', 1, 6 FROM companies c WHERE c.name = 'WorkPulse Demo Co';
INSERT INTO leave_types (company_id, name, code, is_paid, annual_quota)
SELECT c.id, 'Leave Without Pay', 'LWP', 0, NULL FROM companies c WHERE c.name = 'WorkPulse Demo Co';
