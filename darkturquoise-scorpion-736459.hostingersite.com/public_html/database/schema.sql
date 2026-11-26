CREATE DATABASE IF NOT EXISTS `u952809689_hrms` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `u952809689_hrms`;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    date_of_birth DATE NULL,
    gender ENUM('male', 'female', 'other', 'prefer_not_to_say') NULL,
    address TEXT NULL,
    department_id INT UNSIGNED NULL,
    department VARCHAR(100) NOT NULL DEFAULT 'General',
    job_title VARCHAR(120) NOT NULL,
    hire_date DATE NOT NULL,
    employment_status ENUM('active', 'on_leave', 'inactive', 'terminated') NOT NULL DEFAULT 'active',
    salary DECIMAL(12,2) NULL,
    profile_photo VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_employee_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_employee_status (employment_status),
    INDEX idx_employee_hire_date (hire_date)
);

CREATE TABLE IF NOT EXISTS emergency_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    relationship VARCHAR(80) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(160) NULL,
    CONSTRAINT fk_emergency_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS attendance_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NULL,
    time_out DATETIME NULL,
    hours_worked DECIMAL(5,2) DEFAULT 0,
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    status ENUM('present', 'absent', 'late', 'half_day', 'holiday') NOT NULL DEFAULT 'present',
    UNIQUE KEY uq_employee_attendance (employee_id, attendance_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leave_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    annual_days DECIMAL(5,2) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
);

CREATE TABLE IF NOT EXISTS payroll_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    payment_date DATE NULL,
    status ENUM('draft', 'processing', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payroll_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
    deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_payroll_period FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payroll_deductions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_record_id BIGINT UNSIGNED NOT NULL,
    deduction_date DATE NOT NULL,
    deduction_type ENUM('absent', 'half_day') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    UNIQUE KEY uq_payroll_deduction_day (payroll_record_id, deduction_date),
    CONSTRAINT fk_deduction_payroll_record FOREIGN KEY (payroll_record_id) REFERENCES payroll_records(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS job_postings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    department VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('draft', 'open', 'closed') NOT NULL DEFAULT 'draft',
    closing_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS job_applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_posting_id INT UNSIGNED NOT NULL,
    candidate_name VARCHAR(160) NOT NULL,
    email VARCHAR(160) NOT NULL,
    phone VARCHAR(30) NULL,
    resume_path VARCHAR(255) NULL,
    stage ENUM('applied', 'screening', 'interview', 'offered', 'hired', 'rejected') NOT NULL DEFAULT 'applied',
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_application_job FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS training_programs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    provider VARCHAR(160) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('planned', 'active', 'completed') NOT NULL DEFAULT 'planned'
);

CREATE TABLE IF NOT EXISTS training_enrollments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    training_program_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    completion_status ENUM('enrolled', 'in_progress', 'completed') NOT NULL DEFAULT 'enrolled',
    completed_at DATE NULL,
    CONSTRAINT fk_enrollment_program FOREIGN KEY (training_program_id) REFERENCES training_programs(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollment_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    expiry_date DATE NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_document_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

INSERT IGNORE INTO departments (name) VALUES ('Engineering'), ('Human Resources'), ('Finance'), ('Sales'), ('Marketing'), ('IT');
INSERT IGNORE INTO leave_types (name, annual_days) VALUES ('Casual leave', 12), ('Sick leave', 10), ('Vacation leave', 18), ('Unpaid leave', 0);

CREATE TABLE IF NOT EXISTS performance_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    review_date DATE NOT NULL,
    reviewer_name VARCHAR(160) NOT NULL,
    rating DECIMAL(3,1) NOT NULL,
    comments TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_review_date (review_date)
);

CREATE TABLE IF NOT EXISTS benefit_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    provider VARCHAR(160) NULL,
    monthly_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS employee_benefits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    benefit_plan_id INT UNSIGNED NOT NULL,
    enrolled_on DATE NOT NULL,
    status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_employee_benefit (employee_id, benefit_plan_id),
    CONSTRAINT fk_employee_benefit_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_benefit_plan FOREIGN KEY (benefit_plan_id) REFERENCES benefit_plans(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS company_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    serial_number VARCHAR(160) NULL,
    purchase_date DATE NULL,
    status ENUM('available', 'assigned', 'maintenance', 'retired') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS asset_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    assigned_on DATE NOT NULL,
    returned_on DATE NULL,
    notes TEXT NULL,
    CONSTRAINT fk_assignment_asset FOREIGN KEY (asset_id) REFERENCES company_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_assignment_open (asset_id, returned_on)
);

-- Login accounts: role is admin, hr or employee (also created/upgraded automatically by the app)
CREATE TABLE IF NOT EXISTS app_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'admin',
    employee_id INT UNSIGNED NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_employee (employee_id)
);
