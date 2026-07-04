-- HRMS schema
-- mysql 8.0+ / mariadb 10.4+, run as: mysql -u root -p < hrms_schema.sql

CREATE DATABASE IF NOT EXISTS hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hrms;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- org setup
-- ---------------------------------------------------------------

CREATE TABLE companies (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    legal_name    VARCHAR(255),
    email         VARCHAR(255),
    phone         VARCHAR(20),
    address       TEXT,
    timezone      VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
    currency      CHAR(3) NOT NULL DEFAULT 'INR',
    logo_url      VARCHAR(500),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE departments (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id    BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    code          VARCHAR(20) NOT NULL,
    parent_id     BIGINT UNSIGNED,          -- nested depts, null for top level
    head_user_id  BIGINT UNSIGNED,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_dept_code (company_id, code),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE designations (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id  BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL,
    level       TINYINT UNSIGNED DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_designation (company_id, title),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE shifts (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id     BIGINT UNSIGNED NOT NULL,
    name           VARCHAR(100) NOT NULL,
    start_time     TIME NOT NULL,
    end_time       TIME NOT NULL,
    grace_mins     SMALLINT UNSIGNED DEFAULT 15,
    half_day_hours DECIMAL(4,2) DEFAULT 4.00,
    full_day_hours DECIMAL(4,2) DEFAULT 8.00,
    is_active      TINYINT(1) DEFAULT 1,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE holidays (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id    BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    holiday_date  DATE NOT NULL,
    is_optional   TINYINT(1) DEFAULT 0,     -- floating/restricted holidays
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_holiday (company_id, holiday_date, name),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- auth / rbac
-- ---------------------------------------------------------------

CREATE TABLE roles (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    is_system    TINYINT(1) DEFAULT 0,      -- system roles cant be deleted from UI
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(100) NOT NULL UNIQUE,   -- module.action e.g. leave.approve
    module  VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id         BIGINT UNSIGNED NOT NULL,
    -- login id, system generated: [company initials][2+2 letters of name][join year][serial]
    -- e.g. OIJODO20220001 = Odoo India + JOhn DOe + 2022 + 0001
    emp_code           VARCHAR(30) NOT NULL,
    email              VARCHAR(255) NOT NULL,
    password           VARCHAR(255) NOT NULL,   -- bcrypt
    -- admin jab employee banata hai to password auto-generate hota hai,
    -- pehli login pe change karwana padta hai
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    role_id            INT UNSIGNED NOT NULL,
    email_verified_at  TIMESTAMP NULL DEFAULT NULL,
    status             ENUM('invited','active','suspended','exited') NOT NULL DEFAULT 'invited',
    failed_logins      TINYINT UNSIGNED DEFAULT 0,
    locked_until       TIMESTAMP NULL DEFAULT NULL,
    last_login_at      TIMESTAMP NULL DEFAULT NULL,
    last_login_ip      VARCHAR(45),
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         TIMESTAMP NULL DEFAULT NULL,
    -- email globally unique so login by email alone is never ambiguous
    UNIQUE KEY uq_email (email),
    UNIQUE KEY uq_emp_code (company_id, emp_code),
    KEY idx_status (status),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- email verification + password reset tokens. store sha256 hash, never the raw token
CREATE TABLE user_tokens (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    purpose     ENUM('email_verify','password_reset','invite') NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_purpose (user_id, purpose),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- employees
-- ---------------------------------------------------------------

CREATE TABLE employee_profiles (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           BIGINT UNSIGNED NOT NULL UNIQUE,
    first_name        VARCHAR(100) NOT NULL,
    last_name         VARCHAR(100),
    phone             VARCHAR(20),
    emergency_contact VARCHAR(150),
    emergency_phone   VARCHAR(20),
    current_address   TEXT,
    permanent_address TEXT,
    dob               DATE,
    gender            ENUM('male','female','other'),
    blood_group       VARCHAR(5),
    photo_url         VARCHAR(500),

    -- job info, only admin can touch these (enforced in app)
    department_id     BIGINT UNSIGNED,
    designation_id    BIGINT UNSIGNED,
    shift_id          BIGINT UNSIGNED,
    manager_id        BIGINT UNSIGNED,          -- reporting manager
    doj               DATE,
    probation_ends    DATE,
    emp_type          ENUM('full_time','part_time','contract','intern') DEFAULT 'full_time',
    work_mode         ENUM('onsite','remote','hybrid') DEFAULT 'onsite',
    exit_date         DATE,

    -- bank + statutory. encrypt PAN/aadhaar at app level before insert
    pan               VARBINARY(255),
    aadhaar           VARBINARY(255),
    bank_account      VARBINARY(255),
    bank_ifsc         VARCHAR(11),
    bank_name         VARCHAR(150),

    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        TIMESTAMP NULL DEFAULT NULL,
    KEY idx_dept (department_id),
    KEY idx_manager (manager_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (designation_id) REFERENCES designations(id) ON DELETE SET NULL,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE employee_documents (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    doc_type    VARCHAR(50) NOT NULL DEFAULT 'other',  -- pan, aadhaar, degree, offer_letter etc
    title       VARCHAR(200) NOT NULL,
    file_url    VARCHAR(500) NOT NULL,
    file_size   INT UNSIGNED,
    mime_type   VARCHAR(100),
    verified_by BIGINT UNSIGNED,           -- HR who verified, null = unverified
    verified_at TIMESTAMP NULL DEFAULT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP NULL DEFAULT NULL,
    KEY idx_user_docs (user_id, deleted_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- attendance
-- ---------------------------------------------------------------

-- one row per user per day. summary gets recalculated from punches.
-- user_id has no cascade: users must be soft-deleted, attendance history stays
CREATE TABLE attendance (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    att_date      DATE NOT NULL,
    shift_id      BIGINT UNSIGNED,
    status        ENUM('present','absent','half_day','on_leave','holiday','week_off') NOT NULL DEFAULT 'absent',
    check_in      DATETIME,
    check_out     DATETIME,
    work_mins     SMALLINT UNSIGNED,
    is_late       TINYINT(1) DEFAULT 0,
    is_regularized TINYINT(1) DEFAULT 0,
    source        ENUM('web','mobile','biometric','admin') DEFAULT 'web',
    remarks       VARCHAR(255),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_date (user_id, att_date),
    KEY idx_date_status (att_date, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- raw punches, append only. supports multiple in/out per day
CREATE TABLE attendance_punches (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    punch_type    ENUM('in','out') NOT NULL,
    punched_at    DATETIME NOT NULL,
    ip            VARCHAR(45),
    lat           DECIMAL(10,7),
    lng           DECIMAL(10,7),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_att (attendance_id, punched_at),
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- "i forgot to punch out" requests
CREATE TABLE regularization_requests (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    att_date       DATE NOT NULL,
    req_check_in   DATETIME,
    req_check_out  DATETIME,
    reason         VARCHAR(500) NOT NULL,
    status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by    BIGINT UNSIGNED,
    reviewed_at    TIMESTAMP NULL DEFAULT NULL,
    review_comment VARCHAR(500),
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- leave
-- ---------------------------------------------------------------

CREATE TABLE leave_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(80) NOT NULL,
    code            VARCHAR(10) NOT NULL,      -- PL, SL, LWP
    is_paid         TINYINT(1) DEFAULT 1,
    needs_document  TINYINT(1) DEFAULT 0,      -- eg medical cert for sick leave > 2 days
    annual_quota    DECIMAL(5,2),              -- null = no limit (LWP)
    carry_forward   TINYINT(1) DEFAULT 0,
    max_carry_days  DECIMAL(5,2),
    allow_half_day  TINYINT(1) DEFAULT 1,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_code (company_id, code),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE leave_balances (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    year          SMALLINT UNSIGNED NOT NULL,
    opening       DECIMAL(6,2) DEFAULT 0,      -- carried forward from prev year
    accrued       DECIMAL(6,2) DEFAULT 0,
    used          DECIMAL(6,2) DEFAULT 0,
    adjusted      DECIMAL(6,2) DEFAULT 0,      -- manual HR corrections, can be negative
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_balance (user_id, leave_type_id, year),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
) ENGINE=InnoDB;

CREATE TABLE leave_requests (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    req_no        VARCHAR(30) NOT NULL UNIQUE,   -- LR-2026-00042, shown in UI
    user_id       BIGINT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    from_date     DATE NOT NULL,
    to_date       DATE NOT NULL,
    half_day_start TINYINT(1) DEFAULT 0,
    half_day_end  TINYINT(1) DEFAULT 0,
    total_days    DECIMAL(5,2) NOT NULL,         -- calculated excluding holidays/weekends
    reason        TEXT,
    document_url  VARCHAR(500),
    status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_status (user_id, status),
    KEY idx_dates (from_date, to_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id),
    CHECK (to_date >= from_date)
) ENGINE=InnoDB;

-- approval history. level 1 = manager, level 2 = HR (if configured)
CREATE TABLE leave_approvals (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leave_request_id BIGINT UNSIGNED NOT NULL,
    approver_id      BIGINT UNSIGNED NOT NULL,
    level            TINYINT UNSIGNED DEFAULT 1,
    action           ENUM('pending','approved','rejected') DEFAULT 'pending',
    comment          TEXT,
    acted_at         TIMESTAMP NULL DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_level (leave_request_id, level),
    KEY idx_approver (approver_id, action),
    FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- payroll
-- ---------------------------------------------------------------

CREATE TABLE salary_components (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,      -- Basic, HRA, PF, TDS...
    code       VARCHAR(20) NOT NULL,
    kind       ENUM('earning','deduction') NOT NULL,
    is_taxable TINYINT(1) DEFAULT 1,
    is_active  TINYINT(1) DEFAULT 1,
    UNIQUE KEY uq_comp (company_id, code),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- salary revisions are new rows, old row gets effective_to set.
-- never update amounts in place, payslips reference these.
-- no cascade on user_id: payroll history must never be hard-deleted
CREATE TABLE salary_structures (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    ctc_annual     DECIMAL(14,2) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to   DATE,                    -- null = current
    reason         VARCHAR(255),            -- hike / promotion / correction
    created_by     BIGINT UNSIGNED NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_current (user_id, effective_to),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE salary_structure_items (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    structure_id BIGINT UNSIGNED NOT NULL,
    component_id INT UNSIGNED NOT NULL,
    monthly_amt  DECIMAL(12,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_item (structure_id, component_id),
    FOREIGN KEY (structure_id) REFERENCES salary_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES salary_components(id)
) ENGINE=InnoDB;

CREATE TABLE payroll_runs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id   BIGINT UNSIGNED NOT NULL,
    month        TINYINT UNSIGNED NOT NULL,
    year         SMALLINT UNSIGNED NOT NULL,
    status       ENUM('draft','processing','finalized','paid') NOT NULL DEFAULT 'draft',
    processed_by BIGINT UNSIGNED,
    finalized_at TIMESTAMP NULL DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_run (company_id, month, year),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- no cascade on user_id: payslips are permanent records
CREATE TABLE payslips (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id        BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    structure_id  BIGINT UNSIGNED NOT NULL,
    working_days  DECIMAL(4,1) NOT NULL,
    present_days  DECIMAL(4,1) DEFAULT 0,
    paid_leaves   DECIMAL(4,1) DEFAULT 0,
    lop_days      DECIMAL(4,1) DEFAULT 0,   -- loss of pay
    gross         DECIMAL(12,2) NOT NULL DEFAULT 0,
    deductions    DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay       DECIMAL(12,2) NOT NULL DEFAULT 0,
    pdf_url       VARCHAR(500),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slip (run_id, user_id),
    FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (structure_id) REFERENCES salary_structures(id)
) ENGINE=InnoDB;

-- line items frozen at generation time. component_name is denormalized
-- on purpose so renaming a component later doesnt rewrite old payslips
CREATE TABLE payslip_items (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payslip_id     BIGINT UNSIGNED NOT NULL,
    component_id   INT UNSIGNED NOT NULL,
    component_name VARCHAR(100) NOT NULL,
    kind           ENUM('earning','deduction') NOT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES salary_components(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- misc
-- ---------------------------------------------------------------

CREATE TABLE notifications (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(50) NOT NULL,
    title       VARCHAR(200) NOT NULL,
    body        VARCHAR(500),
    entity_type VARCHAR(50),                -- leave_request / payslip / etc
    entity_id   BIGINT UNSIGNED,
    read_at     TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_unread (user_id, read_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id    BIGINT UNSIGNED,            -- null = system/cron
    action      VARCHAR(30) NOT NULL,       -- create/update/delete/approve/login...
    entity_type VARCHAR(50) NOT NULL,
    entity_id   BIGINT UNSIGNED,
    old_values  JSON,
    new_values  JSON,
    ip          VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type, entity_id),
    KEY idx_actor (actor_id, created_at),
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- dept head FK added here since users table is created after departments
ALTER TABLE departments
    ADD CONSTRAINT fk_dept_head FOREIGN KEY (head_user_id) REFERENCES users(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------
-- seed
-- ---------------------------------------------------------------

INSERT INTO roles (name, display_name, is_system) VALUES
('admin', 'Admin / HR Officer', 1),
('employee', 'Employee', 1);

INSERT INTO permissions (name, module) VALUES
('employee.view_all', 'employee'),
('employee.edit_all', 'employee'),
('employee.edit_self', 'employee'),
('attendance.view_all', 'attendance'),
('attendance.view_self', 'attendance'),
('attendance.regularize_approve', 'attendance'),
('leave.apply', 'leave'),
('leave.approve', 'leave'),
('leave.view_all', 'leave'),
('payroll.view_self', 'payroll'),
('payroll.view_all', 'payroll'),
('payroll.manage', 'payroll');

-- admin gets everything
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'admin';

-- employee: self service only
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'employee'
  AND p.name IN ('employee.edit_self', 'attendance.view_self', 'leave.apply', 'payroll.view_self');
