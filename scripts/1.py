import csv
import json
import glob
import os
from collections import defaultdict

game_file = "data/processed/kbo/game.csv"

groups = defaultdict(list)

with open(game_file, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        key = (
            row["game_date"],
            row["stadium_id"],
            row["home_team_id"],
            row["away_team_id"]
        )
        groups[key].append(row)

target_ids = set()

for key, rows in groups.items():
    if len(rows) > 1:
        for row in rows:
            target_ids.add(row["kbo_game_id"])

print("중복 경기 수:", len(target_ids))

for file in glob.glob("data/raw/games_*.csv"):
    with open(file, encoding="utf-8-sig") as f:
        for row in csv.DictReader(f):
            gid = row["kbo_game_id"]

            if gid not in target_ids:
                continue

            raw = json.loads(row["schedule_raw_json"])

            print(
                gid,
                "시간:", raw.get("G_TM"),
                "GAME_NO:", raw.get("GAME_NO"),
                "HEADER_NO:", raw.get("HEADER_NO"),
                "취소:", raw.get("CANCEL_SC_NM")
            )