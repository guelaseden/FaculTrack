ALTER TABLE academic_terms
 ADD COLUMN start_date DATE NULL AFTER semester,
 ADD COLUMN end_date DATE NULL AFTER start_date;
