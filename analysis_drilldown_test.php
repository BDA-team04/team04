<?php
// 작성자: 용연주
require_once 'db_test.php';

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

// 월별 경기 데이터
$monthly = [];

if ($selected_stadium !== '') {
    $monthly = db_query(
        "SELECT
            MONTH(g.game_date) AS month,
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
            END) AS postponed_count
         FROM game g
         WHERE g.stadium_id = ?
         GROUP BY MONTH(g.game_date)
         ORDER BY month",
        'i',
        [$selected_stadium]
    );
}

require_once 'header.php';
?>

<section class="intro">
    <h1 class="title">Drill-down Analysis</h1>
    <p class="content">
        Explore KBO game performance by region,
        stadium, and month.
    </p>
</section>


<section class="analysis-page-layout">

    <!-- 왼쪽: 선택 영역 -->
    <div class="filter-panel">
        <h2>Drill-down Filters</h2>

        <h3>1. Select Region</h3>
        <?php
        $cities = db_query(
            "SELECT DISTINCT city FROM stadium ORDER BY city"
        );
        ?>
        <form method="GET" action="analysis_drilldown_test.php">
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

        <?php if ($selected_city !== ''): ?>
            <h3>2. Select Stadium</h3>

            <form method="GET" action="analysis_drilldown_test.php">
                <input type="hidden" name="city"
                       value="<?= h($selected_city) ?>">

                <select name="stadium_id"
                        onchange="this.form.submit()">
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
            </form>
        <?php endif; ?>

        <a href="analysis_rollup_test.php">Back to Roll-up</a>
    </div>

    <!-- 오른쪽: 분석 결과 -->
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