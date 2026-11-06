CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_admin BOOLEAN NOT NULL DEFAULT FALSE
);
CREATE TABLE sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  access_hash CHAR(64) NOT NULL UNIQUE,
  refresh_hash CHAR(64) NOT NULL UNIQUE,
  access_until DATETIME NOT NULL,
  refresh_until DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE tournaments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  status ENUM('draft','open','running','finished') NOT NULL DEFAULT 'draft',
  winner_id BIGINT UNSIGNED NULL,
  FOREIGN KEY (winner_id) REFERENCES users(id)
);
CREATE TABLE entries (
  tournament_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (tournament_id, user_id),
  FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tournament_id BIGINT UNSIGNED NOT NULL,
  round_number INT NOT NULL,
  position INT NOT NULL,
  player_one BIGINT UNSIGNED NULL,
  player_two BIGINT UNSIGNED NULL,
  score_one INT NULL,
  score_two INT NULL,
  winner_id BIGINT UNSIGNED NULL,
  UNIQUE (tournament_id, round_number, position),
  FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
  FOREIGN KEY (player_one) REFERENCES users(id),
  FOREIGN KEY (player_two) REFERENCES users(id),
  FOREIGN KEY (winner_id) REFERENCES users(id)
);
CREATE TABLE attempts (
  id CHAR(64) PRIMARY KEY,
  match_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  request_id VARCHAR(64) NULL,
  score_one INT NULL,
  score_two INT NULL,
  UNIQUE (match_id, user_id),
  FOREIGN KEY (match_id) REFERENCES matches(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE ad_settings (
  id INT PRIMARY KEY,
  version INT NOT NULL,
  banner_enabled BOOLEAN NOT NULL,
  banner_ad_unit_id VARCHAR(80) NOT NULL
);
INSERT INTO ad_settings VALUES (1, 1, TRUE, 'ca-app-pub-3940256099942544/6300978111');
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,
  details JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES users(id)
);
