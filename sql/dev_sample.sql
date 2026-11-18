-- 작성자: 정윤아
-- ============================================================
-- [제출용 아님] 백엔드 로컬 개발용 임시 스키마 + 샘플 데이터
-- - dbcreate.sql / dbinsert.sql 이 나오기 전까지 테스트용으로만 쓴다.
--  - 최종 스키마는 dbcreate.sql 을 따른다. 이 파일은 제출 전에 삭제한다.
--  - 실행하면 기존 테이블을 지우고 다시 만든다.
--  - 숫자(점수·날씨 등)는 실제 기록이 아닌 가상 값이다.
-- 실행: mysql -u team04 -p team04 < sql/dev_sample.sql
-- ============================================================

USE team04;
SET NAMES utf8mb4;

DROP TABLE IF EXISTS team_game_stats;
DROP TABLE IF EXISTS game;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS weather;
DROP TABLE IF EXISTS team;
DROP TABLE IF EXISTS stadium;

-- ---------- 임시 스키마 (요청한 변경사항 반영 기준) ----------

CREATE TABLE stadium (
    stadium_id      INT AUTO_INCREMENT PRIMARY KEY,
    stadium_name    VARCHAR(50) NOT NULL UNIQUE,
    city            VARCHAR(20) NOT NULL,
    asos_station_id INT,
    is_dome         TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE team (
    team_id    INT AUTO_INCREMENT PRIMARY KEY,
    team_name  VARCHAR(50) NOT NULL UNIQUE,
    short_name VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE weather (
    weather_id       INT AUTO_INCREMENT PRIMARY KEY,
    city             VARCHAR(20) NOT NULL,
    obs_date         DATE NOT NULL,
    asos_station_id  INT,
    avg_temp_c       DECIMAL(4,1),
    max_temp_c       DECIMAL(4,1),
    precipitation_mm DECIMAL(6,1),
    avg_wind_ms      DECIMAL(4,1),
    avg_humidity_pct DECIMAL(4,1),
    UNIQUE KEY uq_weather_city_date (city, obs_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE game (
    game_id      INT AUTO_INCREMENT PRIMARY KEY,
    kbo_game_id  VARCHAR(30) UNIQUE,
    game_date    DATE NOT NULL,
    stadium_id   INT NOT NULL,
    home_team_id INT NOT NULL,
    away_team_id INT NOT NULL,
    game_no      TINYINT NOT NULL DEFAULT 1,
    game_status  ENUM('scheduled','completed','cancelled','postponed') NOT NULL DEFAULT 'scheduled',
    start_time   TIME,
    UNIQUE KEY uq_game (game_date, stadium_id, home_team_id, away_team_id, game_no),
    CONSTRAINT chk_game_teams CHECK (home_team_id <> away_team_id),
    FOREIGN KEY (stadium_id)   REFERENCES stadium(stadium_id),
    FOREIGN KEY (home_team_id) REFERENCES team(team_id),
    FOREIGN KEY (away_team_id) REFERENCES team(team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE team_game_stats (
    stats_id INT AUTO_INCREMENT PRIMARY KEY,
    game_id  INT NOT NULL,
    team_id  INT NOT NULL,
    is_home  TINYINT(1) NOT NULL,
    runs     SMALLINT NOT NULL,
    hits     SMALLINT NOT NULL,
    errors   SMALLINT,
    walks    SMALLINT,
    result   ENUM('W','L','D'),
    UNIQUE KEY uq_stats_game_team (game_id, team_id),
    FOREIGN KEY (game_id) REFERENCES game(game_id),
    FOREIGN KEY (team_id) REFERENCES team(team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    user_id          INT AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(30) NOT NULL UNIQUE,
    password_hash    VARCHAR(255) NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    favorite_team_id INT NULL,
    FOREIGN KEY (favorite_team_id) REFERENCES team(team_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 샘플 데이터 ----------

INSERT INTO stadium (stadium_id, stadium_name, city, asos_station_id, is_dome) VALUES
(1, '잠실야구장',            '서울', 108, 0),
(2, '고척스카이돔',          '서울', 108, 1),
(3, '인천SSG랜더스필드',     '인천', 112, 0),
(4, '수원KT위즈파크',        '수원', 119, 0),
(5, '대전한화생명볼파크',    '대전', 133, 0),
(6, '대구삼성라이온즈파크',  '대구', 143, 0),
(7, '광주기아챔피언스필드',  '광주', 156, 0),
(8, '사직야구장',            '부산', 159, 0),
(9, '창원NC파크',            '창원', 155, 0);

INSERT INTO team (team_id, team_name, short_name) VALUES
(1,  'LG 트윈스',     'LG'),
(2,  '두산 베어스',   '두산'),
(3,  '키움 히어로즈', '키움'),
(4,  'SSG 랜더스',    'SSG'),
(5,  'KT 위즈',       'KT'),
(6,  '한화 이글스',   '한화'),
(7,  '삼성 라이온즈', '삼성'),
(8,  'KIA 타이거즈',  'KIA'),
(9,  '롯데 자이언츠', '롯데'),
(10, 'NC 다이노스',   'NC');

-- 7/14~7/15 날씨 (창원 7/15 는 일부러 비워 둠 → "날씨 데이터 없음" 경고 테스트용)
INSERT INTO weather (city, obs_date, asos_station_id, avg_temp_c, max_temp_c, precipitation_mm, avg_wind_ms, avg_humidity_pct) VALUES
('서울', '2026-07-14', 108, 28.4, 33.1,  0.0, 2.1, 68.0),
('인천', '2026-07-14', 112, 26.9, 30.2,  0.0, 3.4, 74.5),
('대구', '2026-07-14', 143, 30.2, 36.0,  0.0, 1.8, 58.3),
('광주', '2026-07-14', 156, 28.8, 34.2,  2.5, 1.9, 77.0),
('부산', '2026-07-14', 159, 27.1, 30.5,  0.0, 3.8, 79.2),
('서울', '2026-07-15', 108, 25.3, 27.8, 42.5, 2.9, 88.1),
('수원', '2026-07-15', 119, 25.0, 28.0, 35.0, 2.6, 86.4),
('대전', '2026-07-15', 133, 27.6, 31.9,  8.0, 1.7, 80.2);

INSERT INTO game (game_id, kbo_game_id, game_date, stadium_id, home_team_id, away_team_id, game_no, game_status, start_time) VALUES
(1, '20260714LGOB0', '2026-07-14', 1, 1, 2,  1, 'completed', '18:30:00'),  -- 잠실 LG vs 두산
(2, '20260714WOSK0', '2026-07-14', 2, 3, 4,  1, 'completed', '18:30:00'),  -- 고척 키움 vs SSG (돔)
(3, '20260714SSHH0', '2026-07-14', 6, 7, 6,  1, 'completed', '18:30:00'),  -- 대구 삼성 vs 한화
(4, '20260714HTNC0', '2026-07-14', 7, 8, 10, 1, 'completed', '18:30:00'),  -- 광주 KIA vs NC
(5, '20260714LTKT0', '2026-07-14', 8, 9, 5,  1, 'completed', '18:30:00'),  -- 사직 롯데 vs KT
(6, '20260715OBLG0', '2026-07-15', 1, 2, 1,  1, 'cancelled', '18:30:00'),  -- 잠실 우천 취소
(7, '20260715KTLT0', '2026-07-15', 4, 5, 9,  1, 'postponed', '18:30:00'),  -- 수원 우천 연기
(8, '20260715HHSS0', '2026-07-15', 5, 6, 7,  1, 'completed', '18:30:00'),  -- 대전 한화 vs 삼성
(9, '20260715NCHT1', '2026-07-15', 9, 10, 8, 1, 'completed', '14:00:00'),  -- 창원 더블헤더 1차 (날씨 없음)
(10,'20260715NCHT2', '2026-07-15', 9, 10, 8, 2, 'completed', '18:30:00'),  -- 창원 더블헤더 2차 (날씨 없음)
(11, NULL,           '2026-07-16', 3, 4, 3,  1, 'scheduled', '18:30:00');  -- 인천 예정 경기

-- 완료 경기 1건 = stats 2행 (홈 1, 원정 1). 취소·연기·예정 경기는 stats 없음.
INSERT INTO team_game_stats (game_id, team_id, is_home, runs, hits, errors, walks, result) VALUES
(1, 1,  1, 5, 9,  0, 3, 'W'), (1, 2,  0, 3, 7,  1, 2, 'L'),
(2, 3,  1, 2, 6,  1, 4, 'L'), (2, 4,  0, 4, 10, 0, 1, 'W'),
(3, 7,  1, 8, 13, 0, 5, 'W'), (3, 6,  0, 7, 11, 2, 3, 'L'),
(4, 8,  1, 3, 8,  0, 2, 'D'), (4, 10, 0, 3, 7,  1, 4, 'D'),
(5, 9,  1, 1, 5,  2, 1, 'L'), (5, 5,  0, 6, 12, 0, 3, 'W'),
(8, 6,  1, 4, 9,  1, 2, 'W'), (8, 7,  0, 2, 6,  0, 5, 'L'),
(9, 10, 1, 5, 10, 0, 3, 'W'), (9, 8,  0, 4, 8,  1, 2, 'L'),
(10,10, 1, 0, 4,  1, 1, 'L'), (10,8,  0, 3, 7,  0, 4, 'W');
