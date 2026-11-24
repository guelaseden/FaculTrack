# FaculTrack

FaculTrack is a faculty document submission and tracking system. It organizes academic terms, departments, subjects, document requirements, submissions, reviews, and compliance reports in one place.

## Sign in

Sign in with the Employee ID and password provided by the system administrator. Your role determines which pages and records you can access.

## User roles

### Administrator

- Manages departments, user accounts, academic terms, document types, and institution-wide requirements.
- Assigns requirements to departments and faculty, and reviews department-head submissions.
- Views institution-wide reports.
- Has no department assignment. The system supports only one Administrator account.

### Department Head

- Manages subjects and faculty in their own department.
- Creates departmental requirements, sets deadlines, and assigns faculty.
- Reviews submissions from faculty in their department.
- Views department reports. A Department Head may also be enabled to teach and submit documents.

### Faculty

- Views assigned requirements and deadlines.
- Uploads documents, checks review feedback, and resubmits documents returned for revision.
- Views submission history and updates their profile or password in Settings.

## Main workflow

1. **Set up the academic term and document types.** Administrators maintain terms, departments, accounts, document types, accepted file formats, and document templates.
2. **Create requirements.** Administrators create institution-wide or department requirements. Department Heads create requirements for their department and can link a requirement to a subject.
3. **Assign faculty.** Each requirement has an explicit assignee list. Linking a subject preselects the faculty member assigned to that subject; the assignee list can still be edited.
4. **Submit documents.** Faculty upload a file for each assigned requirement. The available formats depend on the document type.
5. **Review submissions.** Department Heads review faculty submissions in their department. Administrators review Department Head submissions. A reviewer cannot review their own submission.
6. **Track progress.** Users can see submission status, review decisions, deadlines, feedback, and historical versions. Administrators and Department Heads can generate reports for their permitted scope.

## Requirements and deadlines

- Academic year and semester are separate fields.
- Deadlines are entered and displayed as **MM-DD-YYYY** in Manila time. A submission made by 11:59 PM on its deadline is on time; it becomes overdue after midnight.
- Requirements can be active or archived. Archiving keeps historical records.
- Requirements may be linked to a subject and assigned to one or more eligible faculty members.

## Submissions and reviews

- Accepted submission formats are configured for each document type. PDF, DOCX, and XLSX are supported when enabled for that type.
- Each submission is limited to **10 MB**.
- Reviews can approve a submission or return it for revision. A return requires reviewer feedback.
- Replacing a returned document creates a new version and starts a new review. Previous versions and their decisions remain in the submission history.
- Compliance reports count each active assignment once, using its current submission. Archived requirements and assignments do not contribute to current totals.

## Reports and exports

Administrators can filter reports by academic term, department, document type, and submission status. Department Heads can filter their reports by academic term, subject, faculty member, and submission status.

The PDF and Excel exports use a faculty summary showing total required, on-time, late, missing/overdue, not-yet-due, and compliance figures. The row-level CSV export is also available.

## Account settings and privacy

Users can update their profile and change their password in **Settings**. Faculty can access only their own assignments and documents. Department Heads are restricted to their department; Administrators have institution-wide access.

Uploaded documents and document templates are stored privately and are available only through the authenticated system.
