<?php
// 작성자: 조혜림
// Windowing Analysis

// 세션, DB 연결
require_once 'session.php';
require_once 'db.php';

// 사용자 입력값 받기
$window_size = filter_input(INPUT_GET, 'window_size', FILTER_VALIDATE_INT);
$start_date = $_GET['start_date'] ?? '2020-05-05';
$end_date = $_GET['end_date'] ?? '2026-10-12';

// team_id 직접 선택하면 적용
if (isset($_GET['team_id'])) {
    $team_id = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT);
} else {
    // favorite_team_id 적용
    $user = current_user();
    $team_id = isset($user['favorite_team_id'])
        ? (int)$user['favorite_team_id']
        : null;
}

// 경기 수는 5, 10, 15, 20 중 하나만 허용
if (!in_array($window_size, [5, 10, 15, 20], true)) {
    $window_size = 5;
}

// 기간 선택 (2020~2026)
function validAnalysisDate($date) {
    if (!is_string($date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && $date >= '2020-05-05'
        && $date <= '2026-10-12';
}

if (!validAnalysisDate($start_date)) {
    $start_date = '2020-05-05';
}

if (!validAnalysisDate($end_date)) {
    $end_date = '2026-10-12';
}

if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

// SQL의 이전 경기 수
$preceding = $window_size - 1;

$teams = [];
$results = [];
$error = null;

try {
    // 팀 목록 조회
    $teams = db_query("
        SELECT team_id, team_name
        FROM team
        ORDER BY team_name
        ");

    // 선택한 팀이 실제 존재하는지 확인
    $valid_ids = array_map(
        'intval',
        array_column($teams, 'team_id')
    );

    if (!in_array($team_id, $valid_ids, true)) {
        $team_id = $valid_ids[0] ?? null;
    }

    // 이동평균 SQL
    if ($team_id !== null) {
        $sql = "
            wITH window_data AS (
            SELECT
                g.game_id,
                g.game_date,
                g.game_no,
                s.team_id,
                s.runs,
                w.avg_temp_c,

                opponent.team_name As opponent_name,
                
                ROUND(
                    AVG(s.runs) OVER (
                        PARTITION BY s.team_id, YEAR(g.game_date)
                        ORDER BY g.game_date, g.game_no, g.game_id
                        ROWS BETWEEN
                            $preceding PRECEDING AND CURRENT ROW
                    ), 2
                ) As moving_avg_runs,

                ROUND(
                    AVG(w.avg_temp_c) OVER (
                        PARTITION BY s.team_id, YEAR(g.game_date)
                        ORDER BY g.game_date, g.game_no, g.game_id
                        ROWS BETWEEN
                            $preceding PRECEDING AND CURRENT ROW
                    ), 2
                ) AS moving_avg_temp
            
            FROM team_game_stats AS s
            
            JOIN game AS g
                ON s.game_id = g.game_id
            
            JOIN stadium AS st
                ON g.stadium_id = st.stadium_id
            
            LEFT JOIN weather AS w
                ON st.city = w.city
                AND g.game_date = w.obs_date
            
            JOIN team AS opponent
                ON opponent.team_id =
                    CASE
                        WHEN s.team_id = g.home_team_id
                            THEN g.away_team_id
                        ELSE g.home_team_id
                    END
            
            WHERE g.game_status = 'completed'
                AND s.team_id = ?
            
            )

            SELECT *
            FROM window_data
            WHERE game_date BETWEEN ? AND ?
            ORDER BY game_date, game_no, game_id
        ";

        // SQL 실행
        $results = db_query($sql, 'iss', [$team_id, $start_date, $end_date]);
    }
} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    $error = "Failed to load analysis data.";
}

// 네비게이션
require_once 'header.php';
?>

<!-- 메인 제목 -->
<section class="intro">
    <h1 class="title">Windowing</h1>
    <p class="content">
        Track moving average of team performance over recent games.
    </p>
</section>

<!-- Windowing 분석 영역 -->
<section class="analysis-page-layout">
    <!-- 왼쪽 영역: 조건 설정 -->
    <div class="filter-panel">
        <h2>Filters</h2>

        <form method="GET" action="analysis_window.php"
            class="analysis-page-form">
        
            <!-- 팀 선택 -->
            <label for="team_id">Team</label>
            <select name="team_id" id="team_id">
                <?php foreach ($teams as $team): ?>

                    <option
                        value="<?= (int)$team['team_id'] ?>"
                        <?= (int)$team['team_id'] === $team_id
                            ? 'selected': '' ?>
                    >

                        <?= htmlspecialchars(
                            $team['team_name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- 경기 수 선택 -->
            <label class="analysis-page-size-label">Window Size</label>
            <div class="analysis-page-options">
                <?php foreach ([5, 10, 15, 20] as $n): ?>
                    <label>
                        <input
                            type="radio"
                            name="window_size"
                            value="<?= $n ?>"
                            <?= $window_size === $n ? 'checked' : '' ?>
                        >
                        <?= $n ?> Games
                    </label>
                <?php endforeach; ?>

            </div>

            <!-- 기간 선택 -->
            <label class = "analysis-page-size-label">Period</label>
            <div class="analysis-page-date-range">
                <input
                    type="date"
                    name="start_date"
                    min="2020-05-05"
                    max="2026-10-12"
                    value="<?= htmlspecialchars($start_date) ?>"
                    required>
                <span>~</span>
                <input
                    type="date"
                    name="end_date"
                    min="2020-05-05"
                    max="2026-10-12"
                    value="<?= htmlspecialchars($end_date) ?>"
                    required>
            </div>
            <!-- 분석 버튼 -->
            <button type="submit" class="analysis-page-submit">Analyze</button>
        </form>
    </div>

    <!-- 오른쪽 영역: 분석 결과 -->
    <div class="result-panel">
        <h2>Analysis Results</h2>

        <?php if ($error): ?>
            <p class="analysis-page-message">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php elseif (empty($results)): ?>
            <p class="analysis-page-message">
                No game data found.
            </p> 
        <?php else: ?>
            <!-- 그래프 제목 -->
            <h3 class="analysis-page-chart-title">
                Moving Average of Runs & Temperature
            </h3>
            
            <!-- 그래프 -->
            <div class="analysis-page-chart">
                <canvas id="windowChart"></canvas>
            </div>

            <!-- 결과 테이블 -->
            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Opponent</th>
                            <th>Runs</th>
                            <th>Moving Avg. Temp. (°C)</th>
                            <th>Moving Avg. Runs</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars(
                                        $row['game_date'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                                
                                <td>
                                    <?= htmlspecialchars(
                                        $row['opponent_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= (int)$row['runs'] ?>
                                </td>

                                <td>
                                    <?= $row['moving_avg_temp'] !== null
                                        ? number_format(
                                            (float)$row['moving_avg_temp'], 2
                                            )
                                        : 'N/A' ?>
                                </td>

                                <td>
                                    <?= number_format(
                                        (float)$row['moving_avg_runs'], 2
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</section>

<?php if (!$error && !empty($results)): ?>

<!-- Chart.js 라이브러리 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const gameData = <?= json_encode(
        $results,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    // X축: 경기 날짜
    const labels = gameData.map(row => row.game_date);

    // 이동평균 득점
    const movingRuns = gameData.map(
        row => Number(row.moving_avg_runs)
    );

    // 최근 N경기 이동평균 기온
    const temperatures = gameData.map(
        row => row.moving_avg_temp === null
            ? null
            : Number(row.moving_avg_temp)
    );

    // 그래프 생성
    new Chart(document.getElementById('windowChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Moving Average Runs',
                    data: movingRuns,
                    borderColor: '#002063',
                    backgroundColor: '#002063',
                    yAxisID: 'yRuns',
                    tension: 0.3,
                    pointRadius: 2
                },
                {
                    label: 'Moving Average Temperature (°C)',
                    data: temperatures,
                    borderColor: "#E53935",
                    backgroundColor: '#E53935',
                    yAxisID: 'yTemp',
                    tension: 0.3,
                    pointRadius: 2,
                    spanGaps: false
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            interaction: {
                mode: 'index',
                intersect: false
            },

            scales: {
                yRuns: {
                    type: 'linear',
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Runs'
                    },
                    beginAtZero: true
                },
                yTemp: {
                    type: 'linear',
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Temperature (°C)'
                    }
                }
            }
        }
    });
</script>

<?php endif; ?>

</main>
</body>
</html>