<?php
// 작성자: 정윤아
// 세션 관련 공통 함수: 로그인 확인, 분석 조건 저장/조회, 플래시 메시지
// 주의: header.php 보다 먼저 require 해야 한다 (리다이렉트는 HTML 출력 전에만 가능).

// 세션이 아직 시작되지 않았을 때만 시작 (중복 session_start 방지)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- 로그인 ---------- */

// 로그인 여부
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

// 로그인한 사용자 정보. 로그인 안 했으면 null.
//   반환 예) ['user_id' => 1, 'username' => 'yuna', 'favorite_team_id' => 3]
function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return [
        'user_id'          => $_SESSION['user_id'],
        'username'         => $_SESSION['username'],
        'favorite_team_id' => $_SESSION['favorite_team_id'] ?? null,
    ];
}

// 로그인한 사용자만 볼 수 있는 페이지 맨 위에서 호출.
// 로그인 안 했으면 login.php 로 보낸다.
function require_login(): void
{
    if (is_logged_in()) {
        return;
    }
    set_flash('error', '로그인이 필요합니다.');
    header('Location: login.php');
    exit;
}

// 로그인 성공 시 호출 (login.php 에서 사용)
// 세션 고정 공격을 막기 위해 세션 ID 를 새로 만든 뒤 정보를 저장한다.
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']          = (int)$user['user_id'];
    $_SESSION['username']         = $user['username'];
    $_SESSION['favorite_team_id'] = $user['favorite_team_id'] === null ? null : (int)$user['favorite_team_id'];
}

/* ---------- 분석 조건 저장/조회 ---------- */

// 페이지별 검색·분석 조건을 세션에 저장
//   예) save_filter('analysis_rank', ['team_id' => 3, 'min_temp' => 30]);
function save_filter(string $page, array $filter): void
{
    $_SESSION['filters'][$page] = $filter;
}

// 저장해 둔 조건을 꺼낸다. 저장된 게 없으면 $default 를 돌려준다.
// 저장된 값이 있는 키만 $default 를 덮어쓴다.
//   예) $f = load_filter('analysis_rank', ['team_id' => 0, 'min_temp' => 33]);
function load_filter(string $page, array $default = []): array
{
    $saved = $_SESSION['filters'][$page] ?? [];
    return array_merge($default, $saved);
}

// 저장된 조건 지우기 (초기화 버튼 등)
function clear_filter(string $page): void
{
    unset($_SESSION['filters'][$page]);
}

/* ---------- 플래시 메시지 (다음 페이지에서 한 번만 보여주는 메시지) ---------- */

// $type: 'success' | 'error' | 'warning'
//   예) set_flash('success', '경기가 저장되었습니다.'); header('Location: game_manage.php');
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

// 쌓인 메시지를 꺼내고 세션에서는 지운다.
//   반환 예) [['type' => 'success', 'message' => '저장되었습니다.']]
function get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// 플래시 메시지를 HTML 로 바로 출력 (class="flash flash-success" 형태)
function show_flash(): void
{
    foreach (get_flash() as $f) {
        $type = htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8');
        $msg  = htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8');
        echo "<div class=\"flash flash-{$type}\">{$msg}</div>\n";
    }
}
