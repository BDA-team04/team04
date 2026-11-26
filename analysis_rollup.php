<?php
// 작성자: 용연주
require_once 'session.php';
require_once 'db.php';

// 초기화
if (isset($_GET['reset'])) {
    clear_filter('analysis_rollup');
    header('Location: analysis_rollup.php');
    exit;
}

// 기본 분석 조건
$default_filter = [
    'year' => '',
    'team_id' => '',
    'min_temp' => '',
    'max_temp' => '',
    'rain' => '',
    'stadium_type' => ''
];

// 이전에 저장한 조건
$filter = load_filter('analysis_rollup', $default_filter);

// 검색 버튼을 눌렀을 때 새로운 조건 저장
$filter_keys = array_keys($default_filter);

if (count(array_intersect($filter_keys, array_keys($_GET))) > 0) {
    $filter = array_merge($default_filter, $_GET);
}

// 연도 검증
$selected_year = (string)$filter['year'];
$allowed_years = ['', '2020', '2021', '2022', '2023', '2024', '2025', '2026'];

if (!in_array($selected_year, $allowed_years, true)) {
    $selected_year = '';
}

// 팀 ID 검증
$team_id = $filter['team_id'];

if ($team_id !== '' && ctype_digit((string)$team_id)) {
    $team_id = (int)$team_id;
} else {
    $team_id = '';
}

// 기온 검증
$min_temp = is_numeric($filter['min_temp'])
    ? (float)$filter['min_temp'] : '';

$max_temp = is_numeric($filter['max_temp'])
    ? (float)$filter['max_temp'] : '';

// 강수 여부 검증
$rain_condition = in_array($filter['rain'], ['', 'dry', 'rain'], true)
    ? $filter['rain'] : '';

// 경기장 유형 검증
$stadium_type = in_array($filter['stadium_type'], ['', 'dome', 'outdoor'], true)
    ? $filter['stadium_type'] : '';

// 검증한 조건 세션에 저장
save_filter('analysis_rollup', [
    'year' => $selected_year,
    'team_id' => $team_id,
    'min_temp' => $min_temp,
    'max_temp' => $max_temp,
    'rain' => $rain_condition,
    'stadium_type' => $stadium_type
]);

$year_param = ($selected_year === '') ? 0 : (int)$selected_year;

