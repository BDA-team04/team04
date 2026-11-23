# 작성자: 조혜림
import requests
import json
import csv
import time
import os
from datetime import date, timedelta

# KBO 홈페이지 내부 데이터 요청 주소
SCHEDULE_URL = (
    "https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList"
)
SCORE_URL = (
    "https://www.koreabaseball.com/ws/Schedule.asmx/GetScoreBoardScroll"
)
HEADERS = {
    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
    "X-Requested-With": "XMLHttpRequest",
    "Referer": "https://www.koreabaseball.com/Schedule/GameCenter/Main.aspx"
}

session = requests.Session()
session.headers.update(HEADERS)

DELAY = 0.7

# 팀명 통일: SK 와이번스 + SSG 랜더스
TEAM_NAME_MAP = {
    "SK": "SSG"
}

def normalize_team_name(name):
    name = (name or "").strip()
    return TEAM_NAME_MAP.get(name, name)

# 날짜 형식 통일: YYYYMMDD -> YYYY-MM-DD
def format_game_date(raw_date):
    value = str(raw_date).replace("-", "").strip()

    if len(value) == 8 and value.isdigit():
        return f"{value[:4]}-{value[4:6]}-{value[6:8]}"

    return raw_date

# 더블헤더 구분
def get_game_no(game):
    try:
        header_no = int(game.get("HEADER_NO", 0))
    except (TypeError, ValueError):
        header_no = 0
    
    return 2 if header_no == 2 else 1

# 1. 날짜별 경기 목록 수집
def get_schedule(game_date):
    response = session.post(
        SCHEDULE_URL,
        data={
            "leId": 1,
            "srId": "0", # 정규시즌만
            "date": game_date
        },
        timeout=30
    )

    response.raise_for_status()
    return response.json().get("game", [])

# 2. 스코어보드 데이터 파싱
def parse_table(table_data):
    if not table_data:
        return []

    if isinstance(table_data, str):
        table_data = json.loads(table_data)

    result = []

    for row in table_data.get("rows", []):
        values = [
            str(cell.get("Text") or "").strip()
            for cell in row.get("row", [])
        ]
        result.append(values)

    return result

# 3. 경기별 상세 기록 수집
def get_scoreboard(game_id, year):
    response = session.post(
        SCORE_URL,
        data={
            "leId": 1,
            "srId": 0,
            "seasonId": year,
            "gameId": game_id
        },
        timeout=30
    )

    response.raise_for_status()

    data = response.json()

    # R, H, E, BB
    rows = parse_table(data.get("table3"))

    if len(rows) < 2:
        return None
    if len(rows[0]) < 4 or len(rows[1]) < 4:
        return None

    def to_int(value):
        if value is None:
            return None

        value = str(value).replace(",", "").strip()
        if not value or value in ("-", "&nbsp;"):
            return None
        
        return int(value)

    try:
        away = [to_int(v) for v in rows[0][:4]]
        home = [to_int(v) for v in rows[1][:4]]

        if None in away or None in home:
            return None

        return away, home

    except (ValueError, TypeError):
        return None

# 4. CSV 저장
def save_csv(filename, rows, columns):
    with open(filename, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=columns)
        writer.writeheader()
        writer.writerows(rows)

