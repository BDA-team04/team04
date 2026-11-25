<?php
// 작성자: 정윤아
// 경기 관리: 검색(SELECT) · 목록 · 삭제(DELETE, 트랜잭션)
// 검색 조건은 세션에 저장해서 다른 페이지에 다녀와도 유지된다.
require_once 'session.php';
require_once 'db.php';

const PAGE_KEY  = 'game_manage';
const PER_PAGE  = 30;
const STATUSES  = ['scheduled', 'completed', 'cancelled', 'postponed'];
const SORTS     = ['newest' => 'g.game_date DESC, g.game_no DESC', 'oldest' => 'g.game_date ASC, g.game_no ASC'];

/* ---------- 1. 삭제 (POST) ---------- */

// 경기 1건 삭제: 자식(team_game_stats) → 부모(game) 순서. 하나라도 실패하면 전체 취소.
function delete_game(int $game_id): bool
{
    $conn = db();
    try {
        $conn->begin_transaction();
        db_execute('DELETE FROM team_game_stats WHERE game_id = ?', 'i', [$game_id]);
        $r = db_execute('DELETE FROM game WHERE game_id = ?', 'i', [$game_id]);
        $conn->commit();
        return $r['affected_rows'] === 1;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_login();
    $game_id = (int)($_POST['game_id'] ?? 0);
    try {
        if (delete_game($game_id)) {
            set_flash('success', "Game #{$game_id} has been deleted.");
        } else {
            set_flash('error', 'The game was not found. It may already be deleted.');
        }
    } catch (mysqli_sql_exception $e) {
        set_flash('error', 'Delete failed and was rolled back: ' . $e->getMessage());
    }
    header('Location: game_manage.php');   // 새로고침해도 다시 삭제되지 않도록 이동
    exit;
}

/* ---------- 2. 검색 조건 (세션 저장/조회) ---------- */

$default = ['from' => '', 'to' => '', 'stadium_id' => 0, 'team_id' => 0, 'statuses' => STATUSES, 'sort' => 'newest'];

// 날짜 문자열이 YYYY-MM-DD 형식이면 그대로, 아니면 빈 문자열
function clean_date(string $s): string
{
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : '';
}

if (isset($_GET['search'])) {
    $statuses = array_values(array_intersect(STATUSES, (array)($_GET['statuses'] ?? [])));   // 허용 값만
    $filter = [
        'from'       => clean_date($_GET['from'] ?? ''),
        'to'         => clean_date($_GET['to'] ?? ''),
        'stadium_id' => (int)($_GET['stadium_id'] ?? 0),
        'team_id'    => (int)($_GET['team_id'] ?? 0),
        'statuses'   => $statuses,
        'sort'       => db_whitelist($_GET['sort'] ?? '', array_keys(SORTS), 'newest'),
    ];
    save_filter(PAGE_KEY, $filter);
} elseif (isset($_GET['reset'])) {
    clear_filter(PAGE_KEY);
    $filter = $default;
} else {
    $filter = load_filter(PAGE_KEY, $default);
}
$page = max(1, (int)($_GET['p'] ?? 1));

/* ---------- 3. 조건에 맞는 WHERE 절 만들기 (값은 모두 ? 로 바인딩) ---------- */

$where  = [];
$types  = '';
$params = [];

if ($filter['from'] !== '') { $where[] = 'g.game_date >= ?'; $types .= 's'; $params[] = $filter['from']; }
if ($filter['to'] !== '')   { $where[] = 'g.game_date <= ?'; $types .= 's'; $params[] = $filter['to']; }
if ($filter['stadium_id'] > 0) { $where[] = 'g.stadium_id = ?'; $types .= 'i'; $params[] = $filter['stadium_id']; }
if ($filter['team_id'] > 0) {
    $where[] = '(g.home_team_id = ? OR g.away_team_id = ?)';
    $types  .= 'ii';
    array_push($params, $filter['team_id'], $filter['team_id']);
}
if ($filter['statuses']) {
    $where[] = 'g.game_status IN (' . implode(',', array_fill(0, count($filter['statuses']), '?')) . ')';
    $types  .= str_repeat('s', count($filter['statuses']));
    array_push($params, ...$filter['statuses']);
} else {
    $where[] = '1 = 0';   // 상태를 하나도 고르지 않으면 결과 없음
}
$where_sql = 'WHERE ' . implode(' AND ', $where);
$order_sql = SORTS[$filter['sort']];   // 화이트리스트에서 고른 값만 SQL 에 들어감

/* ---------- 4. 목록 조회 ---------- */

$total = (int)db_query_one("SELECT COUNT(*) AS n FROM game g $where_sql", $types, $params)['n'];
$pages = max(1, (int)ceil($total / PER_PAGE));
$page  = min($page, $pages);

$games = db_query(
    "SELECT g.game_id, g.game_date, g.game_no, g.game_status, g.start_time,
            s.stadium_name, s.is_dome,
            home_t.short_name AS home_name, away_t.short_name AS away_name,
            home_s.runs AS home_runs, away_s.runs AS away_runs,
            (w.weather_id IS NOT NULL) AS has_weather
       FROM game g
       JOIN stadium s      ON s.stadium_id = g.stadium_id
       JOIN team home_t    ON home_t.team_id = g.home_team_id
       JOIN team away_t    ON away_t.team_id = g.away_team_id
       LEFT JOIN team_game_stats home_s ON home_s.game_id = g.game_id AND home_s.team_id = g.home_team_id
       LEFT JOIN team_game_stats away_s ON away_s.game_id = g.game_id AND away_s.team_id = g.away_team_id
       LEFT JOIN weather w ON w.city = s.city AND w.obs_date = g.game_date
       $where_sql
      ORDER BY $order_sql
      LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [PER_PAGE, ($page - 1) * PER_PAGE])
);

