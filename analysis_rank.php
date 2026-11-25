<?php
// 작성자: 박수민
// Ranking: team performance on hot days and rainy days.
// Uses the shared DB/session helpers. Do not modify common files for this page.

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$user = current_user();
$page = 'analysis_rank_' . ($user ? (int)$user['user_id'] : 'guest');

$default = [
    'analysis_type' => 'heat',
    'season' => 'all',
    'heat_threshold' => '33',
    'rain_threshold' => '1',
    'exclude_dome' => 1,
];

// Load the previous filter, use submitted values, or reset to defaults.
if (isset($_GET['reset'])) {
    clear_filter($page);
    $raw = $default;
} elseif (isset($_GET['submit'])) {
    $raw = $_GET;
} else {
    $raw = load_filter($page, $default);
}

$analysisTypeRaw = $raw['analysis_type'] ?? 'heat';
$analysisType = is_scalar($analysisTypeRaw) ? (string)$analysisTypeRaw : 'heat';
if (!in_array($analysisType, ['heat', 'rain'], true)) {
    $analysisType = 'heat';
}

$heatThresholdOptions = ['30', '33', '35'];
$rainThresholdOptions = ['0.1', '1', '5'];

$heatThresholdRaw = $raw['heat_threshold'] ?? '33';
$heatThreshold = is_scalar($heatThresholdRaw) ? (string)$heatThresholdRaw : '33';
if (!in_array($heatThreshold, $heatThresholdOptions, true)) {
    $heatThreshold = '33';
}

$rainThresholdRaw = $raw['rain_threshold'] ?? '1';
$rainThreshold = is_scalar($rainThresholdRaw) ? (string)$rainThresholdRaw : '1';
if (!in_array($rainThreshold, $rainThresholdOptions, true)) {
    $rainThreshold = '1';
}

$seasonRaw = $raw['season'] ?? 'all';
$season = is_scalar($seasonRaw) ? (string)$seasonRaw : 'all';
$excludeDome = !empty($raw['exclude_dome']) ? 1 : 0;

$availableSeasons = [];
$rows = [];
$error = null;

try {
    // Read the seasons actually available in the current database.
    $seasonRows = db_query(
        "SELECT DISTINCT YEAR(game_date) AS season
           FROM game
          WHERE game_status = 'completed'
          ORDER BY season DESC"
    );
    $availableSeasons = array_map('intval', array_column($seasonRows, 'season'));
    $availableSeasonStrings = array_map('strval', $availableSeasons);

    if ($season !== 'all' && !in_array($season, $availableSeasonStrings, true)) {
        $season = 'all';
    }

    // Save only validated values after the user submits the filter form.
    if (isset($_GET['submit'])) {
        save_filter($page, [
            'analysis_type' => $analysisType,
            'season' => $season,
            'heat_threshold' => $heatThreshold,
            'rain_threshold' => $rainThreshold,
            'exclude_dome' => $excludeDome,
        ]);
    }

    // Common JOIN: one team-game row -> game -> stadium -> city/date weather -> team name.
    // weather has a UNIQUE key on (city, obs_date), so this join does not multiply rows.
    $fromSql = "
        FROM team_game_stats AS s
        INNER JOIN game AS g
            ON s.game_id = g.game_id
        INNER JOIN stadium AS st
            ON st.stadium_id = g.stadium_id
        INNER JOIN weather AS w
            ON w.city = st.city
           AND w.obs_date = g.game_date
        INNER JOIN team AS t
            ON t.team_id = s.team_id
        WHERE g.game_status = 'completed'
    ";

    $types = '';
    $params = [];

    if ($analysisType === 'heat') {
        $fromSql .= ' AND w.max_temp_c IS NOT NULL AND w.max_temp_c >= ?';
        $types .= 'd';
        $params[] = (float)$heatThreshold;
    } else {
        $fromSql .= ' AND w.precipitation_mm IS NOT NULL AND w.precipitation_mm >= ?';
        $types .= 'd';
        $params[] = (float)$rainThreshold;
    }

    if ($excludeDome) {
        $fromSql .= ' AND st.is_dome = 0';
    }

    if ($season !== 'all') {
        $startDate = sprintf('%04d-01-01', (int)$season);
        $endDate = sprintf('%04d-01-01', (int)$season + 1);
        $fromSql .= ' AND g.game_date >= ? AND g.game_date < ?';
        $types .= 'ss';
        $params[] = $startDate;
        $params[] = $endDate;
    }

    if ($analysisType === 'heat') {
        // Keep the unrounded average for ranking; round only when displaying it.
        $aggregateSql = "
            SELECT
                t.team_id,
                t.team_name,
                COUNT(*) AS game_count,
                AVG(s.runs) AS metric_value
            {$fromSql}
            GROUP BY t.team_id, t.team_name
        ";
    } else {
        // Draws are shown separately and excluded from the win-rate denominator.
        $aggregateSql = "
            SELECT
                t.team_id,
                t.team_name,
                COUNT(*) AS game_count,
                SUM(CASE WHEN s.result = 'W' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN s.result = 'L' THEN 1 ELSE 0 END) AS losses,
                SUM(CASE WHEN s.result = 'D' THEN 1 ELSE 0 END) AS draws,
                SUM(CASE WHEN s.result IN ('W', 'L') THEN 1 ELSE 0 END) AS decided_games,
                100.0 * SUM(CASE WHEN s.result = 'W' THEN 1 ELSE 0 END)
                    / NULLIF(SUM(CASE WHEN s.result IN ('W', 'L') THEN 1 ELSE 0 END), 0)
                    AS metric_value
            {$fromSql}
            GROUP BY t.team_id, t.team_name
            HAVING SUM(CASE WHEN s.result IN ('W', 'L') THEN 1 ELSE 0 END) > 0
        ";
    }

    // MariaDB 10.11 supports RANK() as a window function.
    $extraColumns = ($analysisType === 'rain')
        ? ', wins, losses, draws, decided_games'
        : '';

    $sql = "
        SELECT
            RANK() OVER (ORDER BY metric_value DESC) AS ranking,
            team_id,
            team_name,
            game_count,
            ROUND(metric_value, 2) AS metric_value
            {$extraColumns}
        FROM (
            {$aggregateSql}
        ) AS team_summary
        ORDER BY ranking ASC, team_name ASC
    ";

    $rows = db_query($sql, $types, $params);
} catch (Throwable $e) {
    error_log('Ranking: ' . $e->getMessage());
    $error = '분석 데이터를 불러오지 못했습니다. DB 연결, 테이블 및 데이터 입력 상태를 확인해 주세요.';
}

