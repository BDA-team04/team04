-- TEAM04 DB 변경 스크립트 (2026-11-16)
-- 작성 담당: ② DB 설계 (안예은)
-- 대상: 이미 7개 테이블이 존재하는 team04 데이터베이스
-- 주의: 이 파일은 최초 1회만 실행하세요. 이미 컬럼 추가했다면 재실행하지 마세요.
-- 데이터/테이블 삭제 없음. 실행 전 기존 team04.sql 백업 보관 권장.

USE `team04`;

-- (1) 응원팀 컬럼과 팀 FK 추가: 응원팀 미선택 시 NULL
ALTER TABLE `users`
  ADD COLUMN `favorite_team_id` SMALLINT UNSIGNED DEFAULT NULL,
  ADD KEY `idx_users_favorite_team` (`favorite_team_id`),
  ADD CONSTRAINT `fk_users_favorite_team`
    FOREIGN KEY (`favorite_team_id`) REFERENCES `team` (`team_id`)
    ON DELETE SET NULL ON UPDATE RESTRICT;

-- (2) 새 경기의 기본 상태만 scheduled로 변경: 기존 행의 값은 유지
ALTER TABLE `game`
  MODIFY COLUMN `game_status`
    ENUM('scheduled','completed','cancelled','postponed')
    NOT NULL DEFAULT 'scheduled';
