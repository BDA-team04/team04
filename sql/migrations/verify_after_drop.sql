-- 작성자: 안예은 (② DB 설계)
-- 변경 후 6개 테이블, 6개 PK, 6개 FK 및 player 부재 확인
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='team04' AND TABLE_TYPE='BASE TABLE') AS table_count,
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='team04' AND CONSTRAINT_TYPE='PRIMARY KEY') AS pk_count,
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='team04' AND CONSTRAINT_TYPE='FOREIGN KEY') AS fk_count,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='team04' AND TABLE_NAME='player') AS player_table_count;
-- 기대: 6 / 6 / 6 / 0

SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='team04' AND TABLE_NAME='game' AND COLUMN_NAME='game_status';
-- 기대: scheduled

SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='team04' AND TABLE_NAME='users' AND COLUMN_NAME='favorite_team_id';
-- 기대: favorite_team_id
