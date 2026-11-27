<?php
// 작성자: 정윤아
// 로그아웃: 세션 파기 후 로그인 페이지로 이동
require_once 'session.php';

logout_user();

// 안내 메시지를 보여주기 위해 새 세션을 시작한다.
session_start();
session_regenerate_id(true);
set_flash('success', 'You have been logged out.');

header('Location: login.php');
exit;
