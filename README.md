# FaculTrack

A PHP 8.2 and MySQL/MariaDB application for faculty document submission, departmental review, and academic compliance reporting. Bootstrap 5, Bootstrap Icons, and the application’s JavaScript are served locally. The application runs without Composer, Node, or a frontend build step.

## Use the fresh XAMPP installation

Start **Apache** and **MySQL** in the XAMPP Control Panel. Open **http://localhost/facul-track/**. This checkout uses the database **facul_track** on port **3306**. In phpMyAdmin, select that database to see records created through the website.

The database starts with application settings only. Run the initial account command below to create your administrator. The initial administrator's Employee ID defaults to **Admin01**; administrator accounts have no department or teaching access, and only one account can hold the Administrator role. After signing in, use **Departments** to create departments, **Users** to create accounts, **Academic Terms** to manage terms and dates, **Requirements** to create and assign document requirements, and **Documents** to maintain document types, uploaded templates, and accepted submission formats. Personal **Settings** is for profile and password changes. Administrators can independently review teaching department heads' submissions from **Submissions**. Faculty can download a document type's template and submit PDF, DOCX, or XLSX files when that format is enabled for the assigned type; uploads are limited to 10 MB. Subjects are managed by department heads, not administrators; a subject-linked requirement preselects its assigned faculty member while keeping the assignee list editable.

For an older FaculTrack database that you intend to use with this version, back it up and apply any missing migrations in order using a database administrator account, not the application's restricted database account. Migration `004_faculty_id.sql` adds the Employee ID field; migration `005_default_admin_employee_id.sql` assigns **Admin01** to the first administrator; migration `006_subjects_and_terms.sql` adds academic terms and subjects; migration `007_academic_term_dates.sql` adds term start and end dates; migration `008_document_templates_and_formats.sql` adds private templates, per-type formats, and XLSX submissions; migration `009_departmentless_administrator.sql` removes department and teaching access from existing administrator accounts. Assign each other existing account a unique Employee ID in **User Management** before the user signs in. The fresh schema includes all migrations.

## Fresh XAMPP installation

1. Install XAMPP with PHP 8.2 or later, Apache, MariaDB, and phpMyAdmin. In the XAMPP Control Panel start Apache and MySQL. In `php.ini`, enable `pdo_mysql`, `fileinfo`, `mbstring`, and `zip`. Set `upload_max_filesize=10M` and `post_max_size=12M` or larger. Restart Apache after changing this file. The integration suite also uses PHP cURL.
2. Keep the project outside Apache’s default `htdocs`. In local phpMyAdmin create an **empty** database named `facul_track` with `utf8mb4_unicode_ci`. Select it, then import `database/schema.sql`.
3. In phpMyAdmin create a dedicated local database account. Grant it SELECT, INSERT, UPDATE, and DELETE on `facul_track`. Use a separate database administrator for schema imports and backups.
4. Copy `.env.example` to `.env` for this installation. Enter the database host, port, name, username, and password. Environment variables with the same names take precedence. Keep this file outside the web root.
5. Give the PHP process write access to `storage/private` and its PHP session directory. To use another private storage directory, create it and set `FACULTRACK_STORAGE` to its absolute path. Keep uploads outside **every** publicly served directory.
6. Create the initial administrator below, then configure Apache or run the local PHP server.

### Initial account

From the project directory run the following command. Provide an Employee ID (press Enter to use **Admin01**), full name, and email. The CLI prints a randomly generated password once. Sign in, change it in **Profile**, create departments in **Departments**, and create other accounts in **User Management**.

```powershell
& C:/xampp/php/php.exe scripts/create-admin.php
```

To preview the application with fictional records, run the following optional command after creating the administrator and departments. Confirm by typing `SEED`; it creates one demo department-head account and three demo faculty accounts for each active department, three subjects and four requirements per department, plus pending, approved, returned, and overdue examples. It does not duplicate its data if run again. The generated demo account passwords are printed once.

```powershell
& C:/xampp/php/php.exe scripts/seed-demo.php
```

### Serve the application

