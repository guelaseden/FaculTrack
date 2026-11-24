CREATE TABLE academic_terms (
 id INT AUTO_INCREMENT PRIMARY KEY, academic_year VARCHAR(20) NOT NULL, semester VARCHAR(30) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(academic_year,semester)
);
INSERT INTO academic_terms(academic_year,semester)
 SELECT academic_year,semester FROM system_settings WHERE id=1;
CREATE TABLE subjects (
 id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL, title VARCHAR(180) NOT NULL, section VARCHAR(48) NOT NULL,
 term_id INT NOT NULL, department_id INT NOT NULL, faculty_user_id INT NULL, active BOOLEAN NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(department_id,term_id,code,section),
 FOREIGN KEY(term_id) REFERENCES academic_terms(id),
 FOREIGN KEY(department_id) REFERENCES departments(id),
 FOREIGN KEY(faculty_user_id) REFERENCES users(id) ON DELETE SET NULL
);