// 팀 목록
$teams = db_query("
    SELECT team_id, team_name
    FROM team
    ORDER BY team_name
");

// SQL 조건
$join_condition = "
    AND (? = 0 OR YEAR(g.game_date) = ?)
";

$params = [$year_param, $year_param];
$types = 'ii';

// 팀 필터
if ($team_id !== '') {
    $join_condition .= "
        AND (
            g.home_team_id = ?
            OR g.away_team_id = ?
        )
    ";

    $params[] = $team_id;
    $params[] = $team_id;
    $types .= 'ii';
}

// 최저 기온 필터
if ($min_temp !== '') {
    $join_condition .= "
        AND EXISTS (
            SELECT 1
            FROM weather w
            WHERE w.city = s.city
              AND w.obs_date = g.game_date
              AND w.avg_temp_c >= ?
        )
    ";

    $params[] = $min_temp;
    $types .= 'd';
}

// 최고 기온 필터
if ($max_temp !== '') {
    $join_condition .= "
        AND EXISTS (
            SELECT 1
            FROM weather w
            WHERE w.city = s.city
              AND w.obs_date = g.game_date
              AND w.avg_temp_c <= ?
        )
    ";

    $params[] = $max_temp;
    $types .= 'd';
}

// 강수 여부 필터
if ($rain_condition === 'dry') {
    $join_condition .= "
        AND EXISTS (
            SELECT 1
            FROM weather w
            WHERE w.city = s.city
              AND w.obs_date = g.game_date
              AND w.precipitation_mm = 0
        )
    ";
} elseif ($rain_condition === 'rain') {
    $join_condition .= "
        AND EXISTS (
            SELECT 1
            FROM weather w
            WHERE w.city = s.city
              AND w.obs_date = g.game_date
              AND w.precipitation_mm > 0
        )
    ";
}

// 경기장 유형 필터
if ($stadium_type === 'dome') {
    $join_condition .= " AND s.is_dome = 1";
} elseif ($stadium_type === 'outdoor') {
    $join_condition .= " AND s.is_dome = 0";
}

// 지역별 경기 현황 조회
$sql = "
    SELECT
        s.city,

        COUNT(DISTINCT CASE
            WHEN g.game_status = 'completed'
            THEN g.game_id
        END) AS completed_count,

        COUNT(DISTINCT CASE
            WHEN g.game_status = 'cancelled'
            THEN g.game_id
        END) AS cancelled_count,

        COUNT(DISTINCT CASE
            WHEN g.game_status = 'postponed'
            THEN g.game_id
        END) AS postponed_count,

        AVG(CASE
            WHEN g.game_status = 'completed'
            THEN game_stats.total_runs
        END) AS avg_runs,

        SUM(game_stats.wins) AS total_wins,
        SUM(game_stats.losses) AS total_losses,
        SUM(game_stats.draws) AS total_draws

    FROM stadium s

    LEFT JOIN game g
        ON s.stadium_id = g.stadium_id
        $join_condition

    LEFT JOIN (
        SELECT
            game_id,
            SUM(runs) AS total_runs,

            SUM(CASE
                WHEN result = 'W' THEN 1
                ELSE 0
            END) AS wins,

            SUM(CASE
                WHEN result = 'L' THEN 1
                ELSE 0
            END) AS losses,

            SUM(CASE
                WHEN result = 'D' THEN 1
                ELSE 0
            END) AS draws

        FROM team_game_stats
        GROUP BY game_id
    ) AS game_stats
        ON g.game_id = game_stats.game_id

    GROUP BY s.city WITH ROLLUP
";

// SQL 실행
$regions = db_query($sql, $types, $params);

// 네비게이션
require_once 'header.php';
?>

<!-- 메인 제목 -->
<section class="intro">
    <h1 class="title">Roll-up</h1>
    <p class="content">
        Analyze how weather conditions affect KBO game performance
        by region, stadium, and month.
    </p>
</section>

<!-- Roll-up 분석 영역 -->
<section class="analysis-page-layout">

    <!-- 왼쪽 영역: 분석 조건 -->
    <div class="filter-panel">
        <h2>Filters</h2>

        <form method="GET" action="analysis_rollup.php" class="analysis-page-form">

            <!-- 연도 선택 -->
            <label for="year">Season</label>
            <select name="year" id="year">
                <option value="">All Seasons</option>

                <?php for ($year = 2020; $year <= 2026; $year++): ?>
                    <option value="<?= $year ?>"
                        <?= ($selected_year == (string)$year) ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endfor; ?>
            </select>

            <!-- 기온 범위 -->
            <br>
            <label>Temperature (°C)</label>
            <div class="temperature-inputs">
                <input type="number" name="min_temp"
                    step="0.1" placeholder="Min"
                    value="<?= h($min_temp) ?>">

                <input type="number" name="max_temp"
                    step="0.1" placeholder="Max"
                    value="<?= h($max_temp) ?>">
            </div>

            <!-- 강수 여부 -->
            <br>
            <label for="rain">Rain Condition</label>
            <select name="rain" id="rain">
                <option value="">All</option>
                <option value="dry" <?= $rain_condition === 'dry' ? 'selected' : '' ?>>
                    Dry
                </option>
                <option value="rain" <?= $rain_condition === 'rain' ? 'selected' : '' ?>>
                    Rain
                </option>
            </select>

            <!-- 팀 선택 -->
            <br>
            <label for="team_id">Team</label>
            <select name="team_id" id="team_id">
                <option value="">All Teams</option>

                <?php foreach ($teams as $team): ?>
                    <option value="<?= h($team['team_id']) ?>"
                        <?= ((string)$team_id === (string)$team['team_id']) ? 'selected' : '' ?>>
                        <?= h($team['team_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- 경기장 유형 -->
            <br>
            <label for="stadium_type">Stadium Type</label>
            <select name="stadium_type" id="stadium_type">
                <option value="">All</option>
                <option value="dome" <?= $stadium_type === 'dome' ? 'selected' : '' ?>>
                    Dome
                </option>
                <option value="outdoor" <?= $stadium_type === 'outdoor' ? 'selected' : '' ?>>
                    Outdoor
                </option>
            </select>

            <!-- 검색 및 초기화 -->
            <br>
            <button type="submit" class="analysis-page-submit">Search</button>
            <a href="analysis_rollup.php?reset=1" class="analysis-page-reset">Reset</a>

        </form>
    </div>

    <!-- 오른쪽 영역: 분석 결과 -->
    <div class="result-panel">
        <h2>Analysis Results</h2>
        <h3>Region Summary</h3>

        <div class="analysis-page-table-wrap">
            <table class="analysis-page-table">
                <thead>
                    <tr>
                        <th>Region</th>
                        <th>Completed</th>
                        <th>Cancelled</th>
                        <th>Postponed</th>
                        <th>Avg. Runs</th>
                        <th>Wins</th>
                        <th>Losses</th>
                        <th>Draws</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($regions as $region): ?>
                        <tr>
                            <td>
                                <?= $region['city'] === null ? 'All Regions' : h($region['city']) ?>
                            </td>
                            <td><?= h($region['completed_count']) ?></td>
                            <td><?= h($region['cancelled_count']) ?></td>
                            <td><?= h($region['postponed_count']) ?></td>
                            <td>
                                <?= $region['avg_runs'] !== null
                                    ? number_format((float)$region['avg_runs'], 1)
                                    : '-' ?>
                            </td>
                            <td><?= h($region['total_wins']) ?></td>
                            <td><?= h($region['total_losses']) ?></td>
                            <td><?= h($region['total_draws']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

</main>
</body>
</html>