# PeopleHub HR Management System

PeopleHub is a responsive PHP/MySQL HR management application for a WAMP installation. It includes working employee management, recruitment, attendance, leave approval, payroll drafts, training, performance reviews, benefits, asset assignments, and live reports.

## Run with WAMP

1. Start Apache and MySQL in WAMP.
2. Open phpMyAdmin at `http://localhost/phpmyadmin` and import `database/schema.sql`. The script creates the `hrms` database, base reference data, and every table used by the application.
3. Put this project folder inside `C:\wamp64\www`.
4. Open `http://localhost/PS%20hr%20Management%20System/`.

The default connection uses MySQL on `127.0.0.1:3306`, username `root`, and a blank password. Set these environment variables if your database differs:

- `HRMS_DB_HOST`
- `HRMS_DB_PORT`
- `HRMS_DB_NAME`
- `HRMS_DB_USER`
- `HRMS_DB_PASSWORD`

## Notes

- Import the schema again after upgrading from the original dashboard-only version; it uses `CREATE TABLE IF NOT EXISTS`, so existing data is preserved.
- All data-changing forms validate server-side input and include CSRF protection.
- Payroll drafts use the monthly salary currently saved on each active employee record. Absent days deduct one daily rate and half-day attendance deducts half a daily rate; each deduction is stored by date in `payroll_deductions`.
- Payroll history includes an **Export XML** link. Import the downloaded Tally-compatible Journal XML into TallyPrime; map the `Salary Expense` and employee ledger names to the ledgers used by your company.

## Login and roles

Open `login.php` and pick the login type: **Admin**, **HR** or **Employee**. An account only works on its own tab. Visiting any page while signed out redirects to the login page.

| Role | Can do |
| --- | --- |
| Admin | Everything: all HR modules, Settings, and creating/removing Admin, HR and Employee accounts |
| HR | All HR modules (employees, recruitment, attendance, leave, payroll, etc.) and creating Employee accounts. No Settings, cannot create Admin/HR accounts |
| Employee | Only `employee.php`: their own attendance (by month) and their own payment history (processed and paid periods) |

- First run: when no users exist, `login.php` asks you to create the first Admin account.
- Create other logins in **User accounts** (sidebar). An Employee login must be linked to an existing employee record; that link decides whose data they see.
- Passwords are stored hashed in the `app_users` table, which is created and upgraded automatically.
- 5 wrong attempts lock sign-in for 5 minutes for that browser session.
- Sign out with the icon next to your name in the sidebar.
