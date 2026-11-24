ALTER TABLE system_settings
 MODIFY COLUMN formats VARCHAR(32) NOT NULL;

ALTER TABLE document_types
 ADD COLUMN formats VARCHAR(32) NOT NULL DEFAULT 'pdf,docx' AFTER description,
 ADD COLUMN template_key CHAR(64) NULL UNIQUE AFTER formats,
 ADD COLUMN template_name VARCHAR(240) NULL AFTER template_key;

UPDATE document_types t
JOIN system_settings s ON s.id=1
SET t.formats=s.formats;

ALTER TABLE submission_versions
 MODIFY COLUMN format ENUM('pdf','docx','xlsx') NOT NULL;
