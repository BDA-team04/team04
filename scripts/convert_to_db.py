# 작성자: 조혜림
import csv
import glob
import os
import json
from collections import Counter, defaultdict

# 1. 경로
PROJECT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW_DIR = os.path.join(PROJECT_DIR, "data", "raw", "kbo")
PROCESSED_DIR = os.path.join(PROJECT_DIR, "data", "processed", "kbo")

# 2. 팀명 및 ID
TEAM_ORDER = ["두산", "한화", "삼성", "롯데", "KIA", "SSG", "LG", "키움", "KT", "NC"]
TEAM_ID = {name: idx for idx, name in enumerate(TEAM_ORDER, start=1)}

TEAM_ALIASES = {
    "SK": "SSG"
}

def normalize_team_name(name):
    name = (name or "").strip()
    return TEAM_ALIASES.get(name, name)

# 3. 구장명 매핑
STADIUM_ALIASES = {
    "고척": "고척스카이돔",
    "광주": "광주기아챔피언스필드",
    "대구": "대구삼성라이온즈파크",
    "대전": "대전한화생명볼파크",
    "문학": "SSG랜더스필드",
    "사직": "사직야구장",
    "수원": "수원KT위즈파크",
    "울산": "울산문수야구장",
    "잠실": "잠실야구장",
    "창원": "창원NC파크",
    "청주": "청주야구장",
    "포항": "포항야구장",
    "한밭": "한화생명이글스파크"
}

# city, ASOS 관측소 ID, 돔구장 여부
STADIUM_INFO = {
    "고척스카이돔": ("서울", "108", 1),
    "잠실야구장": ("서울", "108", 0),
    "SSG랜더스필드": ("인천", "112", 0),
    "수원KT위즈파크": ("수원", "119", 0),
    "한화생명이글스파크": ("대전", "133", 0),
    "대전한화생명볼파크": ("대전", "133", 0),
    "광주기아챔피언스필드": ("광주", "156", 0),
    "대구삼성라이온즈파크": ("대구", "143", 0),
    "사직야구장": ("부산", "159", 0),
    "창원NC파크": ("창원", "155", 0),
    "울산문수야구장": ("울산", "152", 0),
    "포항야구장": ("포항", "138", 0),
    "청주야구장": ("청주", "131", 0)
}

def normalize_stadium_name(name):
    raw_name = (name or "").strip()
    if raw_name not in STADIUM_ALIASES:
        raise ValueError(f"등록되지 않은 구장명: {raw_name}")

    stadium_name = STADIUM_ALIASES[raw_name]
    if stadium_name not in STADIUM_INFO:
        raise ValueError(f"구장 정보가 없습니다: {stadium_name}")

    return stadium_name

# 4. csv 입출력
def read_csv(path):
    with open(path, "r", newline="", encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))

def write_csv(filename, columns, rows):
    os.makedirs(PROCESSED_DIR, exist_ok=True)
    path = os.path.join(PROCESSED_DIR, filename)

    with open(path, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=columns)
        writer.writeheader()
        writer.writerows(rows)

def to_int(value, field_name, allow_blank=False):
    value = "" if value is None else str(value).strip()
    if value == "" and allow_blank:
        return ""
    if value == "":
        raise ValueError(f"필수 숫자 필드가 비어 있습니다: {field_name}")
    
    return int(float(value))

def normalize_date(value):
    value = (value or "").strip().replace("-", "")
    if len(value) != 8 or not value.isdigit():
        raise ValueError(f"날짜 형식이 올바르지 않습니다: {value}")
    return f"{value[:4]}-{value[4:6]}-{value[6:8]}"

def normalize_game_status(row):
    raw_status = (row.get("game_status") or "").strip().lower()
    scoreboard_status = (row.get("scoreboard_status") or "").strip().lower()
    cancel_text = (row.get("cancel_sc_nm") or "").strip()
    cancel_id = (row.get("cancel_sc_id") or "0").strip()

    if scoreboard_status == "collected" or raw_status == "completed":
        return "completed"

    if scoreboard_status == "cancelled" or raw_status == "cancelled" or cancel_id not in ("", "0", "None"):
        if "순연" in cancel_text or "연기" in cancel_text:
            return "postponed"

        return "cancelled"

    if raw_status in ("scheduled", "postponed"):
        return raw_status

    return "scheduled"

# 5. raw 연도별 csv 읽기
def load_raw_data():
    game_files = sorted(glob.glob(os.path.join(RAW_DIR, "games_202[0-5].csv")))
    if not game_files:
        raise FileNotFoundError(f"raw 폴더에 games_YYYY.csv 파일이 없습니다: {RAW_DIR}")

    all_games = []
    all_stats = []

    for game_file in game_files:
        year = os.path.basename(game_file)[6:10]
        stats_file = os.path.join(RAW_DIR, f"team_stats_{year}.csv")
        if not os.path.exists(stats_file):
            raise FileNotFoundError(f"팀 기록 파일이 없습니다: {stats_file}")

        games = read_csv(game_file)
        stats = read_csv(stats_file)
        print(f"{year}년: 경기 {len(games):,}행 / 팀 기록 {len(stats):,}행")

        all_games.extend(games)
        all_stats.extend(stats)

    return all_games, all_stats

