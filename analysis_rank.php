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

// Analyze 혹은 Reset Filter 버튼을 눌렀는지 확인
$is_submit = isset($_GET['submit']);
$is_reset = isset($_GET['reset']);

// Reset이면 기본 조건, Analyze면 제출값, 그 외에는 저장된 조건 불러오기
$raw = $is_reset
    ? $default
    : ($is_submit ? $_GET : load_filter($page, $default));

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

    // Analyze 또는 Reset Filter를 누르면 검증된 조건을 세션에 저장
    if ($is_submit || $is_reset) {
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


$runsChartData = array_map(static function ($row) {
    return [
        'label' => ($row['runs_ranking'] === null ? '—' : '#' . (int)$row['runs_ranking'])
            . ' ' . (string)$row['team_name'],
        'value' => $row['avg_runs'] === null ? null : (float)$row['avg_runs'],
    ];
}, $rows);

$winChartData = array_map(static function ($row) {
    return [
        'label' => ($row['win_ranking'] === null ? '—' : '#' . (int)$row['win_ranking'])
            . ' ' . (string)$row['team_name'],
        'value' => $row['win_rate'] === null ? null : (float)$row['win_rate'],
    ];
}, $winRows);

require_once __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="analysis_rank.css">

<?php show_flash(); ?>

<section class="intro">
    <h1 class="title">Ranking</h1>
    <p class="content">Compare team average runs and win rates on hot days and rainy days.</p>
</section>

<section class="analysis-page-layout rank-page">
    <div class="filter-panel">
        <h2>Filters</h2>

        <form method="GET" action="analysis_rank.php" class="analysis-page-form">
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

            <label
                for="heat_threshold"
                id="heat-threshold-group"
                class="analysis-page-size-label"
                <?= $isHeat ? '' : 'hidden' ?>
            >
                Hot-day threshold (daily maximum temperature)
                <select name="heat_threshold" id="heat_threshold">
                    <?php foreach ($heatThresholdOptions as $value): ?>
                        <option value="<?= h($value) ?>" <?= $heatThreshold === $value ? 'selected' : '' ?>>
                            <?= h($value) ?>°C or higher
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label
                for="rain_threshold"
                id="rain-threshold-group"
                class="analysis-page-size-label"
                <?= !$isHeat ? '' : 'hidden' ?>
            >
                Rainy-day threshold (daily precipitation)
                <select name="rain_threshold" id="rain_threshold">
                    <?php foreach ($rainThresholdOptions as $value): ?>
                        <option value="<?= h($value) ?>" <?= $rainThreshold === $value ? 'selected' : '' ?>>
                            <?= h($value) ?> mm or higher
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="analysis-page-options">
                <label>
                    <input type="checkbox" name="exclude_dome" value="1" <?= $excludeDome ? 'checked' : '' ?>>
                    Exclude dome stadiums
                </label>
            </div>

            <p class="analysis-page-message">
                Dome stadiums are excluded by default because they are less exposed to outdoor weather.
            </p>

            <button type="submit" name="submit" value="1" class="analysis-page-submit">
                Analyze
            </button>

            <button
                type="submit"
                name="reset"
                value="1"
                class="analysis-page-reset"
                formnovalidate
            >
                Reset filters
            </button>
        </form>
    </div>

    <div class="result-panel">
        <h2>Analysis Results</h2>

        <?php if ($error): ?>
            <p class="analysis-page-message"><?= h($error) ?></p>
        <?php elseif (!$rows): ?>
            <p class="analysis-page-message">
                No data matched these conditions. Please check the imported game and weather data.
            </p>
        <?php else: ?>
            <p class="analysis-page-message">
                <strong><?= h($conditionTitle) ?></strong><br>
                <?= h($thresholdDescription) ?>:
                <strong><?= h($thresholdValue) ?></strong>
                · Season:
                <strong><?= $season === 'all' ? 'All available seasons' : h($season) ?></strong>
                · Dome stadiums:
                <strong><?= $excludeDome ? 'Excluded' : 'Included' ?></strong>
            </p>

            <div class="rank-chart-header">
                <h3 class="analysis-page-chart-title" id="rank-chart-title">
                    Team Average Runs Ranking
                </h3>

                <div class="rank-metric-toggle" role="group" aria-label="Choose ranking metric">
                    <button type="button" class="rank-metric-button is-active"
                            data-metric="runs" aria-pressed="true">
                        Average Runs
                    </button>
                    <button type="button" class="rank-metric-button"
                            data-metric="win" aria-pressed="false">
                        Win Rate
                    </button>
                </div>
            </div>

            <div class="analysis-page-chart rank-chart-area">
                <canvas id="rankChart"></canvas>
            </div>

            <p class="analysis-page-message" id="rank-chart-note">
                Average runs per team-game. Higher values are ranked first.
            </p>

            <h3 class="analysis-page-chart-title">Team Performance Summary</h3>
            <p class="analysis-page-message" id="rank-table-sort-note">
                Sorted by average runs · each metric has its own rank.
            </p>

            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Games</th>
                            <th>Average Runs</th>
                            <th>Win Rate</th>
                            <th>Record (W-L-D)</th>
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
                                <td><?= number_format((int)$row['game_count']) ?></td>
                                <td>
                                    <span class="rank-metric-cell">
                                        <strong class="rank-position"><?= $row['runs_ranking'] === null ? '—' : '#' . (int)$row['runs_ranking'] ?></strong>
                                        <span class="rank-value"><?= $row['avg_runs'] === null ? 'N/A' : number_format((float)$row['avg_runs'], 2) ?></span>
                                    </span>
                                </td>
                                <td>
                                    <span class="rank-metric-cell">
                                        <strong class="rank-position"><?= $row['win_ranking'] === null ? '—' : '#' . (int)$row['win_ranking'] ?></strong>
                                        <span class="rank-value"><?= $row['win_rate'] === null ? 'N/A' : number_format((float)$row['win_rate'], 2) . '%' ?></span>
                                    </span>
                                </td>
                                <td>
                                    <?= number_format((int)$row['wins']) ?>-<?= number_format((int)$row['losses']) ?>-<?= number_format((int)$row['draws']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="analysis-page-message">
                Record is shown as wins-losses-draws (W-L-D). Draws are excluded from the win-rate denominator.
            </p>
            <p class="analysis-page-message">
                Note: Weather is matched to the game date and stadium city. Rainfall is the daily total,
                not necessarily rainfall during game time.
            </p>
        <?php endif; ?>
    </div>
</section>

<?php if (!$error && !empty($rows)): ?>
<script src="js/chart.umd.min.js"></script>
<script>
(function () {
    const runsData = <?= json_encode(
        $runsChartData,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const winData = <?= json_encode(
        $winChartData,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const chartTitle = document.getElementById('rank-chart-title');
    const chartNote = document.getElementById('rank-chart-note');
    const tableBody = document.getElementById('rank-summary-body');
    const tableSortNote = document.getElementById('rank-table-sort-note');
    const metricButtons = document.querySelectorAll('.rank-metric-button');
    const chartCanvas = document.getElementById('rankChart');

    let rankChart = null;

    if (chartCanvas && window.Chart) {
        rankChart = new Chart(chartCanvas, {
            type: 'bar',
            data: {
                labels: runsData.map(item => item.label),
                datasets: [{
                    label: 'Average Runs',
                    data: runsData.map(item => item.value),
                    backgroundColor: '#002063'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Average Runs'
                        }
                    },
                    y: {
                        ticks: {
                            autoSkip: false,
                            align: 'start',
                            crossAlign: 'far'
                        }
                    }
                }
            }
        });
    }

    function setMetric(metric) {
        const showRuns = metric === 'runs';
        const selectedData = showRuns ? runsData : winData;

        if (chartTitle) {
            chartTitle.textContent = showRuns
                ? 'Team Average Runs Ranking'
                : 'Team Win Rate Ranking';
        }

        if (chartNote) {
            chartNote.textContent = showRuns
                ? 'Average runs per team-game. Higher values are ranked first.'
                : 'Win rate = wins ÷ (wins + losses). Draws are excluded from the denominator.';
        }

        if (tableSortNote) {
            tableSortNote.textContent = showRuns
                ? 'Sorted by average runs · each metric has its own rank.'
                : 'Sorted by win rate · each metric has its own rank.';
        }

        if (rankChart) {
            rankChart.data.labels = selectedData.map(item => item.label);
            rankChart.data.datasets[0].label = showRuns ? 'Average Runs' : 'Win Rate';
            rankChart.data.datasets[0].data = selectedData.map(item => item.value);
            rankChart.options.scales.x.max = showRuns ? undefined : 100;
            rankChart.options.scales.x.title.text = showRuns
                ? 'Average Runs'
                : 'Win Rate (%)';
            rankChart.options.scales.x.ticks.callback = function (value) {
                return showRuns ? value : value + '%';
            };
            rankChart.update();
        }

        metricButtons.forEach(function (button) {
            const isActive = button.dataset.metric === metric;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        if (tableBody) {
            const tableRows = Array.from(tableBody.querySelectorAll('tr'));
            const rankKey = showRuns ? 'runsRank' : 'winRank';

            tableRows.sort(function (a, b) {
                const rankA = Number(a.dataset[rankKey] || 999999);
                const rankB = Number(b.dataset[rankKey] || 999999);

                if (rankA !== rankB) {
                    return rankA - rankB;
                }

                return (a.dataset.team || '').localeCompare(b.dataset.team || '');
            });

            tableRows.forEach(function (row) {
                tableBody.appendChild(row);
            });
        }
    }

    metricButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setMetric(button.dataset.metric);
        });
    });

    setMetric('runs');
})();
</script>
<?php endif; ?>

<script>
(function () {
    const typeSelect = document.getElementById('analysis_type');
    const heatGroup = document.getElementById('heat-threshold-group');
    const rainGroup = document.getElementById('rain-threshold-group');

    function updateThresholdFields() {
        if (!typeSelect || !heatGroup || !rainGroup) {
            return;
        }

        const isHeat = typeSelect.value === 'heat';
        heatGroup.hidden = !isHeat;
        rainGroup.hidden = isHeat;
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
