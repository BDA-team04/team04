<?php
// 작성자: 박수민
// Ranking: compare average runs and win rate for the same hot-day/rainy-day games.
// Test version only. Uses the shared DB/session helpers without modifying common files.

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$user = current_user();
// Keep this test page's filters separate from the original Ranking page.
$page = 'analysis_rank_new_' . ($user ? (int)$user['user_id'] : 'guest');

$default = [
    'analysis_type' => 'heat',
    'season' => 'all',
    'heat_threshold' => '33',
    'rain_threshold' => '1',
    'exclude_dome' => 1,
];

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
$winRows = [];
$error = null;

try {
    // Populate the season selector from seasons actually present in the database.
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

    if (isset($_GET['submit'])) {
        save_filter($page, [
            'analysis_type' => $analysisType,
            'season' => $season,
            'heat_threshold' => $heatThreshold,
            'rain_threshold' => $rainThreshold,
            'exclude_dome' => $excludeDome,
        ]);
    }

    // Each team_game_stats row represents one team's performance in one completed game.
    // Weather is matched by the stadium city and game date.
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

    // The weather filter selects the game set. Both metrics use exactly this same set.
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

    // Aggregate both measures together for each team.
    // Draws count as games and are included in average runs, but excluded from win-rate denominator.
    $aggregateSql = "
        SELECT
            t.team_id,
            t.team_name,
            COUNT(*) AS game_count,
            AVG(s.runs) AS avg_runs_raw,
            SUM(CASE WHEN s.result = 'W' THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN s.result = 'L' THEN 1 ELSE 0 END) AS losses,
            SUM(CASE WHEN s.result = 'D' THEN 1 ELSE 0 END) AS draws,
            SUM(CASE WHEN s.result IN ('W', 'L') THEN 1 ELSE 0 END) AS decided_games,
            100.0 * SUM(CASE WHEN s.result = 'W' THEN 1 ELSE 0 END)
                / NULLIF(SUM(CASE WHEN s.result IN ('W', 'L') THEN 1 ELSE 0 END), 0)
                AS win_rate_raw
        {$fromSql}
        GROUP BY t.team_id, t.team_name
    ";

    // Rank on unrounded values so display rounding does not create false ties.
    $sql = "
        SELECT
            CASE WHEN avg_runs_raw IS NULL THEN NULL
                 ELSE RANK() OVER (ORDER BY avg_runs_raw DESC) END AS runs_ranking,
            CASE WHEN win_rate_raw IS NULL THEN NULL
                 ELSE RANK() OVER (ORDER BY win_rate_raw DESC) END AS win_ranking,
            team_id,
            team_name,
            game_count,
            wins,
            losses,
            draws,
            decided_games,
            ROUND(avg_runs_raw, 2) AS avg_runs,
            ROUND(win_rate_raw, 2) AS win_rate
        FROM (
            {$aggregateSql}
        ) AS team_summary
        ORDER BY (avg_runs_raw IS NULL) ASC, runs_ranking ASC, team_name ASC
    ";

    $rows = db_query($sql, $types, $params);

    // The win-rate chart uses its own order; the summary table can switch order with the chart.
    $winRows = $rows;
    usort($winRows, function ($a, $b) {
        $rankA = ($a['win_ranking'] === null) ? PHP_INT_MAX : (int)$a['win_ranking'];
        $rankB = ($b['win_ranking'] === null) ? PHP_INT_MAX : (int)$b['win_ranking'];
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }
        return strcmp((string)$a['team_name'], (string)$b['team_name']);
    });
} catch (Throwable $e) {
    error_log('Ranking new: ' . $e->getMessage());
    $error = '분석 데이터를 불러오지 못했습니다. DB 연결, 테이블 및 데이터 입력 상태를 확인해 주세요.';
}

$isHeat = ($analysisType === 'heat');
$conditionTitle = $isHeat ? 'Hot Days' : 'Rainy Days';
$thresholdDescription = $isHeat ? 'Daily maximum temperature' : 'Daily precipitation';
$thresholdValue = $isHeat
    ? $heatThreshold . '°C or higher'
    : $rainThreshold . ' mm or higher';

