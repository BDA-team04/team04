<?php
// 작성자: 안예은
// Aggregating: temperature bins x stadium x home/away performance.
// Uses the shared DB and session helpers, without modifying teammates' files.
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$user = current_user();
$page = 'analysis_group_' . ($user ? (int)$user['user_id'] : 'guest');
$default = [
    'season' => 'all',
    'stadiums' => [],
    'home_away' => 'all',
    'exclude_dome' => 1,
    'min_games' => 5,
];

// Load conditions from session, unless the user changed or reset them.
if (isset($_GET['reset'])) {
    clear_filter($page);
    $raw = $default;
} elseif (isset($_GET['submit'])) {
    $raw = $_GET;
} else {
    $raw = load_filter($page, $default);
}
$season = (string)($raw['season'] ?? 'all');
if (!in_array($season, ['all', '2020', '2021', '2022', '2023', '2024', '2025', '2026'], true)) {
    $season = 'all';
}
$homeAway = (string)($raw['home_away'] ?? 'all');
if (!in_array($homeAway, ['all', 'home', 'away'], true)) {
    $homeAway = 'all';
}
$excludeDome = !empty($raw['exclude_dome']) ? 1 : 0;
$minGames = filter_var($raw['min_games'] ?? 5, FILTER_VALIDATE_INT);
if ($minGames === false || $minGames < 1 || $minGames > 1000) {
    $minGames = 5;
}
$rawStadiums = $raw['stadiums'] ?? [];
if (!is_array($rawStadiums)) {
    $rawStadiums = [];
}
$selectedStadiums = array_values(array_unique(array_filter(
    array_map('intval', $rawStadiums), fn($id) => $id > 0
)));

$stadiums = [];
$rows = [];
$error = null;
$tempLabels = [1 => 'Below 15°C', 2 => '15–20°C', 3 => '20–25°C',
               4 => '25–28°C', 5 => '28°C and above'];
$chart = [];

try {
    $stadiums = db_query('SELECT stadium_id, stadium_name, city, is_dome FROM stadium ORDER BY stadium_name');

$cityEnglish = [
    '서울' => 'Seoul',
    '인천' => 'Incheon',
    '광주' => 'Gwangju',
    '대구' => 'Daegu',
    '대전' => 'Daejeon',
    '부산' => 'Busan',
    '수원' => 'Suwon',
    '울산' => 'Ulsan',
    '창원' => 'Changwon',
    '청주' => 'Cheongju',
    '포항' => 'Pohang',
];

    $validIds = array_map('intval', array_column($stadiums, 'stadium_id'));
    $selectedStadiums = array_values(array_intersect($selectedStadiums, $validIds));

    if (isset($_GET['submit'])) {
        save_filter($page, [
            'season' => $season,
            'stadiums' => $selectedStadiums,
            'home_away' => $homeAway,
            'exclude_dome' => $excludeDome,
            'min_games' => $minGames,
        ]);
    }

    // A result row is one completed game for one team (home OR away).
    // Joining on both city and date prevents multiplying stadium game records.
    $sql = "
        SELECT
            CASE
                WHEN w.avg_temp_c < 15 THEN 1
                WHEN w.avg_temp_c < 20 THEN 2
                WHEN w.avg_temp_c < 25 THEN 3
                WHEN w.avg_temp_c < 28 THEN 4
                ELSE 5
            END AS temp_order,
            st.stadium_id,
            st.stadium_name,
            s.is_home,
            COUNT(*) AS game_count,
            ROUND(AVG(s.runs), 2) AS avg_runs,
            ROUND(AVG(s.hits), 2) AS avg_hits,
            ROUND(100 * AVG(CASE WHEN s.result = 'W' THEN 1
                                 WHEN s.result = 'L' THEN 0
                                 ELSE NULL END), 2) AS win_pct
        FROM team_game_stats AS s
        INNER JOIN game AS g ON s.game_id = g.game_id
        INNER JOIN stadium AS st ON st.stadium_id = g.stadium_id
        INNER JOIN weather AS w ON w.city = st.city AND w.obs_date = g.game_date
        WHERE g.game_status = 'completed' AND w.avg_temp_c IS NOT NULL
    ";
    $types = '';
    $params = [];
    if ($season !== 'all') {
        $sql .= ' AND YEAR(g.game_date) = ?';
        $types .= 'i';
        $params[] = (int)$season;
    }
    if ($excludeDome) {
        $sql .= ' AND st.is_dome = 0';
    }
    if ($homeAway !== 'all') {
        $sql .= ' AND s.is_home = ?';
        $types .= 'i';
        $params[] = $homeAway === 'home' ? 1 : 0;
    }
    if ($selectedStadiums) {
        $marks = implode(',', array_fill(0, count($selectedStadiums), '?'));
        $sql .= " AND st.stadium_id IN ($marks)";
        $types .= str_repeat('i', count($selectedStadiums));
        array_push($params, ...$selectedStadiums);
    }
    $sql .= "
        GROUP BY temp_order, st.stadium_id, st.stadium_name, s.is_home
        HAVING COUNT(*) >= ?
        ORDER BY temp_order, st.stadium_name, s.is_home DESC
    ";
    $types .= 'i';
    $params[] = $minGames;
    $rows = db_query($sql, $types, $params);

    // Weighted across visible stadium/home-away groups: average runs per team-game.
    $sumRuns = array_fill_keys(array_keys($tempLabels), 0.0);
    $sumGames = array_fill_keys(array_keys($tempLabels), 0);
    foreach ($rows as $r) {
        $bin = (int)$r['temp_order'];
        $count = (int)$r['game_count'];
        $sumRuns[$bin] += (float)$r['avg_runs'] * $count;
        $sumGames[$bin] += $count;
    }
    foreach ($tempLabels as $bin => $label) {
        $chart[$bin] = ['label' => $label, 'n' => $sumGames[$bin],
                        'average' => $sumGames[$bin] ? $sumRuns[$bin] / $sumGames[$bin] : null];
    }
} catch (Throwable $e) {
    error_log('Aggregating: ' . $e->getMessage());
    $error = '분석 데이터를 불러오지 못했습니다. DB 연결 및 데이터 입력 상태를 확인해 주세요.';
}

