"""TEAM04: convert the team's prepared CSVs into sql/dbinsert.sql.
Author: 안예은 (DB & Aggregating)
Run from anywhere: py sql/build_dbinsert.py, within team04 project.
No third-party packages required.
"""
from __future__ import annotations

import csv
from collections import Counter
from decimal import Decimal, InvalidOperation
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'sql' / 'dbinsert.sql'
BATCH_SIZE = 500

# Table -> CSV path and its SQL columns. Auto-increment columns absent from CSV are not listed.
DATASETS = [
    (
        'stadium', 'data/processed/kbo/stadium.csv',
        ['stadium_id', 'stadium_name', 'city', 'asos_station_id', 'is_dome'],
    ),
    (
        'team', 'data/processed/kbo/team.csv',
        ['team_id', 'team_name', 'short_name'],
    ),
    (
        'weather', 'data/processed/weather/FINAL_261121_weather_cleaned_team04.csv',
        ['city', 'obs_date', 'asos_station_id', 'avg_temp_c', 'max_temp_c',
         'precipitation_mm', 'avg_wind_ms', 'avg_humidity_pct'],
    ),
    (
        'game', 'data/processed/kbo/game.csv',
        ['game_id', 'kbo_game_id', 'game_date', 'stadium_id', 'home_team_id',
         'away_team_id', 'game_no', 'game_status', 'start_time'],
    ),
    (
        'team_game_stats', 'data/processed/kbo/team_game_stats.csv',
        ['game_id', 'team_id', 'is_home', 'runs', 'hits', 'errors', 'walks', 'result'],
    ),
]

NUMERIC = {
    'stadium_id', 'asos_station_id_NOT_NUMERIC', 'is_dome', 'team_id',
    'avg_temp_c', 'max_temp_c', 'precipitation_mm', 'avg_wind_ms',
    'avg_humidity_pct', 'game_id', 'home_team_id', 'away_team_id',
    'game_no', 'is_home', 'runs', 'hits', 'errors', 'walks',
}


def sql_value(col: str, value: str | None) -> str:
    """Safely escape CSV values. All empty cells become SQL NULL."""
    if value is None or value.strip() == '':
        return 'NULL'
    value = value.strip()
    if col in NUMERIC:
        try:
            number = Decimal(value)
            if not number.is_finite():
                raise InvalidOperation('not finite')
            if col in {'stadium_id', 'is_dome', 'team_id', 'game_id',
                       'home_team_id', 'away_team_id', 'game_no',
                       'is_home', 'runs', 'hits', 'errors', 'walks'} and number != int(number):
                raise ValueError('not an integer')
            return format(number, 'f')
        except (InvalidOperation, ValueError) as exc:
            raise ValueError(f'Numeric field {col} has invalid value {value!r}') from exc
    # SQL standard '' escape; backslash escaped for MariaDB default SQL mode.
    return "'" + value.replace('\\', '\\\\').replace("'", "''") + "'"


def main() -> None:
    missing = [str(ROOT / rel) for _, rel, _ in DATASETS if not (ROOT / rel).is_file()]
    if missing:
        print('CSV files are missing. Download the latest team repository (main) first:')
        for path in missing:
            print('  -', path)
        raise SystemExit(1)

    counts = Counter()
    OUT.parent.mkdir(exist_ok=True, parents=True)
    with OUT.open('w', encoding='utf-8', newline='\n') as out:
        out.write('-- TEAM04 generated INSERT statements (author: 안예은)\n')
        out.write('-- Auto-generated from committed team CSVs; do not hand-edit rows.\n')
        out.write('-- Run ONLY on fresh/empty tables; rerunning will cause duplicate keys.\n')
        out.write('USE `team04`;\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n\n')

        for table, rel, columns in DATASETS:
            path = ROOT / rel
            with path.open('r', encoding='utf-8-sig', newline='') as handle:
                reader = csv.DictReader(handle)
                actual = reader.fieldnames or []
                missing_columns = [c for c in columns if c not in actual]
                if missing_columns:
                    raise ValueError(f'{rel}: missing columns {missing_columns}; CSV header: {actual}')
                batch: list[str] = []

                def write_batch() -> None:
                    if not batch:
                        return
                    col_sql = ', '.join(f'`{name}`' for name in columns)
                    out.write(f'INSERT INTO `{table}` ({col_sql}) VALUES\n')
                    out.write(',\n'.join(batch))
                    out.write(';\n\n')
                    batch.clear()

                out.write(f'-- {rel}\n')
                for row in reader:
                    values = [sql_value(col, row[col]) for col in columns]
                    batch.append('  (' + ', '.join(values) + ')')
                    counts[table] += 1
                    if len(batch) == BATCH_SIZE:
                        write_batch()
                write_batch()
                if not counts[table]:
                    raise ValueError(f'{rel} has no data rows')
        out.write('COMMIT;\n')
        out.write('-- Validation (run after import):\n')
        for table, _, _ in DATASETS:
            out.write(f'-- SELECT COUNT(*) FROM `{table}`;  -- expected {counts[table]}\n')

    print(f'Created: {OUT}')
    for table, _, _ in DATASETS:
        print(f'  {table}: {counts[table]:,} rows')
    print(f'TOTAL: {sum(counts.values()):,} rows')
    print('Important: run on EMPTY tables, then check counts and JOIN results.')


if __name__ == '__main__':
    main()