$maxRuns = $rows
    ? max(1.0, ...array_map(fn($row) => (float)($row['avg_runs'] ?? 0), $rows))
    : 1.0;

require_once __DIR__ . '/header.php';
?>

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
    margin: 0 0 20px;
    padding: 13px 15px;
    border: 1px solid #dce4f1;
    border-radius: 8px;
    background: #f7f9fd;
    color: #34445f;
    font-size: 13px;
    line-height: 1.6;
}
.rank-chart-panel {
    margin: 0 0 24px;
    padding: 18px 18px 12px;
    border: 1px solid #e0e6f0;
    border-radius: 10px;
    background: #fff;
}
.rank-chart-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}
.rank-chart-heading h3 {
    margin: 0;
    color: #172b59;
    font-size: 17px;
}
.rank-metric-toggle {
    display: inline-flex;
    gap: 4px;
    padding: 4px;
    border: 1px solid #dce3ee;
    border-radius: 8px;
    background: #f4f6fa;
}
.rank-metric-toggle button {
    border: 0;
    border-radius: 6px;
    padding: 8px 11px;
    background: transparent;
    color: #52627a;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.rank-metric-toggle button.active {
    background: #16265f;
    color: #fff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .16);
}
.rank-chart-note {
    margin: 0 0 14px;
    color: #64748b;
    font-size: 12px;
    line-height: 1.5;
}
.rank-chart[hidden] { display: none !important; }
.rank-chart-row {
    display: grid;
    grid-template-columns: 38px 70px minmax(50px, 1fr) 72px;
    align-items: center;
    gap: 10px;
    margin: 12px 0;
    font-size: 13px;
}
.rank-chart-position { color: #64748b; text-align: right; }
.rank-chart-team { font-weight: 600; color: #263653; }
.rank-bar-track { height: 19px; background: #e7edf7; border-radius: 5px; overflow: hidden; }
.rank-bar { height: 100%; background: #244b8f; border-radius: 5px; }
.rank-chart-value { text-align: right; font-variant-numeric: tabular-nums; }
.rank-table-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    margin: 0 0 10px;
}
.rank-table-heading h3 { margin: 0; color: #172b59; font-size: 17px; }
.rank-table-heading p { margin: 0; color: #64748b; font-size: 11px; }
.analysis-page-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    max-height: 540px !important;
    overflow: auto !important;
    box-sizing: border-box !important;
    border: 1px solid #d7ddea !important;
    border-radius: 8px !important;
    background: white !important;
}
table.analysis-page-table {
    border-collapse: collapse !important;
    border-spacing: 0 !important;
    width: 100% !important;
    min-width: 620px !important;
    margin: 0 !important;
    font-size: 13px !important;
    line-height: 1.45 !important;
    color: #24334f !important;
}
.analysis-page-table th,
.analysis-page-table td {
    border: 0 !important;
    border-bottom: 1px solid #e7ebf3 !important;
    padding: 11px 12px !important;
    text-align: center !important;
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
.analysis-page-table th.numeric { text-align: center !important; }
.rank-metric-cell { text-align: center !important; }
.rank-metric-value { font-weight: 700; color: #1f3154; font-variant-numeric: tabular-nums; }
.rank-metric-position {
    display: inline-block;
    min-width: 25px;
    margin-right: 7px;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
}
.rank-wld { font-variant-numeric: tabular-nums; color: #52627a; }
.rank-empty, .rank-error {
    padding: 16px;
    border-radius: 8px;
    background: #f7f9fd;
    color: #52627a;
}
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
@media (max-width: 520px) {
    .rank-chart-panel { padding: 14px 12px 10px; }
    .rank-chart-row { grid-template-columns: 30px 52px minmax(40px, 1fr) 58px; gap: 7px; font-size: 12px; }
    .rank-metric-toggle button { padding: 8px 9px; }
}
</style>

<section class="intro">
    <h1 class="title">Ranking</h1>
    <p class="content">Compare team average runs and win rates on hot days and rainy days.</p>
</section>

<section class="analysis-page-layout">
    <div class="filter-panel">
        <h2>Filters</h2>
        <form method="get" action="analysis_rank.php" class="analysis-page-form">
            <label for="analysis_type">Weather condition</label>
            <select name="analysis_type" id="analysis_type">
                <option value="heat" <?= $analysisType === 'heat' ? 'selected' : '' ?>>Hot days</option>
                <option value="rain" <?= $analysisType === 'rain' ? 'selected' : '' ?>>Rainy days</option>
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
                <strong><?= h($conditionTitle) ?></strong><br>
                <?= h($thresholdDescription) ?>: <strong><?= h($thresholdValue) ?></strong>
                · Season: <strong><?= $season === 'all' ? 'All available seasons' : h($season) ?></strong>
                · Dome stadiums: <strong><?= $excludeDome ? 'Excluded' : 'Included' ?></strong>
            </div>

            <section class="rank-chart-panel" aria-label="Team ranking chart">
                <div class="rank-chart-heading">
                    <h3 id="rank-chart-title">Team Average Runs Ranking</h3>
                    <div class="rank-metric-toggle" role="group" aria-label="Choose ranking metric">
                        <button type="button" class="active" data-metric="runs" aria-pressed="true">Average Runs</button>
                        <button type="button" data-metric="win" aria-pressed="false">Win Rate</button>
                    </div>
                </div>

                <p class="rank-chart-note" id="rank-chart-note">Average runs per team-game. Higher values are ranked first.</p>

                <div class="rank-chart" id="rank-chart-runs">
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $runsValue = $row['avg_runs'] === null ? 0.0 : (float)$row['avg_runs'];
                            $runsWidth = min(100, max(0, $runsValue / $maxRuns * 100));
                        ?>
                        <div class="rank-chart-row">
                            <span class="rank-chart-position">
                                <?= $row['runs_ranking'] === null ? '—' : '#' . (int)$row['runs_ranking'] ?>
                            </span>
                            <span class="rank-chart-team"><?= h($row['team_name']) ?></span>
                            <div class="rank-bar-track">
                                <div class="rank-bar" style="width: <?= number_format($runsWidth, 1, '.', '') ?>%;"></div>
                            </div>
                            <span class="rank-chart-value">
                                <?= $row['avg_runs'] === null ? 'N/A' : number_format((float)$row['avg_runs'], 2) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="rank-chart" id="rank-chart-win" hidden>
                    <?php foreach ($winRows as $row): ?>
                        <?php
                            $winValue = $row['win_rate'] === null ? 0.0 : (float)$row['win_rate'];
                            // Win rate is a percentage, so the chart scale is always 0–100%.
                            $winWidth = min(100, max(0, $winValue));
                        ?>
                        <div class="rank-chart-row">
                            <span class="rank-chart-position">
                                <?= $row['win_ranking'] === null ? '—' : '#' . (int)$row['win_ranking'] ?>
                            </span>
                            <span class="rank-chart-team"><?= h($row['team_name']) ?></span>
                            <div class="rank-bar-track">
                                <div class="rank-bar" style="width: <?= number_format($winWidth, 1, '.', '') ?>%;"></div>
                            </div>
                            <span class="rank-chart-value">
                                <?= $row['win_rate'] === null ? 'N/A' : number_format((float)$row['win_rate'], 2) . '%' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="rank-table-section">
                <div class="rank-table-heading">
                    <h3>Team Performance Summary</h3>
                    <p id="rank-table-sort-note">Sorted by average runs · each metric has its own rank</p>
                </div>
                <div class="analysis-page-table-wrap">
                    <table class="analysis-page-table">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th class="numeric">Games</th>
                                <th class="numeric">Average Runs</th>
                                <th class="numeric">Win Rate</th>
                                <th class="numeric">W-L-D</th>
                            </tr>
                        </thead>
                        <tbody id="rank-summary-body">
                            <?php foreach ($rows as $row): ?>
                                <tr
                                    data-runs-rank="<?= $row['runs_ranking'] === null ? 999999 : (int)$row['runs_ranking'] ?>"
                                    data-win-rank="<?= $row['win_ranking'] === null ? 999999 : (int)$row['win_ranking'] ?>"
                                    data-team="<?= h($row['team_name']) ?>"
                                >
                                    <td><?= h($row['team_name']) ?></td>
                                    <td class="numeric"><?= number_format((int)$row['game_count']) ?></td>
                                    <td class="rank-metric-cell">
                                        <span class="rank-metric-position">
                                            <?= $row['runs_ranking'] === null ? '—' : '#' . (int)$row['runs_ranking'] ?>
                                        </span>
                                        <span class="rank-metric-value">
                                            <?= $row['avg_runs'] === null ? 'N/A' : number_format((float)$row['avg_runs'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="rank-metric-cell">
                                        <span class="rank-metric-position">
                                            <?= $row['win_ranking'] === null ? '—' : '#' . (int)$row['win_ranking'] ?>
                                        </span>
                                        <span class="rank-metric-value">
                                            <?= $row['win_rate'] === null ? 'N/A' : number_format((float)$row['win_rate'], 2) . '%' ?>
                                        </span>
                                    </td>
                                    <td class="numeric rank-wld">
                                        <?= number_format((int)$row['wins']) ?>-<?= number_format((int)$row['losses']) ?>-<?= number_format((int)$row['draws']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="rank-result-note">The table order follows the selected chart. W-L-D means wins-losses-draws. Draws are excluded from the win-rate denominator.</p>
            </section>

            <p class="rank-result-note">Note: Weather is matched to the game date and stadium city. Rainfall is the daily total, not necessarily rainfall during game time.</p>
        <?php endif; ?>
    </div>
</section>

<script>
// Switch the single visible chart and keep the summary table in the same ranking order.
(function () {
    const metricButtons = document.querySelectorAll('.rank-metric-toggle button');
    const runsChart = document.getElementById('rank-chart-runs');
    const winChart = document.getElementById('rank-chart-win');
    const chartTitle = document.getElementById('rank-chart-title');
    const chartNote = document.getElementById('rank-chart-note');
    const tableBody = document.getElementById('rank-summary-body');
    const tableSortNote = document.getElementById('rank-table-sort-note');

    function setMetric(metric) {
        const showRuns = metric === 'runs';

        if (runsChart && winChart) {
            runsChart.hidden = !showRuns;
            winChart.hidden = showRuns;
        }

        if (chartTitle) {
            chartTitle.textContent = showRuns ? 'Team Average Runs Ranking' : 'Team Win Rate Ranking';
        }
        if (chartNote) {
            chartNote.textContent = showRuns
                ? 'Average runs per team-game. Higher values are ranked first.'
                : 'Win rate = wins ÷ (wins + losses). Draws are excluded from the denominator.';
        }
        if (tableSortNote) {
            tableSortNote.textContent = showRuns
                ? 'Sorted by average runs · each metric has its own rank'
                : 'Sorted by win rate · each metric has its own rank';
        }

        metricButtons.forEach(function (button) {
            const active = button.dataset.metric === metric;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        // Reorder the one summary table to match the selected chart.
        if (tableBody) {
            const rows = Array.from(tableBody.querySelectorAll('tr'));
            const rankKey = showRuns ? 'runsRank' : 'winRank';
            rows.sort(function (a, b) {
                const rankA = Number(a.dataset[rankKey] || 999999);
                const rankB = Number(b.dataset[rankKey] || 999999);
                if (rankA !== rankB) return rankA - rankB;
                return (a.dataset.team || '').localeCompare(b.dataset.team || '');
            });
            rows.forEach(function (row) { tableBody.appendChild(row); });
        }
    }

    metricButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setMetric(button.dataset.metric);
        });
    });

    const typeSelect = document.getElementById('analysis_type');
    const heatGroup = document.getElementById('heat-threshold-group');
    const rainGroup = document.getElementById('rain-threshold-group');

    function updateThresholdFields() {
        if (!typeSelect || !heatGroup || !rainGroup) return;
        const isHeat = typeSelect.value === 'heat';
        heatGroup.style.display = isHeat ? '' : 'none';
        rainGroup.style.display = isHeat ? 'none' : '';
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', updateThresholdFields);
        updateThresholdFields();
    }
})();
</script>

</main>
</body>
</html>
