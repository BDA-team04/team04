<?php
// 작성자: 용연주
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

// 월별 경기 현황
$monthly = [];

if ($selected_stadium !== '') {
    $monthly = db_query(
        "SELECT
            MONTH(game_date) AS month,

            COUNT(CASE
                WHEN game_status = 'completed' THEN 1
            END) AS completed_count,

            COUNT(CASE
                WHEN game_status = 'cancelled' THEN 1
            END) AS cancelled_count,

            COUNT(CASE
                WHEN game_status = 'postponed' THEN 1
            END) AS postponed_count

         FROM game
         WHERE stadium_id = ?

         GROUP BY MONTH(game_date)
         ORDER BY month",
        'i',
        [$selected_stadium]
    );
}

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

        <a href="analysis_rollup.php">Back to Roll-up</a>

        <a href="analysis_drilldown.php?reset=1">
            Reset
        </a>

    </div>

    <!-- 오른쪽 영역: 분석 결과 -->
    <div class="result-panel">
        <h2>Analysis Results</h2>

        <?php if ($selected_city === ''): ?>

            <p class="analysis-page-message">
                Please select a region to begin.
            </p>

        <?php elseif ($selected_stadium === ''): ?>

            <p class="analysis-page-message">
                Please select a stadium to view monthly analysis.
            </p>

        <?php else: ?>

            <h3>Monthly Game Summary</h3>

            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Completed</th>
                            <th>Cancelled</th>
                            <th>Postponed</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($monthly)): ?>

                            <tr>
                                <td colspan="4">
                                    No game data available.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($monthly as $row): ?>
                                <tr>
                                    <td><?= h($row['month']) ?>월</td>
                                    <td><?= h($row['completed_count']) ?></td>
                                    <td><?= h($row['cancelled_count']) ?></td>
                                    <td><?= h($row['postponed_count']) ?></td>
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