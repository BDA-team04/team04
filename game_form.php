<?php
// 작성자: 정윤아
// 경기 입력(INSERT) / 수정(UPDATE)
// 저장은 트랜잭션: game → team_game_stats(홈·원정 2행)를 한 번에 저장하고, 하나라도 실패하면 전부 취소한다.
require_once 'session.php';
require_once 'db.php';

require_login();   // 로그인한 사용자만

const STATUSES  = ['scheduled', 'completed', 'cancelled', 'postponed'];
const MAX_STAT  = 99;   // 득점·안타·실책·볼넷 입력 상한

/* ---------- 도우미 함수 ---------- */

// 내 점수와 상대 점수로 W/L/D 계산 (사용자가 입력하지 않고 서버에서 계산)
function calc_result(int $mine, int $theirs): string
{
    if ($mine > $theirs) return 'W';
    if ($mine < $theirs) return 'L';
    return 'D';
}

// 0~99 정수인지 확인. 비어 있고 $optional 이면 null 을 돌려준다.
//   잘못된 값이면 false
function parse_stat(string $raw, bool $optional)
{
    $raw = trim($raw);
    if ($raw === '') {
        return $optional ? null : false;
    }
    if (!ctype_digit($raw) || (int)$raw > MAX_STAT) {
        return false;
    }
    return (int)$raw;
}

// 기존 경기 + 기록 불러오기 (수정 화면용). 없으면 null
function load_game(int $game_id): ?array
{
    $game = db_query_one('SELECT * FROM game WHERE game_id = ?', 'i', [$game_id]);
    if (!$game) {
        return null;
    }
    $stats = db_query('SELECT * FROM team_game_stats WHERE game_id = ?', 'i', [$game_id]);
    foreach ($stats as $s) {
        $side = $s['is_home'] ? 'home' : 'away';
        foreach (['runs', 'hits', 'errors', 'walks'] as $col) {
            $game["{$side}_{$col}"] = $s[$col] === null ? '' : (string)$s[$col];
        }
    }
    return $game;
}

// 해당 구장 도시·날짜의 날씨 데이터가 있는지
function has_weather(int $stadium_id, string $date): bool
{
    return (bool)db_query_one(
        'SELECT w.weather_id FROM stadium s JOIN weather w ON w.city = s.city AND w.obs_date = ? WHERE s.stadium_id = ?',
        'si',
        [$date, $stadium_id]
    );
}

/* ---------- 입력 검증 ---------- */

// 폼 값 → [정리된 값 배열, 오류 메시지 배열]
function validate_game(array $in): array
{
    $errors = [];
    $v = [
        'game_date'    => trim($in['game_date'] ?? ''),
        'stadium_id'   => (int)($in['stadium_id'] ?? 0),
        'home_team_id' => (int)($in['home_team_id'] ?? 0),
        'away_team_id' => (int)($in['away_team_id'] ?? 0),
        'game_no'      => (int)($in['game_no'] ?? 1),
        'start_time'   => trim($in['start_time'] ?? ''),
        'game_status'  => $in['game_status'] ?? '',
    ];

    $d = DateTime::createFromFormat('Y-m-d', $v['game_date']);
    if (!$d || $d->format('Y-m-d') !== $v['game_date']) {
        $errors[] = 'Please enter a valid date.';
    }
    if (!db_query_one('SELECT stadium_id FROM stadium WHERE stadium_id = ?', 'i', [$v['stadium_id']])) {
        $errors[] = 'Please choose a stadium.';
    }
    foreach (['home_team_id' => 'home', 'away_team_id' => 'away'] as $key => $label) {
        if (!db_query_one('SELECT team_id FROM team WHERE team_id = ?', 'i', [$v[$key]])) {
            $errors[] = "Please choose the {$label} team.";
        }
    }
    if ($v['home_team_id'] > 0 && $v['home_team_id'] === $v['away_team_id']) {
        $errors[] = 'Home and away teams must be different.';
    }
    if ($v['game_no'] < 1 || $v['game_no'] > 2) {
        $errors[] = 'Game number must be 1 or 2 (doubleheader).';
    }
    if ($v['start_time'] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v['start_time'])) {
        $errors[] = 'Please enter a valid start time.';
    }
    if (!in_array($v['game_status'], STATUSES, true)) {
        $errors[] = 'Please choose a game status.';
    }

    // 완료 경기만 기록을 받는다. 득점·안타는 필수, 실책·볼넷은 비워도 됨(NULL)
    if ($v['game_status'] === 'completed') {
        foreach (['home', 'away'] as $side) {
            foreach (['runs' => false, 'hits' => false, 'errors' => true, 'walks' => true] as $col => $optional) {
                $parsed = parse_stat((string)($in["{$side}_{$col}"] ?? ''), $optional);
                if ($parsed === false) {
                    $errors[] = ucfirst($side) . " {$col}: enter a whole number from 0 to " . MAX_STAT . ($optional ? ' (or leave blank)' : '') . '.';
                }
                $v["{$side}_{$col}"] = $parsed;
            }
        }
    }

    return [$v, $errors];
}

