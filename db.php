<?php
// 작성자: 정윤아
// DB 연결과 쿼리 헬퍼 함수 모음
// 사용법은 docs/backend_usage.md 참고

require_once __DIR__ . '/config.php';

// mysqli 오류를 예외(mysqli_sql_exception)로 던지게 설정
// → try/catch 로 잡아서 트랜잭션 rollback 등을 할 수 있다.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// DB 연결 객체를 돌려준다. 처음 호출할 때 한 번만 연결한다.
function db(): mysqli
{
    static $conn = null;

    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            echo '<h2>데이터베이스에 연결할 수 없습니다.</h2>';
            echo '<p>XAMPP에서 MySQL이 실행 중인지, team04 계정과 DB가 만들어져 있는지 확인하세요.</p>';
            echo '<p>오류 내용: ' . htmlspecialchars($e->getMessage()) . '</p>';
            exit;
        }
    }
    return $conn;
}

// (내부용) SQL 을 prepare 하고 값을 바인딩해서 실행한다.
//   $types  : 'i'(정수) 'd'(실수) 's'(문자열) 를 값 개수만큼. 예) 'isd'
//   $params : 바인딩할 값 배열. 예) [3, '서울', 25.5]
function db_run(string $sql, string $types, array $params): mysqli_stmt
{
    if (strlen($types) !== count($params)) {
        throw new InvalidArgumentException('타입 문자열 길이와 값 개수가 다릅니다: ' . $types);
    }

    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

// SELECT 실행 → 결과 전체를 연관배열의 배열로 돌려준다.
//   예) $rows = db_query('SELECT * FROM team WHERE team_id = ?', 'i', [3]);
//       foreach ($rows as $row) { echo $row['team_name']; }
function db_query(string $sql, string $types = '', array $params = []): array
{
    $stmt = db_run($sql, $types, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// SELECT 결과의 첫 행만 돌려준다. 없으면 null.
function db_query_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_query($sql, $types, $params);
    return $rows[0] ?? null;
}

// INSERT / UPDATE / DELETE 실행
//   반환: ['affected_rows' => 바뀐 행 수, 'insert_id' => 새로 생긴 AUTO_INCREMENT 값]
function db_execute(string $sql, string $types = '', array $params = []): array
{
    $stmt = db_run($sql, $types, $params);
    $result = [
        'affected_rows' => $stmt->affected_rows,
        'insert_id'     => db()->insert_id,
    ];
    $stmt->close();
    return $result;
}

// 바인딩할 수 없는 부분(정렬 컬럼, ASC/DESC 등)을 화이트리스트로 검증한다.
// 허용 목록에 없으면 기본값을 돌려준다.
//   예) $order = db_whitelist($_GET['sort'] ?? '', ['runs', 'hits'], 'runs');
function db_whitelist($value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

// 화면 출력용 이스케이프 (htmlspecialchars 줄임)
//   예) echo '<td>' . h($row['team_name']) . '</td>';
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