$stadiums  = db_query('SELECT stadium_id, stadium_name FROM stadium ORDER BY stadium_name');
$teams     = db_query('SELECT team_id, team_name FROM team ORDER BY team_name');
$logged_in = is_logged_in();

require_once 'header.php';
?>
<link rel="stylesheet" href="crud_auth.css">

<section class="intro">
    <h1 class="title">Game Data</h1>
    <p class="content">Search, add, edit, and delete KBO games. Your search conditions are kept while you browse other pages.</p>
</section>

<section class="analysis-page-layout crud-layout">
    <!-- 왼쪽: 검색 조건 -->
    <div class="filter-panel">
        <h2>Search</h2>
        <form method="get" action="game_manage.php" class="analysis-page-form">
            <label class="analysis-page-size-label" for="from">From</label>
            <input type="date" id="from" name="from" value="<?= h($filter['from']) ?>">

            <label class="analysis-page-size-label" for="to">To</label>
            <input type="date" id="to" name="to" value="<?= h($filter['to']) ?>">

            <label class="analysis-page-size-label" for="stadium_id">Stadium</label>
            <select id="stadium_id" name="stadium_id">
                <option value="0">All stadiums</option>
                <?php foreach ($stadiums as $s): ?>
                    <option value="<?= (int)$s['stadium_id'] ?>" <?= (int)$s['stadium_id'] === $filter['stadium_id'] ? 'selected' : '' ?>><?= h($s['stadium_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="analysis-page-size-label" for="team_id">Team (home or away)</label>
            <select id="team_id" name="team_id">
                <option value="0">All teams</option>
                <?php foreach ($teams as $t): ?>
                    <option value="<?= (int)$t['team_id'] ?>" <?= (int)$t['team_id'] === $filter['team_id'] ? 'selected' : '' ?>><?= h($t['team_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <span class="analysis-page-size-label crud-group-title">Status</span>
            <div class="analysis-page-options">
                <?php foreach (STATUSES as $st): ?>
                    <label>
                        <input type="checkbox" name="statuses[]" value="<?= $st ?>" <?= in_array($st, $filter['statuses'], true) ? 'checked' : '' ?>>
                        <?= ucfirst($st) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <span class="analysis-page-size-label crud-group-title">Sort</span>
            <div class="analysis-page-options">
                <label><input type="radio" name="sort" value="newest" <?= $filter['sort'] === 'newest' ? 'checked' : '' ?>> Newest first</label>
                <label><input type="radio" name="sort" value="oldest" <?= $filter['sort'] === 'oldest' ? 'checked' : '' ?>> Oldest first</label>
            </div>

            <div class="filter-buttons">
                <button type="submit" name="search" value="1" class="btn-primary btn-block">Search</button>
                <button type="submit" name="reset" value="1" class="btn-secondary btn-block" formnovalidate>Reset</button>
            </div>
        </form>
    </div>

    <!-- 오른쪽: 결과 목록 -->
    <div class="result-panel">
        <div class="crud-result-head">
            <h2>Games (<?= number_format($total) ?>)</h2>
            <?php if ($logged_in): ?>
                <a href="game_form.php" class="btn-primary">+ Add Game</a>
            <?php endif; ?>
        </div>
        <?php show_flash(); ?>

        <?php if (!$logged_in): ?>
            <p class="crud-login-hint">
                Log in to add, edit, or delete games.
                <a href="login.php" class="btn-secondary btn-sm">Log In</a>
            </p>
        <?php endif; ?>

        <?php if (!$games): ?>
            <p class="analysis-page-message">No games match the search conditions.</p>
        <?php else: ?>
            <div class="analysis-page-table-wrap">
                <table class="analysis-page-table">
                    <thead>
                        <tr>
                            <th>Date</th><th>Stadium</th><th>Home</th><th>Score</th><th>Away</th>
                            <th>Status</th><th>Weather</th>
                            <?php if ($logged_in): ?><th>Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($games as $g): ?>
                        <tr>
                            <td><?= h($g['game_date']) ?><?= (int)$g['game_no'] > 1 ? ' (G' . (int)$g['game_no'] . ')' : '' ?></td>
                            <td><?= h($g['stadium_name']) ?><?= $g['is_dome'] ? ' (dome)' : '' ?></td>
                            <td><?= h($g['home_name']) ?></td>
                            <td><?= $g['home_runs'] === null ? '-' : (int)$g['home_runs'] . ' : ' . (int)$g['away_runs'] ?></td>
                            <td><?= h($g['away_name']) ?></td>
                            <td><?= h(ucfirst($g['game_status'])) ?></td>
                            <td><?= $g['has_weather'] ? 'Yes' : 'No' ?></td>
                            <?php if ($logged_in): ?>
                                <td>
                                    <div class="crud-actions">
                                        <a href="game_form.php?id=<?= (int)$g['game_id'] ?>" class="btn-secondary btn-sm">Edit</a>
                                        <form method="post" action="game_manage.php"
                                              onsubmit="return confirm('Delete this game and its team stats?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="game_id" value="<?= (int)$g['game_id'] ?>">
                                            <button type="submit" class="btn-danger btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="game_manage.php?p=<?= $page - 1 ?>" class="btn-secondary btn-sm">&laquo; Prev</a><?php endif; ?>
                    <span>Page <?= $page ?> / <?= $pages ?></span>
                    <?php if ($page < $pages): ?><a href="game_manage.php?p=<?= $page + 1 ?>" class="btn-secondary btn-sm">Next &raquo;</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

</main>
</body>
</html>