/* ---------- 저장 (트랜잭션) ---------- */

// 저장 순서: (수정이면) 기존 기록 삭제 → game INSERT/UPDATE → (완료 경기면) 기록 2행 INSERT
// 중간에 하나라도 실패하면 rollback 으로 처음 상태로 되돌린다.
// 반환: 저장된 game_id
function save_game(?int $game_id, array $v): int
{
    $conn = db();
    try {
        $conn->begin_transaction();

        $game_params = [$v['game_date'], $v['stadium_id'], $v['home_team_id'], $v['away_team_id'],
                        $v['game_no'], $v['game_status'], $v['start_time'] === '' ? null : $v['start_time']];

        if ($game_id === null) {
            $r = db_execute(
                'INSERT INTO game (game_date, stadium_id, home_team_id, away_team_id, game_no, game_status, start_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                'siiiiss', $game_params
            );
            $game_id = $r['insert_id'];
        } else {
            // 상태가 바뀌거나 팀이 바뀔 수 있으므로 기존 기록은 지우고 아래에서 새로 만든다.
            db_execute('DELETE FROM team_game_stats WHERE game_id = ?', 'i', [$game_id]);
            db_execute(
                'UPDATE game SET game_date = ?, stadium_id = ?, home_team_id = ?, away_team_id = ?,
                                 game_no = ?, game_status = ?, start_time = ?
                  WHERE game_id = ?',
                'siiiissi', array_merge($game_params, [$game_id])
            );
        }

        // 완료 경기만 홈·원정 기록 2행 저장 (is_home, result 는 서버에서 계산)
        if ($v['game_status'] === 'completed') {
            $sides = [
                ['team' => $v['home_team_id'], 'is_home' => 1, 'me' => 'home', 'opp' => 'away'],
                ['team' => $v['away_team_id'], 'is_home' => 0, 'me' => 'away', 'opp' => 'home'],
            ];
            foreach ($sides as $s) {
                db_execute(
                    'INSERT INTO team_game_stats (game_id, team_id, is_home, runs, hits, errors, walks, result)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    'iiiiiiis',
                    [$game_id, $s['team'], $s['is_home'],
                     $v["{$s['me']}_runs"], $v["{$s['me']}_hits"], $v["{$s['me']}_errors"], $v["{$s['me']}_walks"],
                     calc_result($v["{$s['me']}_runs"], $v["{$s['opp']}_runs"])]
                );
            }
        }

        $conn->commit();
        return $game_id;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();   // 지금까지 한 INSERT/UPDATE/DELETE 전부 취소
        throw $e;
    }
}

/* ---------- 요청 처리 ---------- */

$game_id = isset($_GET['id']) ? (int)$_GET['id'] : null;   // id 가 있으면 수정 모드
$errors  = [];

// 폼 기본값 (새 경기)
$form = ['game_date' => '', 'stadium_id' => 0, 'home_team_id' => 0, 'away_team_id' => 0, 'game_no' => 1,
         'start_time' => '18:30', 'game_status' => 'scheduled'];
foreach (['home', 'away'] as $side) {
    foreach (['runs', 'hits', 'errors', 'walks'] as $col) {
        $form["{$side}_{$col}"] = '';
    }
}

if ($game_id !== null) {
    $loaded = load_game($game_id);
    if (!$loaded) {
        set_flash('error', 'The game was not found.');
        header('Location: game_manage.php');
        exit;
    }
    $form = array_merge($form, $loaded);
    $form['start_time'] = $form['start_time'] === null ? '' : substr($form['start_time'], 0, 5);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$v, $errors] = validate_game($_POST);

    if (!$errors) {
        try {
            $saved_id = save_game($game_id, $v);
            set_flash('success', ($game_id === null ? 'Game added' : 'Game updated') . " (#{$saved_id}).");
            if (!has_weather($v['stadium_id'], $v['game_date'])) {
                set_flash('warning', 'No weather data exists for this city and date, so this game will be excluded from the analysis.');
            }
            header('Location: game_manage.php');
            exit;
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {   // UNIQUE 위반: 날짜·구장·홈·원정·더블헤더 번호가 같은 경기가 이미 있음
                $errors[] = 'The same game (date, stadium, teams, game number) already exists. Nothing was saved (rolled back).';
            } else {
                $errors[] = 'Save failed and was rolled back: ' . $e->getMessage();
            }
        }
    }
    // 실패하면 사용자가 입력한 값을 그대로 다시 보여준다.
    foreach ($form as $key => $_) {
        if (isset($_POST[$key])) {
            $form[$key] = is_string($_POST[$key]) ? $_POST[$key] : '';
        }
    }
}

