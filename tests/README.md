# FaculTrack verification

Run the integration suite from the project root with PHP 8.2+, PDO MySQL, cURL, fileinfo, mbstring, and ZIP enabled. The suite starts its own PHP web server on a free loopback port. It creates a uniquely named database, generated user passwords, upload fixtures, and private storage, then removes those test resources on exit.

Use a local MariaDB/MySQL **test administrator** with permission to create and drop disposable databases and users and grant privileges on those databases. Each web-server run connects as a generated account with SELECT, INSERT, UPDATE and DELETE on its test database. The suite connects to localhost and defaults to port 33317. For a separate local XAMPP test installation using port 3306, use PowerShell 7 as follows.

```powershell
$env:FACULTRACK_TEST_DB_PORT = '3306'
$env:FACULTRACK_TEST_DB_USER = 'your_local_test_account'
$env:FACULTRACK_TEST_DB_PASS = Read-Host 'Test database password' -MaskInput
php tests/integration.php
```

The HTTP checks sign in as distinct users and compare responses against persisted database state. They cover:

| Area | Scenarios |
| --- | --- |
| Authentication | Valid faculty login, invalid password, inactive login, no public registration or remember-me option, administrator account guidance, active-session deactivation, password update, logout |
| Permissions | Faculty privacy, department boundaries, one departmentless Administrator role, administrator-only routes, administrator self-modification, CSRF checks |
| Teaching | Faculty access by role, teaching department head and administrator uploads, toggling an existing session, preservation of previously reviewed versions |
| Documents | Per-type format restrictions, private template upload/download/removal, PDF/DOCX/XLSX upload/download, invalid workbook content, unsupported extension, size limit, duplicate replacement prevention |
| Tracking | Missing and overdue assignments, late submissions, pending review, revision, required reviewer remarks, approval, version history, retirement |
| Administration | Account creation, teaching changes, activity log, department creation and archiving, document type formats and templates, requirement assignment, and deadline changes |
| Reporting | Branded printable summary, styled Excel workbook, filtered CSV output, departmental authorization, assignment-based compliance percentages |
| Input | Literal apostrophes and angle brackets persisted through parameterized SQL and escaped for HTML |

## Browser checks

The optional browser run needs Node 22+ and a separate Chromium profile launched in headless mode with its debugging port restricted to loopback. Start Chrome or Edge with `--headless --remote-debugging-address=127.0.0.1 --remote-debugging-port=9327 --user-data-dir=<temporary-profile-directory>`. Close this test browser after verification.

```powershell
$env:FACULTRACK_BROWSER_TEST = '1'
php tests/integration.php
```

The browser signs in as Faculty, Department Head and Administrator and loads 21 role/page combinations at 1440, 768 and 390 CSS pixels. It checks viewport sizing, document overflow, uncaught JavaScript exceptions, the administrator edit modal and toast dismissal, the removed administrator Subjects route, and opening and closing the mobile navigation drawer. Set `FACULTRACK_DEBUG_URL` for a different debug port. Set `FACULTRACK_QA_SCREENSHOTS` to an existing directory to retain dashboard screenshots.

## Observed results

Previous baseline: on November 22, 2026, the suite passed **61 checks** using PHP 8.2.12, MariaDB 10.4.32 and Chrome 158 on Windows. That baseline predates the current administrator review-navigation and fixed upload-limit checks. The browser workflows then covered 57 role/page/viewport combinations.

The browser checks also verify that sign-in has no account-creation or password-recovery links and displays the administrator contact guidance as plain text.
