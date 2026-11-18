-- ================================================================
-- TEAM04 | DB 테이블 삭제 스크립트
-- 작성 담당: ② DB 설계 (안예은)
-- 작성자: 안예은
-- 경고: 실행 시 team04의 6개 현재 테이블 및 이전 버전 player 테이블 및 그 안의 데이터가 삭제됩니다.
-- 최초 DB 생성 테스트/초기화 때만 사용하세요. 실데이터 적재 후 실행 금지!
-- ================================================================
USE `team04`;
-- 자식 테이블을 먼저 삭제해야 외래키 참조 오류가 나지 않습니다.
DROP TABLE IF EXISTS `team_game_stats`;
-- 이전 버전에서 player가 존재한다면 같이 정리 (최신 dbcreate에는 없음)
DROP TABLE IF EXISTS `player`;
DROP TABLE IF EXISTS `game`;
DROP TABLE IF EXISTS `weather`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `team`;
DROP TABLE IF EXISTS `stadium`;