require_once __DIR__ . '/header.php';
?>
<section class="intro">
    <h1 class="title">Aggregating</h1>
    <p class="content">Compare KBO performance by temperature, stadium, and home/away.</p>
</section>

<section class="analysis-page-layout">
    <div class="filter-panel">
        <h2>Filters</h2>
        <form method="get" action="analysis_group.php" class="analysis-page-form">
            <label for="season">Season</label>
            <select name="season" id="season">
                <option value="all" <?= $season === 'all' ? 'selected' : '' ?>>All seasons (2020–2026)</option>
                <?php for ($y = 2020; $y <= 2026; $y++): ?>
                    <option value="<?= $y ?>" <?= $season === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>

            <label class="analysis-page-size-label">Stadiums (none selected = all)</label>
            <div style="max-height:190px;overflow-y:auto;border:1px solid #dae0eb;border-radius:8px;padding:10px;">
                <?php foreach ($stadiums as $st): ?>
                    <label style="display:block;margin:6px 0;font-size:14px;">
                        <input type="checkbox" name="stadiums[]" value="<?= (int)$st['stadium_id'] ?>"
                            <?= in_array((int)$st['stadium_id'], $selectedStadiums, true) ? 'checked' : '' ?>>
                        <?= h($st['stadium_name']) ?> (<?= h($cityEnglish[$st['city']] ?? $st['city']) ?>)
                    </label>
                <?php endforeach; ?>
            </div>

            <label class="analysis-page-size-label">Home / Away</label>
            <div class="analysis-page-options">
                <?php foreach (['all' => 'All', 'home' => 'Home', 'away' => 'Away'] as $value => $label): ?>
                    <label><input type="radio" name="home_away" value="<?= h($value) ?>"
                        <?= $homeAway === $value ? 'checked' : '' ?>> <?= h($label) ?></label>
                <?php endforeach; ?>
            </div>

            <label style="display:block;margin:12px 0;font-size:14px;">
                <input type="checkbox" name="exclude_dome" value="1" <?= $excludeDome ? 'checked' : '' ?>>
                Exclude dome stadium (recommended)
            </label>

            <label for="min_games" class="analysis-page-size-label">Minimum games per group</label>
            <input type="number" name="min_games" id="min_games" min="1" max="1000"
                   value="<?= $minGames ?>" required style="width:100%;padding:8px;">
            <button type="submit" name="submit" value="1" class="analysis-page-submit">Analyze</button>
            
<a href="analysis_group.php?reset=1"
   class="analysis-page-reset"
   style="text-decoration:none;">
    Reset filters
</a>

        </form>
    </div>

    <div class="result-panel">
        <h2>Analysis Results</h2>
        <?php if ($error): ?>
            <p class="analysis-page-message"><?= h($error) ?></p>
        <?php elseif (!$rows): ?>
            <p class="analysis-page-message">No data matched these conditions. Please check whether the CSV data has been imported, or lower the minimum games.</p>
        <?php else: ?>
            <p style="margin:12px 0;">Results: <?= number_format(count($rows)) ?> groups. Win rate excludes tied games.</p>
            <h3 class="analysis-page-chart-title">Average Runs by Temperature (visible groups)</h3>
            <div class="analysis-page-chart" style="display:block;height:auto;min-height:220px;">
                <?php
                    $maxAvg = max(1.0, ...array_map(
                        fn($entry) => (float)($entry['average'] ?? 0), array_values($chart)
                    ));
                ?>
                <?php foreach ($chart as $item): ?>
                    <div style="display:flex;align-items:center;gap:12px;margin:14px 0;">
                        <span style="width:115px;flex-shrink:0;font-size:13px;"><?= h($item['label']) ?></span>
                        <div style="flex:1;background:#e5ebf7;border-radius:6px;overflow:hidden;">
                            <div style="height:22px;width:<?= $item['average'] !== null ? round($item['average'] / $maxAvg * 100, 1) : 0 ?>%;background:#002063;border-radius:6px;"></div>
                        </div>
                        <span style="width:65px;text-align:right;font-size:13px;">
                            <?= $item['average'] !== null ? number_format($item['average'], 2) : 'N/A' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead><tr>
                        <th>Temp. range</th><th>Stadium</th><th>Home/Away</th>
                        <th>Games</th><th>Avg. runs</th><th>Avg. hits</th><th>Win rate</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h($tempLabels[(int)$row['temp_order']] ?? '') ?></td>
                            <td><?= h($row['stadium_name']) ?></td>
                            <td><?= (int)$row['is_home'] === 1 ? 'Home' : 'Away' ?></td>
                            <td><?= number_format((int)$row['game_count']) ?></td>
                            <td><?= number_format((float)$row['avg_runs'], 2) ?></td>
                            <td><?= number_format((float)$row['avg_hits'], 2) ?></td>
                            <td><?= $row['win_pct'] !== null ? number_format((float)$row['win_pct'], 1) . '%' : 'N/A' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
</main>
</body>
</html>
