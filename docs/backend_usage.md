<!-- 작성자: 정윤아 -->
# 백엔드 공통 모듈 사용법 (팀원용)

공통 파일 3개를 `require` 하면 DB 연결, 세션, 로그인 확인을 바로 쓸 수 있습니다.
공통 파일(`config.php`, `db.php`, `session.php`)은 정윤아만 수정합니다. 필요한 기능이 있으면 말해 주세요.

| 파일 | 내용 |
|---|---|
| `config.php` | DB 접속 상수 (`team04` / `team04` / `team04`) |
| `db.php` | DB 연결, 쿼리 헬퍼(`db_query`, `db_execute` …), 출력용 `h()` |
| `session.php` | 세션 시작, 로그인 확인, 분석 조건 저장/조회, 플래시 메시지 |

---

## 1. 페이지 맨 위에 이 몇 줄

```php
<?php
// 작성자: 홍길동            ← 과제 필수! 파일마다 꼭 적기
require_once 'session.php';   // 1) 세션 (header.php 보다 먼저!)
require_once 'db.php';        // 2) DB

// require_login();           // 로그인한 사람만 볼 페이지면 주석 해제

// 3) 입력 받기 → 쿼리 실행 (HTML 출력 전에 처리)
$rows = db_query('SELECT team_id, team_name FROM team ORDER BY team_name');

require_once 'header.php';    // 4) 그 다음에 화면 출력 시작
?>
<?php show_flash(); ?>
<table>
<?php foreach ($rows as $r): ?>
    <tr><td><?= h($r['team_name']) ?></td></tr>
<?php endforeach; ?>
</table>
```

**순서가 중요합니다.** `header.php` 가 HTML을 출력하기 시작하면 그 뒤로는 `header('Location: ...')`(리다이렉트)가 안 됩니다.
그래서 `session.php` → `db.php` → 처리 → `header.php` 순서로 씁니다.

---

## 2. 쿼리 실행 (Prepared Statement)

**사용자 입력은 절대 SQL 문자열에 직접 붙이지 않습니다.** `?` 자리에 값을 바인딩합니다.

```php
// SELECT 여러 행 → 연관배열의 배열
$rows = db_query(
    'SELECT g.game_date, s.stadium_name
       FROM game g JOIN stadium s ON s.stadium_id = g.stadium_id
      WHERE g.game_date BETWEEN ? AND ? AND s.city = ?',
    'sss',                                   // 값 3개 → 타입 3글자
    [$from, $to, $city]
);

// SELECT 한 행 → 연관배열 또는 null
$team = db_query_one('SELECT * FROM team WHERE team_id = ?', 'i', [$team_id]);

// INSERT / UPDATE / DELETE → ['affected_rows' => n, 'insert_id' => id]
$r = db_execute('UPDATE game SET game_status = ? WHERE game_id = ?', 'si', ['cancelled', 7]);
echo $r['affected_rows'];
```

타입 문자: `i` 정수, `d` 실수, `s` 문자열(날짜 포함). 값 개수와 글자 수가 같아야 합니다.

### IN (...) 에 여러 값 넣기 (체크박스 등)

```php
$ids = array_map('intval', $_GET['team_ids'] ?? []);      // 예: [1, 3, 5]
if ($ids) {
    $marks = implode(',', array_fill(0, count($ids), '?')); // "?,?,?"
    $rows  = db_query("SELECT * FROM team WHERE team_id IN ($marks)",
                      str_repeat('i', count($ids)), $ids);
}
```

### 정렬 컬럼·ASC/DESC 처럼 바인딩이 안 되는 부분 → 화이트리스트

```php
$sort = db_whitelist($_GET['sort'] ?? '', ['avg_runs', 'avg_hits', 'games'], 'avg_runs');
$dir  = db_whitelist($_GET['dir']  ?? '', ['ASC', 'DESC'], 'DESC');
$rows = db_query("SELECT ... ORDER BY $sort $dir LIMIT ?", 'i', [$limit]);
```

허용 목록에 없는 값이 들어오면 기본값으로 바뀌므로 안전합니다.

### 화면 출력은 항상 `h()`

```php
<td><?= h($row['team_name']) ?></td>
```

---

## 3. 분석 조건을 세션에 저장 (과제 요구사항: PHP 세션)

