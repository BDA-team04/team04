<?php
// 작성자: 용연주
require_once 'db.php';

// 선택한 지역
$selected_city = $_GET['city'] ?? '';

// 선택한 경기장
$selected_stadium = $_GET['stadium_id'] ?? '';

if (!is_numeric($selected_stadium) || $selected_stadium === '') {
    $selected_stadium = '';
} else {
    $selected_stadium = (int)$selected_stadium;
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
        Explore monthly weather conditions and KBO game
        status by region and stadium.
    </p>
</section>

<!-- Drill-down 분석 영역 -->
<section class="analysis-page-layout">

    <!-- 왼쪽 영역: 분석 조건 -->
    <div class="filter-panel">
        <h2>Drill-down Filters</h2>

        <!-- 지역 선택 -->
        <h3>1. Select Region</h3>

        <?php
        $cities = db_query(
            "SELECT DISTINCT city
             FROM stadium
             ORDER BY city"
        );
        ?>

        <form method="GET" action="analysis_drilldown.php">
            <select name="city" onchange="this.form.submit()">
                <option value="">Select Region</option>

                <?php foreach ($cities as $city): ?>
                    <option value="<?= h($city['city']) ?>"
                        <?= $selected_city === $city['city'] ? 'selected' : '' ?>>
                        <?= h($city['city']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- 경기장 선택 -->
        <?php if ($selected_city !== ''): ?>
            <h3>2. Select Stadium</h3>

            <form method="GET" action="analysis_drilldown.php">
                <input type="hidden" name="city"
                    value="<?= h($selected_city) ?>">

                <select name="stadium_id">
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

                <button type="submit">Search</button>
            </form>
        <?php endif; ?>

        <!-- Roll-up 페이지 이동 -->
        <a href="analysis_rollup.php">Back to Roll-up</a>

    </div>

    <!-- 오른쪽 영역: 분석 결과 -->
    <div class="result-panel">
        <h2>Analysis Results</h2>

        <?php if ($selected_city === ''): ?>
            <p>Please select a region to begin.</p>

        <?php elseif ($selected_stadium === ''): ?>
            <p>Please select a stadium to view monthly analysis.</p>

        <?php else: ?>
            <h3>Monthly Game Summary</h3>

            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Completed</th>
                        <th>Cancelled</th>
                        <th>Postponed</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($monthly as $row): ?>
                        <tr>
                            <td><?= h($row['month']) ?>월</td>
                            <td><?= h($row['completed_count']) ?></td>
                            <td><?= h($row['cancelled_count']) ?></td>
                            <td><?= h($row['postponed_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

</section>

</main>
</body>
</html>