$isHeat = ($analysisType === 'heat');
$pageTitle = $isHeat ? 'Team Average Runs on Hot Days' : 'Team Win Rate on Rainy Days';
$description = $isHeat
    ? 'Rank teams by average runs on days when the daily maximum temperature meets the selected threshold.'
    : 'Rank teams by win rate on days when the daily precipitation meets the selected threshold.';
$thresholdDescription = $isHeat
    ? 'Hot-day threshold: daily maximum temperature'
    : 'Rainy-day threshold: daily precipitation';
$metricUnit = $isHeat ? '' : '%';

require_once __DIR__ . '/header.php';
?>

<!-- Page-specific styles. The shared style.css and other team pages remain unchanged. -->
<style>
.analysis-page-layout {
    display: grid !important;
    grid-template-columns: minmax(240px, 285px) minmax(0, 1fr) !important;
    gap: 22px !important;
    align-items: start !important;
    width: min(1200px, calc(100% - 60px)) !important;
    max-width: 1200px !important;
    margin: 0 auto 48px !important;
    overflow: visible !important;
}
.analysis-page-layout > .filter-panel,
.analysis-page-layout > .result-panel {
    box-sizing: border-box !important;
    min-width: 0 !important;
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
    margin: 0 !important;
    padding: 24px !important;
}
.analysis-page-layout .filter-panel h2,
.analysis-page-layout .result-panel h2 { margin: 0 0 18px !important; }
.analysis-page-layout .analysis-page-form {
    display: flex !important;
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 8px !important;
}
.analysis-page-layout .analysis-page-form select {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    min-height: 36px !important;
    border: 1px solid #cbd3e3 !important;
    border-radius: 6px !important;
    padding: 6px 8px !important;
    font-size: 14px !important;
    background: #fff !important;
}
.analysis-page-layout .analysis-page-form label {
    line-height: 1.5 !important;
    font-size: 14px !important;
}
.analysis-page-layout .analysis-page-submit {
    display: inline-block !important;
    width: 100% !important;
    margin-top: 7px !important;
    padding: 10px 12px !important;
    border: 0 !important;
    border-radius: 7px !important;
    background: #16265f !important;
    color: white !important;
    cursor: pointer !important;
    font-weight: 600 !important;
    font-size: 14px !important;
}
.analysis-page-layout .analysis-page-reset {
    display: inline-block;
    margin-top: 8px;
    text-align: center;
    font-size: 14px;
}
.rank-filter-note,
.rank-result-note {
    color: #64748b;
    font-size: 12px;
    line-height: 1.55;
}
.rank-filter-group { margin: 5px 0 9px; }
.rank-check-label {
    display: flex !important;
    align-items: center;
    gap: 7px;
    margin: 9px 0 5px;
}
.rank-summary {
    margin: 0 0 18px;
    padding: 13px 15px;
    border: 1px solid #dce4f1;
    border-radius: 8px;
    background: #f7f9fd;
    color: #34445f;
    font-size: 13px;
    line-height: 1.6;
}
.rank-chart { margin: 18px 0 25px; }
.rank-chart-row {
    display: grid;
    grid-template-columns: 38px 70px minmax(50px, 1fr) 62px;
    align-items: center;
    gap: 10px;
    margin: 12px 0;
    font-size: 13px;
}
.rank-chart-position { color: #64748b; text-align: right; }
.rank-chart-team { font-weight: 600; color: #263653; }
.rank-bar-track { height: 21px; background: #e7edf7; border-radius: 5px; overflow: hidden; }
.rank-bar { height: 100%; background: #244b8f; border-radius: 5px; }
.rank-chart-value { text-align: right; font-variant-numeric: tabular-nums; }
.analysis-page-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    max-height: 540px !important;
    overflow: auto !important;
    box-sizing: border-box !important;
    margin-top: 20px !important;
    border: 1px solid #d7ddea !important;
    border-radius: 8px !important;
    background: white !important;
}
table.analysis-page-table {
    border-collapse: collapse !important;
    border-spacing: 0 !important;
    width: 100% !important;
    min-width: 560px !important;
    margin: 0 !important;
    font-size: 13px !important;
    line-height: 1.45 !important;
    color: #24334f !important;
}
.analysis-page-table th,
.analysis-page-table td {
    border: 0 !important;
    border-bottom: 1px solid #e7ebf3 !important;
    padding: 10px 11px !important;
    text-align: left !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
    font-size: 13px !important;
}
.analysis-page-table th {
    position: sticky !important;
    top: 0 !important;
    z-index: 2 !important;
    background: #e8edf8 !important;
    color: #18275c !important;
    font-weight: 700 !important;
}
.analysis-page-table tbody tr:nth-child(even) { background: #f6f8fd !important; }
.analysis-page-table tbody tr:hover { background: #edf2fa !important; }
.analysis-page-table td.numeric,
.analysis-page-table th.numeric { text-align: right !important; }
.rank-empty, .rank-error { padding: 16px; border-radius: 8px; background: #f7f9fd; color: #52627a; }
@media (max-width: 860px) {
    .analysis-page-layout {
        display: flex !important;
        flex-direction: column !important;
        width: calc(100% - 28px) !important;
        gap: 18px !important;
    }
    .analysis-page-layout > .filter-panel,
    .analysis-page-layout > .result-panel { padding: 18px !important; }
}
</style>

<section class="intro">
    <h1 class="title">Ranking</h1>
    <p class="content">Compare team performance on hot days and rainy days.</p>
</section>

<section class="analysis-page-layout">
    <div class="filter-panel">
        <h2>Filters</h2>
        <form method="get" action="analysis_rank.php" class="analysis-page-form">
            <label for="analysis_type">Analysis type</label>
            <select name="analysis_type" id="analysis_type">
                <option value="heat" <?= $analysisType === 'heat' ? 'selected' : '' ?>>Hot days · Average runs</option>
                <option value="rain" <?= $analysisType === 'rain' ? 'selected' : '' ?>>Rainy days · Win rate</option>
            </select>

            <label for="season">Season</label>
            <select name="season" id="season">
                <option value="all" <?= $season === 'all' ? 'selected' : '' ?>>All available seasons</option>
                <?php foreach ($availableSeasons as $year): ?>
                    <option value="<?= (int)$year ?>" <?= $season === (string)$year ? 'selected' : '' ?>>
                        <?= (int)$year ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="rank-filter-group" id="heat-threshold-group" style="<?= $isHeat ? '' : 'display:none;' ?>">
                <label for="heat_threshold">Hot-day threshold (daily maximum temperature)</label>
                <select name="heat_threshold" id="heat_threshold">
                    <?php foreach ($heatThresholdOptions as $value): ?>
                        <option value="<?= h($value) ?>" <?= $heatThreshold === $value ? 'selected' : '' ?>>
                            <?= h($value) ?>°C or higher
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rank-filter-group" id="rain-threshold-group" style="<?= !$isHeat ? '' : 'display:none;' ?>">
                <label for="rain_threshold">Rainy-day threshold (daily precipitation)</label>
                <select name="rain_threshold" id="rain_threshold">
                    <?php foreach ($rainThresholdOptions as $value): ?>
                        <option value="<?= h($value) ?>" <?= $rainThreshold === $value ? 'selected' : '' ?>>
                            <?= h($value) ?> mm or higher
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="rank-check-label">
                <input type="checkbox" name="exclude_dome" value="1" <?= $excludeDome ? 'checked' : '' ?>>
                Exclude dome stadiums
            </label>
            <p class="rank-filter-note">Checked by default because dome stadiums are less exposed to outdoor weather.</p>

            <button type="submit" name="submit" value="1" class="analysis-page-submit">Analyze</button>
            <a href="analysis_rank.php?reset=1" class="analysis-page-reset">Reset filters</a>
        </form>
    </div>

    <div class="result-panel">
        <h2>Analysis Results</h2>

        <?php if ($error): ?>
            <p class="rank-error"><?= h($error) ?></p>
        <?php elseif (!$rows): ?>
            <p class="rank-empty">No data matched these conditions. Please check the imported game and weather data.</p>
        <?php else: ?>
            <div class="rank-summary">
                <strong><?= h($pageTitle) ?></strong><br>
                <?= h($thresholdDescription) ?>:
                <?php if ($isHeat): ?>
                    <strong><?= h($heatThreshold) ?>°C or higher</strong>
                <?php else: ?>
                    <strong><?= h($rainThreshold) ?> mm or higher</strong>
                <?php endif; ?>
                · Season: <strong><?= $season === 'all' ? 'All available seasons' : h($season) ?></strong>
                · Dome stadiums: <strong><?= $excludeDome ? 'Excluded' : 'Included' ?></strong>
            </div>

            <p class="rank-result-note">
                <?= $isHeat
                    ? 'Ranking is based on each team’s average runs per team-game.'
                    : 'Win rate = wins ÷ (wins + losses). Draws are excluded from the denominator.' ?>
            </p>

            <?php
                $maxMetric = max(1.0, ...array_map(
                    fn($row) => (float)$row['metric_value'], $rows
                ));
            ?>
            <div class="rank-chart" aria-label="Ranking chart">
                <?php foreach ($rows as $row): ?>
                    <?php $barWidth = min(100, max(0, (float)$row['metric_value'] / $maxMetric * 100)); ?>
                    <div class="rank-chart-row">
                        <span class="rank-chart-position">#<?= (int)$row['ranking'] ?></span>
                        <span class="rank-chart-team"><?= h($row['team_name']) ?></span>
                        <div class="rank-bar-track">
                            <div class="rank-bar" style="width: <?= number_format($barWidth, 1, '.', '') ?>%;"></div>
                        </div>
                        <span class="rank-chart-value">
                            <?= number_format((float)$row['metric_value'], 2) ?><?= h($metricUnit) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead>
                        <?php if ($isHeat): ?>
                            <tr>
                                <th>Rank</th>
                                <th>Team</th>
                                <th class="numeric">Games</th>
                                <th class="numeric">Average runs</th>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th>Rank</th>
                                <th>Team</th>
                                <th class="numeric">Games</th>
                                <th class="numeric">Wins</th>
                                <th class="numeric">Losses</th>
                                <th class="numeric">Draws</th>
                                <th class="numeric">Win rate</th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= (int)$row['ranking'] ?></td>
                                <td><?= h($row['team_name']) ?></td>
                                <td class="numeric"><?= number_format((int)$row['game_count']) ?></td>
                                <?php if ($isHeat): ?>
                                    <td class="numeric"><?= number_format((float)$row['metric_value'], 2) ?></td>
                                <?php else: ?>
                                    <td class="numeric"><?= number_format((int)$row['wins']) ?></td>
                                    <td class="numeric"><?= number_format((int)$row['losses']) ?></td>
                                    <td class="numeric"><?= number_format((int)$row['draws']) ?></td>
                                    <td class="numeric"><?= number_format((float)$row['metric_value'], 2) ?>%</td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$isHeat): ?>
                <p class="rank-result-note">Note: Rainfall is the total precipitation recorded for the game date, not necessarily rainfall during game time.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<script>
// Show only the threshold selector that matches the selected analysis type.
(function () {
    const typeSelect = document.getElementById('analysis_type');
    const heatGroup = document.getElementById('heat-threshold-group');
    const rainGroup = document.getElementById('rain-threshold-group');

    function updateThresholdFields() {
        const isHeat = typeSelect.value === 'heat';
        heatGroup.style.display = isHeat ? '' : 'none';
        rainGroup.style.display = isHeat ? 'none' : '';
    }

    if (typeSelect && heatGroup && rainGroup) {
        typeSelect.addEventListener('change', updateThresholdFields);
        updateThresholdFields();
    }
})();
</script>

</main>
</body>
</html>
