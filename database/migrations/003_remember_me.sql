-- Run once for an existing FaculTrack installation.
CREATE TABLE IF NOT EXISTS remember_tokens (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, selector CHAR(24) NOT NULL UNIQUE, user_id INT NOT NULL,
 validator_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
