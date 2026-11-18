<?php
// 작성자: 조혜림
// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 현재 페이지 확인
$current_page = basename($_SERVER['PHP_SELF']);

// 로그인 여부 확인
$is_logged_in = isset($_SESSION['user_id']);

function nav_active(string $page, string $current_page): string{
    return $page == $current_page ? 'active' : '';
}

?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>KBO Weather & Baseball Analytics</title>
        <!-- CSS 연결 -->
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <!-- 네비게이션 바 -->
        <header class="site-header">
            <!-- 로고 -->
             <a href="home.php" class="logo">
                <img src="images/ball.jpg" alt="ball">
                KBO Weather & Baseball Analytics
            </a>

            <!-- 메뉴 -->
             <nav class="nav-menu">
                <a href="analysis_group.php" 
                    class="<?= nav_active('analysis_group.php', $current_page) ?>">
                    Aggregating
                </a>
                <a href="analysis_rollup.php" 
                    class="<?= nav_active('analysis_rollup.php', $current_page) ?>">
                    Rollup / Drill down
                </a>
                <a href="analysis_window.php" 
                    class="<?= nav_active('analysis_window.php', $current_page) ?>">
                    Windowing
                </a>
                <a href="analysis_rank.php" 
                    class="<?= nav_active('analysis_rank.php', $current_page) ?>">
                    Ranking
                </a>
                <a href="crud.php" 
                    class="<?= nav_active('crud.php', $current_page) ?>">
                    CRUD
                </a>

                <?php if ($is_logged_in): ?>
                    <a href="logout.php" class="login-link">
                        <img src="images/person.jpg" alt="person" class="login-icon">
                        Logout
                    </a>
                <?php else: ?>
                    <a href="login.php"
                            class="<?= nav_active('login.php', $current_page) ?>">
                        <img src="images/person.jpg" alt="person" class="login-icon">
                        Login
                    </a>
                <?php endif; ?>
            </nav>
        </header>

        <!-- 페이지별 내용 -->
        <main>