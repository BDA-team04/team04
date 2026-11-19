# TEAM04 — DB 컬럼 정의서 (초안, 팀원과 확정 필요)

담당: ② DB 설계 (안예은)  |  수정: 2026-11-18

## 공통 규칙
- DB 이름: `team04` / 테이블 수: **6개**.
- 모든 날짜: `YYYY-MM-DD` (예: `2026-07-01`). 시간: `HH:MM:SS`.
- 각 팀원은 PK/FK ID를 임의로 새로 생성하지 말고, 기준 테이블의 ID와 맞춰 적재할 것.
- **경기당 `game` 한 행**. 완료된 경기 한 건에 **`team_game_stats` 두 행(홈 1, 원정 1)**.
- **날씨는 대표 ASOS 관측소 1곳 / 도시·날짜마다 1행**. 구장이 도시별 관측소에 매핑되는지 ④ 담당과 확인.
- `weather`와 `game`은 `game.game_date = weather.obs_date` 및 `stadium.city = weather.city`로 **논리적으로 JOIN**. DB FK는 설정하지 않음(날씨 관측값의 지연/누락 가능).
- 취소 경기에는 `team_game_stats`를 만들지 않음. 분석 시 `game_status = 'completed'` 조건 적용.
- `is_dome=1`은 돔구장. 돔/야외 비교 시 실제 기상 노출과 차이를 해석해야 함.
- `users.favorite_team_id`는 응원팀 미선택 시 NULL 허용, 선택 시 `team.team_id`를 참조. 팀 삭제 시 자동으로 NULL 처리.
- `game.game_status`의 기본값은 `scheduled`; 완료된 경기 적재 시 명시적으로 `completed` 지정. 분석에는 `completed`만 포함.
- 비밀번호 원문 저장 금지: `users.password_hash`에는 PHP `password_hash()` 출력값만 저장.
- 실제 수집한 원본 CSV는 본인이 보관, 이 SQL 파일은 DB 구조용임.

## 테이블 6개

### `stadium` — 구장
`stadium_id` (PK), `stadium_name` (구장명), `city` (구장 도시), `asos_station_id` (대표 관측소 코드, 선택), `is_dome` (0=야외/1=돔).

### `team` — KBO 팀
`team_id` (PK), `team_name` (팀 이름), `short_name` (축약명, 선택).

### `weather` — 기상청 ASOS 일별 관측값
`weather_id` (PK), `city` (구장 도시), `obs_date` (관측 날짜), `asos_station_id`, `avg_temp_c` (평균기온 °C), `max_temp_c` (최고기온 °C), `precipitation_mm` (일강수량 mm), `avg_wind_ms` (평균풍속 m/s), `avg_humidity_pct` (평균습도 %).

### `game` — 경기
`game_id` (PK), `kbo_game_id` (원본 경기ID, 선택), `game_date` (날짜), `stadium_id` (FK), `home_team_id` (FK), `away_team_id` (FK), `game_no` (더블헤더 번호 기본값 1), `game_status` (`scheduled/completed/cancelled/postponed`, **기본값 scheduled**), `start_time` (선택).

### `team_game_stats` — 팀별 경기 기록
`stats_id` (PK), `game_id` (FK), `team_id` (FK), `is_home` (홈 1/원정 0), `runs` (득점), `hits` (안타), `errors` (실책, 없으면 NULL), `walks` (볼넷, 없으면 NULL), `result` (W/L/D, 없으면 NULL).

### `users` — 로그인 사용자
`user_id` (PK), `username` (로그인명, 유일), `password_hash` (비밀번호 해시), `created_at` (가입일), `favorite_team_id` (선택, `team.team_id`를 참조하는 FK, 로그인 시 세션의 응원팀 기본값에 사용).

## 팀원과 꼭 확인할 문제 (DB 확정 전)
1. ③ 경기 담당: 더블헤더 구분 가능한 원본 경기 ID가 있는지, 팀 기록 `hits/errors/walks`까지 확보되는지.
2. ④ 날씨 담당: 도시별 대표 ASOS 관측소 선정과 결측치 처리 기준. 특히 도시에 여러 관측소가 있는 경우 한 관측소만 대표로 선택.
3. ⑤ UI 담당: 분석④는 team, game, team_game_stats, stadium, weather 테이블만 사용하도록 확인. `player` 테이블은 사용하지 않음.
4. ① 백엔드 담당: 회원가입 시 `favorite_team_id` 입력/저장 및 로그인 시 `$_SESSION` 저장 구현. CRUD에 필요한 필드 추가 여부 확인.
5. 전체: 학교 과제에 추가로 요구된 테이블/쿼리 수/트랜잭션 방식이 있는지 교수 지침 확인.

## 팀원별 필요한 테이블
- ① 백엔드: `users`, `game`, `team_game_stats`; DB 연결에 전체 사용.
- ③ 경기 데이터: `team`, `stadium`, `game`, `team_game_stats`.
- ④ 날씨 데이터: `stadium`, `weather`; `game`과 JOIN.
- ⑤ UI·윈도잉: `team`, `game`, `team_game_stats`, `stadium`, `weather`.
- ② DB/분석①: `game`, `stadium`, `weather`, `team_game_stats`.

## 2026-11-18 변경 이력
- 팀 합의에 따라 `player` 테이블을 제거함. 현재 DB는 6개 테이블로 구성.
- 기존 DB에 `player`가 있다면 `dbupdate_remove_player.sql`을 **데이터 백업 및 사용 여부 확인 후** 1회 실행.
- `users.favorite_team_id` FK와 `game.game_status` 기본값 `scheduled`는 유지.
- `dbdrop.sql`의 `DROP TABLE IF EXISTS player`는 오래된 DB 정리를 위한 호환 명령임 (최신 dbcreate는 player 생성 안 함).
