-- Run once for an existing FaculTrack installation. Fresh schema imports already include these fields.
ALTER TABLE users ADD username VARCHAR(48) NULL UNIQUE,
    ADD registration_status ENUM('Approved','Pending','Declined') NOT NULL DEFAULT 'Approved';
UPDATE users SET username=CONCAT('user-',id) WHERE username IS NULL;