# 6. 변환
def convert():
    raw_games, raw_stats = load_raw_data()

    # 경기 ID 중복 검사
    game_ids = [str(row.get("kbo_game_id", "")).strip() for row in raw_games]
    duplicates = [gid for gid, count in Counter(game_ids).items() if gid and count > 1]
    if duplicates:
        raise ValueError(f"중복된 경기 ID가 있습니다: {duplicates[:10]}")

    # 수집 데이터에 등장한 팀 확인
    found_teams = set()
    for row in raw_games:
        found_teams.add(normalize_team_name(row.get("home_team")))
        found_teams.add(normalize_team_name(row.get("away_team")))
    for row in raw_stats:
        found_teams.add(normalize_team_name(row.get("team_name")))

    unknown_teams = sorted(found_teams - set(TEAM_ID))
    if unknown_teams:
        raise ValueError(f"TEAM_ORDER에 없는 팀명입니다: {unknown_teams}")

    
    # 구장 ID 생성
    stadium_names = sorted({normalize_stadium_name(row.get("stadium_name")) for row in raw_games})
    stadium_id = {name: idx for idx, name in enumerate(stadium_names, start=1)}

    stadium_rows = []
    for name in stadium_names:
        city, asos_id, is_dome = STADIUM_INFO[name]
        stadium_rows.append({
            "stadium_id": stadium_id[name],
            "stadium_name": name,
            "city": city,
            "asos_station_id": asos_id,
            "is_dome": is_dome
        })

    # team 테이블
    team_rows = [
        {"team_id": TEAM_ID[name], "team_name": name, "short_name": name}
        for name in TEAM_ORDER if name in found_teams
    ]

    # game 테이블
    game_rows = []
    for row in raw_games:
        gid = str(row.get("kbo_game_id", "")).strip()
        if not gid:
            raise ValueError("kbo_game_id가 비어 있는 경기 행이 있습니다.")

        stadium_name = normalize_stadium_name(row.get("stadium_name"))
        home_name = normalize_team_name(row.get("home_team"))
        away_name = normalize_team_name(row.get("away_team"))

        schedule_data = json.loads(
            row.get("schedule_raw_json") or "{}"
        )

        # 경기 시작 시간 HH:MM:SS로 변환
        start_time = str(schedule_data.get("G_TM") or "").strip()
        if start_time and len(start_time) == 5:
            start_time += ":00"

        game_rows.append({
            "kbo_game_id": gid,
            "game_date": normalize_date(row.get("game_date")),
            "stadium_id": stadium_id[stadium_name],
             "home_team_id": TEAM_ID[home_name],
             "away_team_id": TEAM_ID[away_name],
             "game_no": 1,
             "game_status": normalize_game_status(row),
             "start_time": start_time
        })

    # 더블헤더 경기 번호 자동 지정
    game_groups = defaultdict(list)

    # 날짜, 구장, 홈팀, 원정팀 기준으로 그룹화
    for row in game_rows:
        key = (
            row["game_date"],
            row["stadium_id"],
            row["home_team_id"],
            row["away_team_id"]
        )
        game_groups[key].append(row)

    # 경기 시작 시간 순서로 번호 지정
    for key, rows in game_groups.items():

        if len(rows) == 1:
            rows[0]["game_no"] = 1

        elif len(rows) == 2:
            rows.sort(key=lambda x: x["start_time"] or "")

            if not all(x["start_time"] for x in rows):
                raise ValueError(f"경기 시간 누락: {key}")

            if rows[0]["start_time"] == rows[1]["start_time"]:
                raise ValueError(f"더블헤더 시간 중복: {key}")

            rows[0]["game_no"] = 1
            rows[1]["game_no"] = 2

        else:
            raise ValueError(f"동일 조건 경기 3개 이상 발견: {key}")

    print("\n더블헤더 번호 지정 완료")
    print(
        "더블헤더 그룹:",
        sum(len(rows) == 2 for rows in game_groups.values())
    )

    # DB UNIQUE 제약조건 중복 검사 및 원본 확인
    identity_groups = {}

    for row in game_rows:
        key = (
            row["game_date"],
            row["stadium_id"],
            row["home_team_id"],
            row["away_team_id"],
            row["game_no"]
        )
        identity_groups.setdefault(key, []).append(row)

    duplicates = {
        key: rows
        for key, rows in identity_groups.items()
        if len(rows) > 1
    }

    # 원본 경기 데이터 검색용
    raw_by_id = {
        str(row["kbo_game_id"]).strip(): row
        for row in raw_games
    }

    print("\n========== 중복 경기 원본 확인 ==========")

    for key, rows in duplicates.items():
        print("\n중복 그룹:", key)

        for game in rows:
            gid = game["kbo_game_id"]
            raw = raw_by_id[gid]

            schedule = json.loads(
                raw.get("schedule_raw_json") or "{}"
            )

            print({
                "G_ID": gid,
                "변환된 game_no": game["game_no"],
                "HEADER_NO": schedule.get("HEADER_NO"),
                "GAME_NO": schedule.get("GAME_NO"),
                "G_TM": schedule.get("G_TM"),
                "GAME_STATE_SC": schedule.get("GAME_STATE_SC"),
                "CANCEL_SC_ID": schedule.get("CANCEL_SC_ID"),
                "CANCEL_SC_NM": schedule.get("CANCEL_SC_NM"),
                "scoreboard_status": raw.get("scoreboard_status")
            })

    print("\n중복 그룹 수:", len(duplicates))

    if duplicates:
        raise ValueError(
            f"DB 경기 고유키 중복 발견: {list(duplicates.keys())[:10]}"
        )

    
    # 경기 날짜와 구장 기준으로 정렬 후 game_id 부여
    game_rows.sort(key=lambda row: (
        row["game_date"], row["stadium_id"], row["game_no"], row["kbo_game_id"]
    ))
    for game_id, row in enumerate(game_rows, start=1):
        row["game_id"] = game_id

    game_by_kbo_id = {row["kbo_game_id"]: row for row in game_rows}

    # 경기별 홈/원정 득점을 이용해 승/패/무 결과 계산
    runs_by_game = {}
    for row in raw_stats:
        gid = str(row.get("kbo_game_id", "")).strip()
        is_home = to_int(row.get("is_home"), "is_home")
        runs = to_int(row.get("runs"), "runs")
        runs_by_game.setdefault(gid, {})[is_home] = runs

    # team_game_stats 테이블
    stats_rows = []
    seen_game_team = set()

    for row in raw_stats:
        gid = str(row.get("kbo_game_id", "")).strip()
        if gid not in game_by_kbo_id:
            raise ValueError(f"game에 없는 경기 ID입니다: {gid}")

        game = game_by_kbo_id[gid]
        team_name = normalize_team_name(row.get("team_name"))
        team_id = TEAM_ID[team_name]
        is_home = to_int(row.get("is_home"), "is_home")

        if is_home not in (0, 1):
            raise ValueError(f"is_home 값은 0 또는 1이어야 합니다: {gid}")

        expected_team_id = game["home_team_id"] if is_home else game["away_team_id"]
        if team_id != expected_team_id:
            raise ValueError(f"홈/원정 팀이 일치하지 않습니다: {gid}, {team_name}")

        key = (game["game_id"], team_id)
        if key in seen_game_team:
            raise ValueError(f"같은 경기의 팀 기록이 중복됩니다: {gid}, {team_name}")
        seen_game_team.add(key)

        game_runs = runs_by_game.get(gid, {})
        if set(game_runs) != {0, 1}:
            raise ValueError(f"홈/원정 득점 기록이 모두 존재하지 않습니다: {gid}")

        home_runs = game_runs[1]
        away_runs = game_runs[0]

        if home_runs == away_runs:
            result = "D"
        elif (is_home == 1 and home_runs > away_runs) or (is_home == 0 and away_runs > home_runs):
            result = "W"
        else:
            result = "L"

        stats_rows.append({
            "game_id": game["game_id"],
            "team_id": team_id,
            "is_home": is_home,
            "runs": to_int(row.get("runs"), "runs"),
            "hits": to_int(row.get("hits"), "hits"),
            "errors": to_int(row.get("errors"), "errors", allow_blank=True),
            "walks": to_int(row.get("walks"), "walks", allow_blank=True),
            "result": result
        })

    # 경기별 팀 기록 개수 검사
    stats_count = Counter(row["game_id"] for row in stats_rows)
    for game in game_rows:
        count = stats_count.get(game["game_id"], 0)
        if game["game_status"] == "completed" and count != 2:
            raise ValueError(f"완료 경기의 팀 기록이 2행이 아닙니다: {game['kbo_game_id']} ({count}행)")
        if game["game_status"] in ("cancelled", "postponed") and count != 0:
            raise ValueError(f"취소/순연 경기의 팀 기록이 존재합니다: {game['kbo_game_id']}")

    stats_rows.sort(key=lambda row: (row["game_id"], row["is_home"]))

    # DB 스키마별 CSV 저장
    write_csv("stadium.csv",
                ["stadium_id", "stadium_name", "city", "asos_station_id", "is_dome"],
                stadium_rows)
    write_csv("team.csv",
                ["team_id", "team_name", "short_name"],
                team_rows)
    write_csv("game.csv",
                ["game_id", "kbo_game_id", "game_date", "stadium_id", "home_team_id",
                "away_team_id", "game_no", "game_status", "start_time"],
                game_rows)
    write_csv("team_game_stats.csv",
                ["game_id", "team_id", "is_home", "runs", "hits", "errors", "walks", "result"],
                stats_rows)

    print("\n========== DB용 CSV 변환 완료 ==========")
    print(f"구장: {len(stadium_rows):,}행")
    print(f"팀: {len(team_rows):,}행")
    print(f"경기: {len(game_rows):,}행")
    print(f"팀별 경기 기록: {len(stats_rows):,}행")
    print(f"저장 위치: {PROCESSED_DIR}")

if __name__ == "__main__":
    convert()