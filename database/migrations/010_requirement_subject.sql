ALTER TABLE requirements ADD COLUMN subject_id INT NULL,
 ADD FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL;
