<?php
// 작성자: 용연주
require_once 'db_test.php';

// 선택한 연도
$selected_year = $_GET['year'] ?? '';

$allowed_years = ['', '2020', '2021', '2022', '2023', '2024', '2025', '2026'];

if (!in_array($selected_year, $allowed_years, true)) {
    $selected_year = '';
}

$year_param = ($selected_year === '') ? 0 : (int)$selected_year;

// 팀 목록 가져오기
$teams = db_query("
    SELECT team_id, team_name
    FROM team
    ORDER BY team_name
");

// 선택한 팀
$team_id = $_GET['team_id'] ?? '';

if (!is_numeric($team_id) || $team_id === '') {
    $team_id = '';
} else {
    $team_id = (int)$team_id;
}

// 기온 필터
$min_temp = $_GET['min_temp'] ?? '';
$max_temp = $_GET['max_temp'] ?? '';

$min_temp = (is_numeric($min_temp)) ? (float)$min_temp : '';
$max_temp = (is_numeric($max_temp)) ? (float)$max_temp : '';

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

// 최저 기온
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

// 최고 기온
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

// 비 여부 필터
$rain_condition = $_GET['rain'] ?? '';

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
}

if ($rain_condition === 'rain') {
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
$stadium_type = $_GET['stadium_type'] ?? '';

if ($stadium_type === 'dome') {
    $join_condition .= "
        AND s.is_dome = 1
    ";
}

if ($stadium_type === 'outdoor') {
    $join_condition .= "
        AND s.is_dome = 0
    ";
}

// 지역별 경기 현황
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
        END) AS avg_runs

    FROM stadium s

    LEFT JOIN game g
        ON s.stadium_id = g.stadium_id
        $join_condition

    LEFT JOIN (
        SELECT
            game_id,
            SUM(runs) AS total_runs
        FROM team_game_stats
        GROUP BY game_id
    ) AS game_stats
        ON g.game_id = game_stats.game_id

    GROUP BY s.city
    ORDER BY s.city
";

// SQL 실행
$regions = db_query($sql, $types, $params);

require_once 'header.php';
?>

<!-- 메인 제목 -->
<section class="intro">
    <h1 class="title">Roll-up / Drill-down</h1>
    <p class="content">
        Analyze how weather conditions affect KBO game performance
        by region, stadium, and month.
    </p>
</section>

<!-- Roll-up / Drill-down 분석 영역 -->
<section class="analysis-page-layout">

    <!-- 왼쪽 영역: 분석 조건 -->
    <div class="filter-panel">
        <h2>Filters</h2>

        <form method="GET" action="analysis_rollup_test.php">

            <!-- 날짜 선택 -->
            <label for="year">Season</label>
            <select name="year" id="year">
                <option value="">All Seasons</option>
                <?php for ($year = 2020; $year <= 2026; $year++): ?>
                <option value="<?= $year ?>"
                    <?= (($_GET['year'] ?? '') == $year) ? 'selected' : '' ?>>
                    <?= $year ?>
                </option>
            <?php endfor; ?>
            </select>

            <!-- 기온 범위 -->
            <label>Temperature (°C)</label>
            <div>
            <input type="number" name="min_temp"
                step="0.1" placeholder="Min"
                value="<?= h($_GET['min_temp'] ?? '') ?>">

            <input type="number" name="max_temp"
                step="0.1" placeholder="Max"
                value="<?= h($_GET['max_temp'] ?? '') ?>">
            </div>

            <!-- 강수 여부 -->
            <label for="rain">Rain Condition</label>
            <select name="rain">
                <option value="">All</option>
                <option value="dry" <?= ($_GET['rain'] ?? '') === 'dry' ? 'selected' : '' ?>>
                    Dry
                </option>
                <option value="rain" <?= ($_GET['rain'] ?? '') === 'rain' ? 'selected' : '' ?>>
                    Rain
                </option>
            </select>

            <!-- 팀 선택 -->
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
            <label for="stadium_type">Stadium Type</label>
            <select name="stadium_type" id="stadium_type">
                <option value="">All</option>

                <option value="dome"
                    <?= ($_GET['stadium_type'] ?? '') === 'dome' ? 'selected' : '' ?>>
                    Dome
                </option>

                <option value="outdoor"
                    <?= ($_GET['stadium_type'] ?? '') === 'outdoor' ? 'selected' : '' ?>>
                    Outdoor
                </option>
            </select>

            <!-- 검색 및 초기화 -->
            <button type="submit">Search</button>
            <a href="analysis_rollup_test.php">Reset</a>

        </form>
    </div>

    <!-- 오른쪽 영역: 분석 결과 -->
    <div class="result-panel">
        <h2>Analysis Results</h2>

        <!-- 지역별 경기 현황 -->
        <h3>Region Summary</h3>

        
<table>
    <thead>
        <tr>
            <th>Region</th>
            <th>Completed</th>
            <th>Cancelled</th>
            <th>Postponed</th>
            <th>Avg. Runs</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($regions as $region): ?>
            <tr>
                <td><?= h($region['city']) ?></td>
                <td><?= h($region['completed_count']) ?></td>
                <td><?= h($region['cancelled_count']) ?></td>
                <td><?= h($region['postponed_count']) ?></td>
                <td>
                    <?php
                    if ($region['avg_runs'] !== null) {
                        echo number_format((float)$region['avg_runs'], 1);
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>


    </div>

</section>

</main>
</body>
</html>