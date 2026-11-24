SET NAMES utf8mb4;
SET default_storage_engine=InnoDB;
CREATE TABLE departments (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(140) NOT NULL UNIQUE, code VARCHAR(25) NOT NULL UNIQUE,
 description TEXT NOT NULL, active BOOLEAN NOT NULL DEFAULT 1
);
CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(140) NOT NULL, faculty_id VARCHAR(48) NULL UNIQUE, email VARCHAR(180) NOT NULL UNIQUE,
 username VARCHAR(48) NULL UNIQUE,
 registration_status ENUM('Approved','Pending','Declined') NOT NULL DEFAULT 'Approved',
 password_hash VARCHAR(255) NOT NULL, role ENUM('Faculty','Department Head','Administrator') NOT NULL,
 department_id INT NULL, is_teaching BOOLEAN NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT 1,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (department_id) REFERENCES departments(id)
);
CREATE TABLE remember_tokens (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, selector CHAR(24) NOT NULL UNIQUE, user_id INT NOT NULL,
 validator_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE document_types (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, description TEXT NOT NULL,
 formats VARCHAR(32) NOT NULL DEFAULT 'pdf,docx', template_key CHAR(64) NULL UNIQUE, template_name VARCHAR(240) NULL,
 active BOOLEAN NOT NULL DEFAULT 1
);
CREATE TABLE requirements (
 id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(180) NOT NULL, type_id INT NOT NULL, department_id INT NULL,
 description TEXT NOT NULL, academic_year VARCHAR(20) NOT NULL, semester VARCHAR(30) NOT NULL, deadline DATE NOT NULL,
 active BOOLEAN DEFAULT 1, created_by INT NOT NULL, FOREIGN KEY(type_id) REFERENCES document_types(id),
 FOREIGN KEY(department_id) REFERENCES departments(id), FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE requirement_assignments (
 id INT AUTO_INCREMENT PRIMARY KEY, requirement_id INT NOT NULL, user_id INT NOT NULL, active BOOLEAN DEFAULT 1,
 UNIQUE(requirement_id,user_id), FOREIGN KEY(requirement_id) REFERENCES requirements(id), FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE submissions (
 id INT AUTO_INCREMENT PRIMARY KEY, assignment_id INT NOT NULL, created_at DATETIME NOT NULL,
 retired BOOLEAN NOT NULL DEFAULT 0, FOREIGN KEY(assignment_id) REFERENCES requirement_assignments(id)
);
CREATE TABLE submission_versions (
 id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, version_no INT NOT NULL, title VARCHAR(180) NOT NULL,
 storage_key CHAR(64) NOT NULL UNIQUE, original_name VARCHAR(240) NOT NULL, format ENUM('pdf','docx','xlsx') NOT NULL,
 remarks TEXT NOT NULL, submitted_at DATETIME NOT NULL, deadline_snapshot DATE NOT NULL,
 UNIQUE(submission_id,version_no), FOREIGN KEY(submission_id) REFERENCES submissions(id)
);
CREATE TABLE submission_reviews (
 id INT AUTO_INCREMENT PRIMARY KEY, version_id INT UNIQUE NOT NULL, reviewer_id INT NOT NULL,
 decision ENUM('Approved','Needs Revision') NOT NULL, remarks TEXT NOT NULL, reviewed_at DATETIME NOT NULL,
 FOREIGN KEY(version_id) REFERENCES submission_versions(id), FOREIGN KEY(reviewer_id) REFERENCES users(id)
);
CREATE TABLE activity_logs (
 id INT AUTO_INCREMENT PRIMARY KEY, actor_id INT NULL, action VARCHAR(100) NOT NULL, detail TEXT NOT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(actor_id) REFERENCES users(id)
);
CREATE TABLE system_settings (
 id INT PRIMARY KEY, institution VARCHAR(160) NOT NULL, academic_year VARCHAR(20) NOT NULL, semester VARCHAR(30) NOT NULL,
 max_mb INT NOT NULL, formats VARCHAR(32) NOT NULL
);
INSERT INTO system_settings VALUES (1,'FaculTrack','2026–2027','First Semester',10,'pdf,docx');
CREATE TABLE academic_terms (
 id INT AUTO_INCREMENT PRIMARY KEY, academic_year VARCHAR(20) NOT NULL, semester VARCHAR(30) NOT NULL,
 start_date DATE NULL, end_date DATE NULL,
 active BOOLEAN NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(academic_year,semester)
);
INSERT INTO academic_terms(academic_year,semester)
 SELECT academic_year,semester FROM system_settings WHERE id=1;
CREATE TABLE subjects (
 units DECIMAL(5,2) UNSIGNED NULL,
 id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL, title VARCHAR(180) NOT NULL, section VARCHAR(48) NOT NULL,
 term_id INT NOT NULL, department_id INT NOT NULL, faculty_user_id INT NULL, active BOOLEAN NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(department_id,term_id,code,section),
 FOREIGN KEY(term_id) REFERENCES academic_terms(id),
 FOREIGN KEY(department_id) REFERENCES departments(id),
 FOREIGN KEY(faculty_user_id) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE requirements ADD COLUMN subject_id INT NULL,
 ADD FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL;