For Apache, add a virtual host to `apache/conf/extra/httpd-vhosts.conf` and ensure the file is included in `httpd.conf`. Replace the example paths with the actual checkout. Add `127.0.0.1 facul-track.localhost` to the local hosts file if needed. Restart Apache and visit `http://facul-track.localhost/`.

```apache
<VirtualHost *:80>
    ServerName facul-track.localhost
    DocumentRoot "C:/Projects/Facul-Track/public"
    <Directory "C:/Projects/Facul-Track/public">
        DirectoryIndex index.php
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
```

For a quick local run, start MySQL and run `php artisan serve` from the project folder. Open `http://127.0.0.1:8000/` and sign in to reach the dashboard. Press Ctrl+C in the terminal to stop the server.

## Application structure

| Location | Responsibility |
|---|---|
| `public/index.php` | Browser entry point, session/CSRF validation, authorized routing, transactions and download responses |
| `app/core.php` | Configuration, PDO, authentication, input validation and common permissions |
| `app/manage.php` | Account, catalog, requirement, deadline, and settings changes |
| `app/records.php` | Scoped assignment queries, private file validation, revision history, and review decisions |
| `app/ui.php`, `views/` | Shared layouts, filters, forms, pagination, dashboards, and printable reports |
| `database/`, `scripts/` | Schema, migrations and CLI-only administrator setup |
| `public/assets/` | Local styles, JavaScript, icon fonts, and third-party licenses |
| `tests/` | HTTP integration checks and optional Chromium responsive checks |

## Permissions and recordkeeping

- Faculty see their own assignments and documents. An active Faculty role always permits submission. The teaching setting adds personal submission pages to department head and administrator accounts.
- Department heads manage their own department. Administrators have no department or teaching access, and only one account can hold the Administrator role. Administrators can assign institution-wide requirements and review a teaching head’s work. Every review requires an independent reviewer.
- Assign a department head through **User Management** by setting the role and department. The department directory lists the active heads. A requirement’s explicit assignee list determines who owes a document. Edit the list when assigning new staff.
- An assignment can have one active submission and multiple retained file versions. A returned file can be replaced. Reviews attach to specific versions. Retiring an incorrect submission removes its contribution to current compliance while preserving the original files and decisions.
- Document templates are stored in private storage and downloaded only through the authenticated application. Each document type independently controls faculty submission formats. Faculty submissions and document templates have a 10 MB maximum. Archive requirements, departments, or document categories by clearing **Active**. Disabling an account or teaching access preserves historical records. Use **Reports → Submission History** for departmental or institution-wide history.
- Deadlines use **Asia/Manila**. Submission on the due date through 23:59:59 is on time. Missing work becomes overdue at midnight. Every version stores its submission timestamp and the deadline in effect at upload. A replacement has its own timeliness and starts a fresh review.
- Progress equals the number of active eligible assignments with a current submission divided by all active eligible assignments. Revisions count once. Pending requirements are missing assignments. Pending Review counts uploaded versions awaiting a decision. The deadline display includes the remaining calendar days.
- Report filters apply to the printable PDF layout and Excel report. Both summarize required work by faculty with on-time, late, missing, not-yet-due, and compliance figures. The row-level CSV export remains available, and formula-like CSV values are escaped.
- Sessions expire after 30 minutes of inactivity. Account permissions refresh on each request. Public registrations require administrator approval. Administrators can also create accounts directly. Password changes require the current password. Email password reset requires a verified delivery service and is outside this installation.

## Deployment and backup

Use a PHP/MySQL host that allows a dedicated public document root, private writable storage, and the required PHP extensions. Configure HTTPS and PHP session storage. Expose only `public/`. Disable production error display, enable a private error log, and restrict access to credentials, CLI scripts, database tools, backups, and session files. Use a strong database password and the application’s scoped database grants. Create real accounts in a fresh database. Set PHP, web-server, and application upload limits together.

Back up the database, private upload directory, and configuration together. During backup or restoration, pause writes so file versions and database records agree. Restore into an isolated environment and verify a historical download before relying on a backup.

## Verification

The local PHP/MariaDB workflow and responsive browser checks are documented in [the testing guide](tests/README.md). Deployment to a production host requires verification against that host’s Apache/PHP configuration, upload limits, TLS, and filesystem permissions.