다른 페이지에 갔다가 돌아와도 마지막 검색 조건이 유지됩니다.

```php
$page = 'analysis_rank';                                  // 페이지마다 고유 이름
$default = ['team_id' => 0, 'min_temp' => 33, 'stadiums' => []];

if (isset($_GET['submit'])) {                             // 검색 버튼을 눌렀을 때
    $filter = [
        'team_id'  => (int)($_GET['team_id'] ?? 0),
        'min_temp' => (float)($_GET['min_temp'] ?? 33),
        'stadiums' => array_map('intval', $_GET['stadiums'] ?? []),
    ];
    save_filter($page, $filter);
} elseif (isset($_GET['reset'])) {                        // 초기화 버튼
    clear_filter($page);
    $filter = $default;
} else {                                                  // 처음 들어왔을 때
    $filter = load_filter($page, $default);
}
```

폼에서는 `$filter` 값으로 `selected` / `checked` 를 표시하면 됩니다.

---

## 4. 로그인 관련

```php
require_login();                 // 로그인 안 했으면 login.php 로 이동
$user = current_user();          // ['user_id', 'username', 'favorite_team_id'] 또는 null
if ($user) echo h($user['username']);
```

`header.php` 의 로그인/로그아웃 메뉴는 `$_SESSION['user_id']` 로 판단하며, `session.php` 와 같은 키를 씁니다.

---

## 5. 플래시 메시지 (리다이렉트 후 한 번만 보여주는 알림)

```php
set_flash('success', '저장되었습니다.');   // 'success' | 'error' | 'warning'
header('Location: game_manage.php');
exit;
```

다음 페이지에서 `show_flash();` 를 호출하면 `<div class="flash flash-success">…</div>` 로 출력됩니다.
(스타일은 `style.css` 에 `.flash`, `.flash-success`, `.flash-error`, `.flash-warning` 추가 요청)

---

## 6. 트랜잭션 (경기 관리 페이지에서 사용)

```php
$conn = db();
try {
    $conn->begin_transaction();
    $r = db_execute('INSERT INTO game (...) VALUES (?, ?, ...)', '...', [...]);
    $game_id = $r['insert_id'];
    db_execute('INSERT INTO team_game_stats (...) VALUES (...)', '...', [...]);  // 홈
    db_execute('INSERT INTO team_game_stats (...) VALUES (...)', '...', [...]);  // 원정
    $conn->commit();
} catch (mysqli_sql_exception $e) {
    $conn->rollback();           // 하나라도 실패하면 전부 취소
    set_flash('error', '저장 실패: ' . $e->getMessage());
}
```

`db.php` 가 오류를 예외로 던지도록 설정해 두어서 `try/catch` 로 잡을 수 있습니다.

### 시연: 트랜잭션 롤백 보여주기

(3단계 경기 입력 페이지 완성 후 구체적인 순서를 채울 예정)
아이디어: 이미 있는 경기와 같은 날짜·구장·홈·원정·더블헤더 번호로 다시 입력 → `game` UNIQUE 위반 → 전체 롤백 → `team_game_stats` 도 늘어나지 않은 것을 확인.

---

## 7. 로컬 DB 준비 (처음 한 번)

XAMPP에서 Apache, MySQL을 Start 한 뒤 터미널에서 root 로 접속합니다.

- Windows: `C:\xampp\mysql\bin\mysql -u root`
- macOS: `/Applications/XAMPP/xamppfiles/bin/mysql -u root`

```sql
CREATE DATABASE IF NOT EXISTS team04 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'team04'@'localhost' IDENTIFIED BY 'team04';
GRANT ALL PRIVILEGES ON team04.* TO 'team04'@'localhost';
FLUSH PRIVILEGES;
```

그 다음 테이블과 데이터를 넣습니다 (`htdocs/team04` 폴더에서 실행).

```sql
-- mysql -u team04 -p team04  (비밀번호 team04) 접속 후
source sql/dbcreate.sql;
source sql/dbinsert.sql;
```

`dbinsert.sql` 이 아직 없으면 `dbcreate.sql` 실행 후 개발용 `sql/dev_sample.sql` 로 샘플 데이터를 넣어 테스트할 수 있습니다 (제출용 아님, 기존 데이터를 지우고 다시 채움).
