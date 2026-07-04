# WorkPulse

HRMS built for the Odoo Hackathon. Handles the full employee lifecycle for a small company — onboarding, attendance, leaves, salary and payslips — with separate portals for HR and employees.

## What it does

**Admin / HR**
- Register a company (signup creates the first admin)
- Add employees with full details — the system generates a login id and a temporary password
- Employee directory with live status on each card (green = present, plane = on leave, yellow = absent)
- Approve or reject leave requests with the employee's balance shown right in the review
- Review attendance regularization requests
- Set salary per employee (enter monthly wage, components auto-calculate: Basic 50%, HRA, PF, professional tax etc.)
- Run payroll — payslips generate from attendance and approved leaves, unpaid days cut pay

**Employee**
- First login forces a password change
- Check in / check out with a live work timer
- Month-wise attendance with work hours and extra hours
- Apply for time off on a year calendar, track balances (PL / SL / LWP)
- Profile with resume, personal details, salary breakup, documents — can edit only phone, marital status, address and photo
- Notifications for approvals, salary changes and payslips

## Login id format

Generated automatically: company initials + first two letters of first and last name + joining year + serial.
Example: `OIJODO20220001` = Odoo India + JOhn DOe + 2022 + first joinee of that year.

## Stack

Plain PHP 8 (no framework), MySQL/MariaDB, vanilla JS with a single `api.js` layer for all calls. Session-based auth with role permissions (RBAC tables). Runs on XAMPP.

## Setup

1. Clone into your web root (`htdocs/workpulse`)
2. `mysql -u root < schema/hrms_schema.sql`
3. `mysql -u root < schema/seed_dev.sql`
4. Copy `config/.env.example` to `config/.env` and fill your DB details
5. `chmod 777 assets/uploads` (mac/linux)
6. Open `http://localhost/workpulse/` — login `admin@workpulse.test` / `admin123`

## Structure

```
api/          php backend, one folder per module (auth, employees, attendance, leaves, salary, payroll, documents, notifications)
admin/        HR portal pages
employee/     employee portal pages
assets/       css, js (api.js = endpoint map), uploads
config/       db config, reads from .env
schema/       full db schema + dev seed
```

Full page/endpoint map lives in `assets/file_str.txt`.

## Team

Built by Rajan, Aditya and Maaz.
