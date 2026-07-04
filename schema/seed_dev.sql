-- dev seed, local only. login: admin@workpulse.test (ya WDADUS20260001) / admin123
USE hrms;

INSERT INTO companies (name, legal_name, email) VALUES
('WorkPulse Demo Co', 'WorkPulse Demo Private Limited', 'hello@workpulse.test');

-- login id format ke hisab se: WD (WorkPulse Demo) + ADUS (ADmin USer) + 2026 + 0001
INSERT INTO users (company_id, emp_code, email, password, role_id, status, email_verified_at)
SELECT c.id, 'WDADUS20260001', 'admin@workpulse.test', '$2y$10$JZOjnym3B19SbNeNcH52d.qPCpa5QvVtrHv7ru/dbi2VkbJs7ryjK', r.id, 'active', NOW()
FROM companies c, roles r WHERE c.name = 'WorkPulse Demo Co' AND r.name = 'admin';

INSERT INTO employee_profiles (user_id, first_name, last_name)
SELECT id, 'Admin', 'User' FROM users WHERE email = 'admin@workpulse.test';