$stadiums = db_query('SELECT stadium_id, stadium_name, city FROM stadium ORDER BY stadium_name');
$teams    = db_query('SELECT team_id, team_name FROM team ORDER BY team_name');
$is_edit  = $game_id !== null;

require_once 'header.php';
?>

<section class="intro">
    <h1 class="title"><?= $is_edit ? 'Edit Game #' . (int)$game_id : 'Add Game' ?></h1>
    <p class="content">Team stats are saved only for completed games. Win/loss is calculated automatically from the runs.</p>
</section>

<section class="auth-card game-form-card">
    <?php if ($errors): ?>
        <ul class="form-error">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="game_form.php<?= $is_edit ? '?id=' . (int)$game_id : '' ?>" class="auth-form">
        <div class="form-row">
            <label for="game_date">Date</label>
            <input type="date" id="game_date" name="game_date" value="<?= h($form['game_date']) ?>" required>
        </div>

        <div class="form-row">
            <label for="stadium_id">Stadium</label>
            <select id="stadium_id" name="stadium_id" required>
                <option value="">-- Select a stadium --</option>
                <?php foreach ($stadiums as $s): ?>
                    <option value="<?= (int)$s['stadium_id'] ?>" <?= (int)$s['stadium_id'] === (int)$form['stadium_id'] ? 'selected' : '' ?>>
                        <?= h($s['stadium_name']) ?> (<?= h($s['city']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php foreach (['home_team_id' => 'Home Team', 'away_team_id' => 'Away Team'] as $key => $label): ?>
            <div class="form-row">
                <label for="<?= $key ?>"><?= $label ?></label>
                <select id="<?= $key ?>" name="<?= $key ?>" required>
                    <option value="">-- Select a team --</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= (int)$t['team_id'] ?>" <?= (int)$t['team_id'] === (int)$form[$key] ? 'selected' : '' ?>><?= h($t['team_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <div class="form-row">
            <label for="game_no">Game No. (doubleheader)</label>
            <input type="number" id="game_no" name="game_no" min="1" max="2" value="<?= (int)$form['game_no'] ?>" required>
        </div>

        <div class="form-row">
            <label for="start_time">Start Time (optional)</label>
            <input type="time" id="start_time" name="start_time" value="<?= h($form['start_time']) ?>">
        </div>

        <div class="form-row">
            <span>Status</span>
            <?php foreach (STATUSES as $st): ?>
                <label>
                    <input type="radio" name="game_status" value="<?= $st ?>" <?= $form['game_status'] === $st ? 'checked' : '' ?> required>
                    <?= ucfirst($st) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <fieldset id="stats-box">
            <legend>Team Stats (completed games only)</legend>
            <table class="analysis-page-table">
                <thead><tr><th></th><th>Runs</th><th>Hits</th><th>Errors</th><th>Walks</th></tr></thead>
                <tbody>
                <?php foreach (['home' => 'Home', 'away' => 'Away'] as $side => $label): ?>
                    <tr>
                        <th><?= $label ?></th>
                        <?php foreach (['runs', 'hits', 'errors', 'walks'] as $col): ?>
                            <td>
                                <input type="number" name="<?= "{$side}_{$col}" ?>" min="0" max="<?= MAX_STAT ?>"
                                       value="<?= h($form["{$side}_{$col}"]) ?>"
                                       <?= in_array($col, ['errors', 'walks'], true) ? 'placeholder="optional"' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>

        <button type="submit" class="btn-primary"><?= $is_edit ? 'Save Changes' : 'Add Game' ?></button>
        <a href="game_manage.php" class="analysis-page-reset">Cancel</a>
    </form>
</section>

<script>
// 상태가 completed 가 아니면 기록 입력칸을 비활성화 (서버에서도 completed 일 때만 저장함)
(function () {
    var box = document.getElementById('stats-box');
    function update() {
        var checked = document.querySelector('input[name="game_status"]:checked');
        box.disabled = !checked || checked.value !== 'completed';
    }
    document.querySelectorAll('input[name="game_status"]').forEach(function (r) {
        r.addEventListener('change', update);
    });
    update();
})();
</script>

</main>
</body>
</html>
