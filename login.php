<?php
// 작성자: 정윤아
// 로그인: password_verify 로 확인 → 세션에 user_id, username, favorite_team_id 저장
require_once 'session.php';
require_once 'db.php';

if (is_logged_in()) {
    header('Location: home.php');
    exit;
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = db_query_one(
        'SELECT user_id, username, password_hash, favorite_team_id FROM users WHERE username = ?',
        's',
        [$username]
    );

    // 아이디가 없거나 비밀번호가 틀려도 같은 메시지 (어느 쪽이 틀렸는지 알려주지 않음)
    if ($user && password_verify($password, $user['password_hash'])) {
        login_user($user);                        // 세션 ID 재발급 + 사용자 정보 저장
        header('Location: ' . pop_after_login_page());
        exit;
    }
    $error = 'Invalid username or password.';
}

require_once 'header.php';
?>

<section class="intro">
    <h1 class="title">Log In</h1>
    <p class="content">Log in to manage game data.</p>
</section>

<section class="auth-card">
    <?php show_flash(); ?>

    <?php if ($error): ?>
        <ul class="form-error"><li><?= h($error) ?></li></ul>
    <?php endif; ?>

    <form method="post" action="login.php" class="auth-form">
        <div class="form-row">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= h($username) ?>" maxlength="30" required autofocus>
        </div>

        <div class="form-row">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" maxlength="72" required>
        </div>

        <button type="submit" class="btn-primary">Log In</button>
    </form>

    <p class="auth-switch">Don't have an account? <a href="register.php">Sign up</a></p>
</section>

</main>
</body>
</html>