# 5. 2020~2025년 전체 수집
def collect_all(start_year=2020, end_year=2025):
    # 데이터 저장 위치
    project_dir = os.path.dirname(
        os.path.dirname(os.path.abspath(__file__))
    )

    output_dir = os.path.join(project_dir, "data", "raw", "kbo")

    os.makedirs(output_dir, exist_ok=True)

    all_games = []
    all_stats = []

    for year in range(start_year, end_year + 1):
        print(f"\n========== {year}년 데이터 수집 시작 ==========")

        year_games = []
        year_stats = []
        seen = set()

        start = date(year, 3, 1)
        end = date(year, 11, 30)

        current = start

        while current <= end:
            game_date = current.strftime("%Y%m%d")

            try:
                games = get_schedule(game_date)
                if games and not year_games:
                    print("원본 데이터 필드:", list(games[0].keys()))
                    print("HEADER_NO:", games[0].get("HEADER_NO"))
                    print("G_TM:", games[0].get("G_TM"))
                for game in games:
                    gid = game.get("G_ID")

                    ## 경기 ID 중복 확인
                    if not gid or gid in seen:
                        continue

                    seen.add(gid)

                    # 경기 상태 원본 정보
                    raw_cancel_id = game.get("CANCEL_SC_ID")
                    cancel_sc_id = (
                        str(raw_cancel_id).strip()
                        if raw_cancel_id is not None else "0"
                    )
                    cancel_sc_nm = game.get("CANCEL_SC_NM") or ""

                    # 경기 정보는 스코어보드가 없어도 저장
                    game_row = {
                        "kbo_game_id": gid,
                        "game_date": format_game_date(game.get("G_DT", game_date)),
                        "stadium_name": game.get("S_NM", ""),
                        "away_team": normalize_team_name(game.get("AWAY_NM", "")),
                        "home_team": normalize_team_name(game.get("HOME_NM", "")),
                        "game_no": get_game_no(game),
                        "game_status": "scheduled",
                        "start_time": game.get("G_TM") or None,
                        "cancel_sc_id": cancel_sc_id,
                        "cancel_sc_nm": cancel_sc_nm,
                        "scoreboard_status": "pending",
                        "schedule_raw_json": json.dumps(game, ensure_ascii=False)
                    }

                    year_games.append(game_row)

                    # 취소 경기는 일정만 저장하고 스코어보드는 요청하지 않음
                    if cancel_sc_id not in ("", "0"):
                        game_row["scoreboard_status"] = "cancelled"
                        game_row["game_status"] = "cancelled"
                        print("취소 경기:", gid, cancel_sc_nm)
                        continue

                    try:
                        # 경기별 상세 스코어보드
                        scoreboard = get_scoreboard(gid, year)

                        if scoreboard is None:
                            game_row["scoreboard_status"] = (
                                "unavailable_or_unparsed"
                            )
                            print("스코어보드 없음:", gid)
                            continue

                        away, home = scoreboard
                        game_row["scoreboard_status"] = "collected"
                        game_row["game_status"] = "completed"

                        # 첫 경기 파싱 결과 확인
                        if not year_stats:
                            print("\n[첫 경기 데이터 확인]")
                            print("경기 ID:", gid)
                            print("원정팀 R/H/E/BB:", away)
                            print("홈팀 R/H/E/BB:", home)

                        # 경기 결과 계산
                        if away[0] > home[0]:
                            away_result = "W"
                            home_result = "L"
                        elif away[0] < home[0]:
                            away_result = "L"
                            home_result = "W"
                        else:
                            away_result = "D"
                            home_result = "D"

                        # 원정팀 기록
                        year_stats.append({
                            "kbo_game_id": gid,
                            "team_name": normalize_team_name(game.get("AWAY_NM", "")),
                            "is_home": 0,
                            "runs": away[0],
                            "hits": away[1],
                            "errors": away[2],
                            "walks": away[3],
                            "result": away_result
                        })

                        # 홈팀 기록
                        year_stats.append({
                            "kbo_game_id": gid,
                            "team_name": normalize_team_name(game.get("HOME_NM", "")),
                            "is_home": 1,
                            "runs": home[0],
                            "hits": home[1],
                            "errors": home[2],
                            "walks": home[3],
                            "result": home_result
                        })

                        print("수집 완료:", gid)

                    except (
                        requests.RequestException,
                        ValueError,
                        TypeError,
                        KeyError,
                        AttributeError
                     ) as e:
                        game_row["scoreboard_status"] = "request_error"
                        print("경기 데이터 처리 오류:", gid, e)

                    finally:
                        time.sleep(DELAY)

            except (requests.RequestException, ValueError) as e:
                print("일정 요청 오류:", game_date, e)

            current += timedelta(days=1)
            time.sleep(DELAY)

        # 연도별 CSV 저장
        save_csv(
            os.path.join(output_dir, f"games_{year}.csv"),
            year_games,
            [
                "kbo_game_id",
                "game_date",
                "stadium_name",
                "away_team",
                "home_team",
                "game_no",
                "game_status",
                "start_time",
                "cancel_sc_id",
                "cancel_sc_nm",
                "scoreboard_status",
                "schedule_raw_json"
            ]
        )

        save_csv(
            os.path.join(output_dir, f"team_stats_{year}.csv"),
            year_stats,
            [
                "kbo_game_id",
                "team_name",
                "is_home",
                "runs",
                "hits",
                "errors",
                "walks",
                "result"
            ]
        )

        all_games.extend(year_games)
        all_stats.extend(year_stats)

        cancelled_count = sum(
            g["scoreboard_status"] == "cancelled"
            for g in year_games
        )

        missing_count = sum(
            g["scoreboard_status"] in (
                "unavailable_or_unparsed",
                "request_error"
            )
            for g in year_games
        )

        print(f"\n{year}년 수집 완료")
        print("전체 경기 일정:", len(year_games))
        print("스코어보드 수집 경기:", len(year_stats) // 2)
        print("취소 경기:", cancelled_count)
        print("스코어보드 오류/미수집:", missing_count)

    # 전체 연도 통합 CSV
    save_csv(
        os.path.join(output_dir, "historical_games.csv"),
        all_games,
        [
            "kbo_game_id",
            "game_date",
            "stadium_name",
            "away_team",
            "home_team",
            "game_no",
            "game_status",
            "start_time",
            "cancel_sc_id",
            "cancel_sc_nm",
            "scoreboard_status",
            "schedule_raw_json"
        ]
    )

    save_csv(
        os.path.join(output_dir, "historical_team_stats.csv"),
        all_stats,
        [
            "kbo_game_id",
            "team_name",
            "is_home",
            "runs",
            "hits",
            "errors",
            "walks",
            "result"
        ]
    )

    total_cancelled = sum(
        g["scoreboard_status"] == "cancelled"
        for g in all_games
    )
    total_missing = sum(
        g["scoreboard_status"] in (
            "unavailable_or_unparsed",
            "request_error"
        )
        for g in all_games
    )

    print("\n========== 전체 수집 완료 ==========")
    print("전체 경기 일정:", len(all_games))
    print("스코어보드 수집 경기:", len(all_stats) // 2)
    print("취소 경기:", total_cancelled)
    print("스코어보드 오류/미수집:", total_missing)

if __name__ == "__main__":
    collect_all(2020, 2025)