-- ====================================================================
-- TEAM04 | KBO 날씨와 경기력 분석 웹사이트
-- 작성 담당: ② DB 설계 (안예은)
-- 작성자: 안예은
-- 수정일: 2026-11-18 | 대상: MariaDB (XAMPP)
-- 주의: 이 파일은 6개 테이블 구조만 만듭니다. 실제 경기 데이터는 포함하지 않습니다.
-- 2026-11-18: player 테이블 제거 (회의 결정 반영)
-- ====================================================================

CREATE DATABASE IF NOT EXISTS `team04`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `team04`;

-- 1. 구장: KBO 경기의 장소 정보 (동일 도시에 여러 구장 가능)
CREATE TABLE IF NOT EXISTS `stadium` (
  `stadium_id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stadium_name` VARCHAR(80) NOT NULL,
  `city` VARCHAR(40) NOT NULL,
  `asos_station_id` VARCHAR(12) DEFAULT NULL,
  `is_dome` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`stadium_id`),
  UNIQUE KEY `uq_stadium_name` (`stadium_name`),
  KEY `idx_stadium_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 팀: 참여 팀 정보
CREATE TABLE IF NOT EXISTS `team` (
  `team_id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `team_name` VARCHAR(80) NOT NULL,
  `short_name` VARCHAR(30) DEFAULT NULL,
  PRIMARY KEY (`team_id`),
  UNIQUE KEY `uq_team_name` (`team_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 날씨: 구장 도시별 일별 ASOS 관측값
-- 동일한 city + obs_date에는 팀에서 선택한 대표 관측소의 값 1건만 적재
CREATE TABLE IF NOT EXISTS `weather` (
  `weather_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `city` VARCHAR(40) NOT NULL,
  `obs_date` DATE NOT NULL,
  `asos_station_id` VARCHAR(12) DEFAULT NULL,
  `avg_temp_c` DECIMAL(5,2) DEFAULT NULL,
  `max_temp_c` DECIMAL(5,2) DEFAULT NULL,
  `precipitation_mm` DECIMAL(8,2) DEFAULT NULL,
  `avg_wind_ms` DECIMAL(6,2) DEFAULT NULL,
  `avg_humidity_pct` DECIMAL(5,2) DEFAULT NULL,
  PRIMARY KEY (`weather_id`),
  UNIQUE KEY `uq_weather_city_date` (`city`, `obs_date`),
  KEY `idx_weather_date` (`obs_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 경기: 홈팀, 원정팀, 구장, 날짜를 기록
-- game_no: 같은 날짜·구장의 더블헤더 등을 구분 (보통 1, 두 번째 경기 2)
CREATE TABLE IF NOT EXISTS `game` (
  `game_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kbo_game_id` VARCHAR(50) DEFAULT NULL,
  `game_date` DATE NOT NULL,
  `stadium_id` SMALLINT UNSIGNED NOT NULL,
  `home_team_id` SMALLINT UNSIGNED NOT NULL,
  `away_team_id` SMALLINT UNSIGNED NOT NULL,
  `game_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `game_status` ENUM('scheduled','completed','cancelled','postponed')
    NOT NULL DEFAULT 'scheduled',
  `start_time` TIME DEFAULT NULL,
  PRIMARY KEY (`game_id`),
  UNIQUE KEY `uq_kbo_game_id` (`kbo_game_id`),
  UNIQUE KEY `uq_game_identity`
    (`game_date`, `stadium_id`, `home_team_id`, `away_team_id`, `game_no`),
  KEY `idx_game_date` (`game_date`),
  KEY `idx_game_stadium_date` (`stadium_id`, `game_date`),
  KEY `idx_game_home_team` (`home_team_id`),
  KEY `idx_game_away_team` (`away_team_id`),
  CONSTRAINT `fk_game_stadium` FOREIGN KEY (`stadium_id`)
    REFERENCES `stadium` (`stadium_id`),
  CONSTRAINT `fk_game_home` FOREIGN KEY (`home_team_id`)
    REFERENCES `team` (`team_id`),
  CONSTRAINT `fk_game_away` FOREIGN KEY (`away_team_id`)
    REFERENCES `team` (`team_id`),
  CONSTRAINT `chk_game_different_teams` CHECK (`home_team_id` <> `away_team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 팀별 경기 기록: 정상 종료된 경기당 홈/원정 각 1행 = 총 2행
-- 경기당 2건 조건과 홈/원정 값의 일치는 적재 후 검증 쿼리로 확인
CREATE TABLE IF NOT EXISTS `team_game_stats` (
  `stats_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id` BIGINT UNSIGNED NOT NULL,
  `team_id` SMALLINT UNSIGNED NOT NULL,
  `is_home` TINYINT(1) NOT NULL,
  `runs` SMALLINT UNSIGNED NOT NULL,
  `hits` SMALLINT UNSIGNED NOT NULL,
  `errors` SMALLINT UNSIGNED DEFAULT NULL,
  `walks` SMALLINT UNSIGNED DEFAULT NULL,
  `result` ENUM('W','L','D') DEFAULT NULL,
  PRIMARY KEY (`stats_id`),
  UNIQUE KEY `uq_stats_game_team` (`game_id`, `team_id`),
  KEY `idx_stats_team_game` (`team_id`, `game_id`),
  KEY `idx_stats_home` (`is_home`),
  CONSTRAINT `fk_stats_game` FOREIGN KEY (`game_id`)
    REFERENCES `game` (`game_id`),
  CONSTRAINT `fk_stats_team` FOREIGN KEY (`team_id`)
    REFERENCES `team` (`team_id`),
  CONSTRAINT `chk_stats_is_home` CHECK (`is_home` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 사용자: 로그인용 계정 정보 (비밀번호 평문 저장 금지)
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `favorite_team_id` SMALLINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_favorite_team` (`favorite_team_id`),
  CONSTRAINT `fk_users_favorite_team` FOREIGN KEY (`favorite_team_id`)
    REFERENCES `team` (`team_id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
