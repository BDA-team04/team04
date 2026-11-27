<?php
// 작성자: 용연주
// 수정: 정윤아 (지역 → 구장 → 월 단계 연결, 구장별 요약·월별 날씨 지표 추가)
require_once 'session.php';
require_once 'db.php';

// 초기화
if (isset($_GET['reset'])) {
    clear_filter('analysis_drilldown');
    header('Location: analysis_drilldown.php');
    exit;
}

// 기본 분석 조건
$default_filter = [
    'city' => '',
    'stadium_id' => ''
];

// 이전에 저장한 조건
$filter = load_filter('analysis_drilldown', $default_filter);

// 새로운 조건 저장
$filter_keys = array_keys($default_filter);

if (count(array_intersect($filter_keys, array_keys($_GET))) > 0) {
    $filter = array_merge($default_filter, $_GET);
}

// 지역 목록
$cities = db_query("
    SELECT DISTINCT city
    FROM stadium
    WHERE city IS NOT NULL
    ORDER BY city
");

// 지역 검증
$selected_city = (string)$filter['city'];

$allowed_cities = array_column($cities, 'city');

if (!in_array($selected_city, $allowed_cities, true)) {
    $selected_city = '';
}

// 경기장 ID 검증
$selected_stadium = $filter['stadium_id'];

if (
    $selected_city !== '' &&
    ctype_digit((string)$selected_stadium)
) {
    $selected_stadium = (int)$selected_stadium;
} else {
    $selected_stadium = '';
}

// 지역별 경기장 목록
$stadiums = [];

if ($selected_city !== '') {
    $stadiums = db_query(
        "SELECT stadium_id, stadium_name
         FROM stadium
         WHERE city = ?
         ORDER BY stadium_name",
        's',
        [$selected_city]
    );
}

// 선택한 경기장이 해당 지역에 속하는지 확인
$stadium_ids = array_column($stadiums, 'stadium_id');

if (
    $selected_stadium !== '' &&
    !in_array($selected_stadium, array_map('intval', $stadium_ids), true)
) {
    $selected_stadium = '';
}

// 검증한 조건 세션에 저장
save_filter('analysis_drilldown', [
    'city' => $selected_city,
    'stadium_id' => $selected_stadium
]);

// 경기 1건당 1행: 양 팀 득점 합계, 홈팀 승·패
$game_stats_sql = "
    SELECT
        game_id,
        SUM(runs) AS total_runs,
        SUM(CASE WHEN is_home = 1 AND result = 'W' THEN 1 ELSE 0 END) AS home_wins,
        SUM(CASE WHEN is_home = 1 AND result = 'L' THEN 1 ELSE 0 END) AS home_losses
    FROM team_game_stats
    GROUP BY game_id
";

// 1단계 아래: 선택한 지역의 구장별 현황 (지역 → 구장)
$stadium_summary = [];

if ($selected_city !== '' && $selected_stadium === '') {
    $stadium_summary = db_query(
        "SELECT
            s.stadium_id,
            s.stadium_name,
            s.is_dome,
            COUNT(CASE WHEN g.game_status = 'completed' THEN 1 END) AS completed_count,
            COUNT(CASE WHEN g.game_status = 'cancelled' THEN 1 END) AS cancelled_count,
            COUNT(CASE WHEN g.game_status = 'postponed' THEN 1 END) AS postponed_count,
            AVG(CASE WHEN g.game_status = 'completed' THEN gs.total_runs END) AS avg_runs,
            100 * SUM(gs.home_wins) / NULLIF(SUM(gs.home_wins) + SUM(gs.home_losses), 0) AS home_win_pct
         FROM stadium s
         LEFT JOIN game g ON g.stadium_id = s.stadium_id
         LEFT JOIN ($game_stats_sql) AS gs ON gs.game_id = g.game_id
         WHERE s.city = ?
         GROUP BY s.stadium_id, s.stadium_name, s.is_dome
         ORDER BY s.stadium_name",
        's',
        [$selected_city]
    );
}

// 2단계 아래: 선택한 구장의 월별 현황 (구장 → 월)
$monthly = [];

if ($selected_stadium !== '') {
    $monthly = db_query(
        "SELECT
            MONTH(g.game_date) AS month,
            COUNT(CASE WHEN g.game_status = 'completed' THEN 1 END) AS completed_count,
            COUNT(CASE WHEN g.game_status = 'cancelled' THEN 1 END) AS cancelled_count,
            COUNT(CASE WHEN g.game_status = 'postponed' THEN 1 END) AS postponed_count,
            AVG(CASE WHEN g.game_status = 'completed' THEN gs.total_runs END) AS avg_runs,
            AVG(w.avg_temp_c) AS avg_temp,
            AVG(w.precipitation_mm) AS avg_rain
         FROM game g
         JOIN stadium s ON s.stadium_id = g.stadium_id
         LEFT JOIN ($game_stats_sql) AS gs ON gs.game_id = g.game_id
         LEFT JOIN weather w ON w.city = s.city AND w.obs_date = g.game_date
         WHERE g.stadium_id = ?
         GROUP BY MONTH(g.game_date)
         ORDER BY month",
        'i',
        [$selected_stadium]
    );
}

// 화면 위쪽 경로 표시용 구장 이름
$selected_stadium_name = '';
foreach ($stadiums as $stadium) {
    if ((int)$stadium['stadium_id'] === $selected_stadium) {
        $selected_stadium_name = $stadium['stadium_name'];
    }
}

$month_names = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// 네비게이션
require_once 'header.php';
?>

<!-- 메인 제목 -->
<section class="intro">
    <h1 class="title">Drill-down</h1>
    <p class="content">
        Explore monthly KBO game status by region and stadium.
    </p>
</section>

<!-- Drill-down 분석 영역 -->
<section class="analysis-page-layout">

    <!-- 왼쪽 영역: 분석 조건 -->
    <div class="filter-panel">
        <h2>Drill-down Filters</h2>

        <form method="GET"
              action="analysis_drilldown.php"
              class="analysis-page-form">

            <!-- 지역 선택 -->
            <label for="city">Select Region</label>

            <select name="city"
                    id="city"
                    onchange="this.form.submit()">

                <option value="">Select Region</option>

                <?php foreach ($cities as $city): ?>
                    <option value="<?= h($city['city']) ?>"
                        <?= $selected_city === $city['city']
                            ? 'selected' : '' ?>>
                        <?= h($city['city']) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </form>

        <!-- 경기장 선택 -->
        <?php if ($selected_city !== ''): ?>

            <form method="GET"
                  action="analysis_drilldown.php"
                  class="analysis-page-form">

                <input type="hidden"
                       name="city"
                       value="<?= h($selected_city) ?>">

                <label for="stadium_id">Select Stadium</label>

                <select name="stadium_id" id="stadium_id">
                    <option value="">Select Stadium</option>

                    <?php foreach ($stadiums as $stadium): ?>
                        <option value="<?= h($stadium['stadium_id']) ?>"
                            <?= (string)$selected_stadium ===
                                (string)$stadium['stadium_id']
                                ? 'selected' : '' ?>>
                            <?= h($stadium['stadium_name']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <button type="submit" class="analysis-page-submit">
                    Search
                </button>
            </form>

        <?php endif; ?>

        <div class="analysis-page-form">
            <a href="analysis_rollup.php" class="analysis-page-reset">Back to Roll-up</a>
            <a href="analysis_drilldown.php?reset=1" class="analysis-page-reset">Reset</a>
        </div>

    </div>

    <!-- 오른쪽 영역: 분석 결과 -->
    <div class="result-panel">
        <h2>Analysis Results</h2>

        <!-- 현재 위치: 전체 지역 › 지역 › 구장 (누르면 한 단계 위로) -->
        <p class="analysis-page-message">
            <a href="analysis_rollup.php">All Regions</a>
            <?php if ($selected_city !== ''): ?>
                &rsaquo;
                <a href="analysis_drilldown.php?city=<?= urlencode($selected_city) ?>"><?= h($selected_city) ?></a>
            <?php endif; ?>
            <?php if ($selected_stadium_name !== ''): ?>
                &rsaquo; <?= h($selected_stadium_name) ?>
            <?php endif; ?>
        </p>

        <?php if ($selected_city === ''): ?>

            <p class="analysis-page-message">
                Please select a region to begin, or click a region on the Roll-up page.
            </p>

        <?php elseif ($selected_stadium === ''): ?>

            <h3>Stadium Summary: <?= h($selected_city) ?></h3>
            <p class="analysis-page-message">Click a stadium to see its monthly results.</p>

            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead>
                        <tr>
                            <th>Stadium</th>
                            <th>Completed</th>
                            <th>Cancelled</th>
                            <th>Postponed</th>
                            <th>Avg. Runs / Game</th>
                            <th>Home Win %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stadium_summary as $row): ?>
                            <tr>
                                <td>
                                    <a href="analysis_drilldown.php?city=<?= urlencode($selected_city) ?>&amp;stadium_id=<?= (int)$row['stadium_id'] ?>">
                                        <?= h($row['stadium_name']) ?>
                                    </a>
                                    <?= $row['is_dome'] ? ' (dome)' : '' ?>
                                </td>
                                <td><?= (int)$row['completed_count'] ?></td>
                                <td><?= (int)$row['cancelled_count'] ?></td>
                                <td><?= (int)$row['postponed_count'] ?></td>
                                <td><?= $row['avg_runs'] !== null ? number_format((float)$row['avg_runs'], 1) : '-' ?></td>
                                <td><?= $row['home_win_pct'] !== null ? number_format((float)$row['home_win_pct'], 1) . '%' : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <h3>Monthly Game Summary: <?= h($selected_stadium_name) ?></h3>

            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Completed</th>
                            <th>Cancelled</th>
                            <th>Postponed</th>
                            <th>Avg. Runs / Game</th>
                            <th>Avg. Temp (°C)</th>
                            <th>Avg. Rain (mm)</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($monthly)): ?>

                            <tr>
                                <td colspan="7">
                                    No game data available.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($monthly as $row): ?>
                                <tr>
                                    <td><?= h($month_names[(int)$row['month']] ?? $row['month']) ?></td>
                                    <td><?= (int)$row['completed_count'] ?></td>
                                    <td><?= (int)$row['cancelled_count'] ?></td>
                                    <td><?= (int)$row['postponed_count'] ?></td>
                                    <td><?= $row['avg_runs'] !== null ? number_format((float)$row['avg_runs'], 1) : '-' ?></td>
                                    <td><?= $row['avg_temp'] !== null ? number_format((float)$row['avg_temp'], 1) : '-' ?></td>
                                    <td><?= $row['avg_rain'] !== null ? number_format((float)$row['avg_rain'], 1) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>

</section>

</main>
</body>
</html>