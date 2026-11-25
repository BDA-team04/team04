<?php
// 작성자: 정윤아
// 회원가입: 아이디, 비밀번호(2회 입력), 응원팀(선택 안 함 가능)
require_once 'session.php';
require_once 'db.php';

// 이미 로그인했으면 가입 화면이 필요 없다.
if (is_logged_in()) {
    header('Location: home.php');
    exit;
}

// 입력값 검사 → 오류 메시지 배열 (비어 있으면 통과)
function validate_register(string $username, string $password, string $password2, int $team_id): array
{
    $errors = [];

    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
        $errors[] = 'Username must be 3-30 characters (letters, numbers, underscore).';
    } elseif (db_query_one('SELECT user_id FROM users WHERE username = ?', 's', [$username])) {
        $errors[] = 'This username is already taken.';
    }

    if (strlen($password) < 8 || strlen($password) > 72) {   // bcrypt 는 72바이트까지만 사용
        $errors[] = 'Password must be 8-72 characters.';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    }

    // 0 = 선택 안 함. 그 외에는 team 테이블에 있는 ID 여야 한다.
    if ($team_id !== 0 && !db_query_one('SELECT team_id FROM team WHERE team_id = ?', 'i', [$team_id])) {
        $errors[] = 'Please choose a valid team.';
    }

    return $errors;
}

$teams    = db_query('SELECT team_id, team_name FROM team ORDER BY team_name');
$errors   = [];
$username = '';
$team_id  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirm'] ?? '';
    $team_id   = (int)($_POST['favorite_team_id'] ?? 0);

    $errors = validate_register($username, $password, $password2, $team_id);

    if (!$errors) {
        try {
            db_execute(
                'INSERT INTO users (username, password_hash, favorite_team_id) VALUES (?, ?, ?)',
                'ssi',
                [$username, password_hash($password, PASSWORD_DEFAULT), $team_id === 0 ? null : $team_id]
            );
            set_flash('success', 'Your account has been created. Please log in.');
            header('Location: login.php');
            exit;
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) {   // 1062 = UNIQUE 위반 (동시에 같은 아이디로 가입한 경우)
                throw $e;
            }
            $errors[] = 'This username is already taken.';
        }
    }
}

require_once 'header.php';
?>
<link rel="stylesheet" href="crud_auth.css">

<section class="intro">
    <h1 class="title">Sign Up</h1>
    <p class="content">Create an account to add, edit, and delete game data.</p>
</section>

<section class="auth-card">
    <?php show_flash(); ?>

    <?php if ($errors): ?>
        <ul class="form-error">
            <?php foreach ($errors as $e): ?>
                <li><?= h($e) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="register.php" class="auth-form">
        <div class="form-row">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= h($username) ?>"
                   minlength="3" maxlength="30" pattern="[A-Za-z0-9_]+" required autofocus>
            <span class="form-hint">3-30 characters: letters, numbers, underscore</span>
        </div>

        <div class="form-row">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="8" maxlength="72" required>
            <span class="form-hint">At least 8 characters</span>
        </div>

        <div class="form-row">
            <label for="password_confirm">Confirm Password</label>
            <input type="password" id="password_confirm" name="password_confirm" minlength="8" maxlength="72" required>
        </div>

        <div class="form-row">
            <label for="favorite_team_id">Favorite Team (optional)</label>
            <select id="favorite_team_id" name="favorite_team_id">
                <option value="0">-- Select a team --</option>
                <?php foreach ($teams as $t): ?>
                    <option value="<?= (int)$t['team_id'] ?>" <?= (int)$t['team_id'] === $team_id ? 'selected' : '' ?>>
                        <?= h($t['team_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn-primary">Sign Up</button>
    </form>

    <p class="auth-switch">Already have an account? <a href="login.php">Log in</a></p>
</section>

</main>
</body>
</html>
