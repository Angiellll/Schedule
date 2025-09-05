<?php
header('Content-Type: application/json; charset=utf-8');

// 連接資料庫
$mysqli = new mysqli('localhost', 'root', '12345', 'CSV_DB_6');
if ($mysqli->connect_errno) {
    echo json_encode(['error' => 'Failed to connect to DB']);
    exit;
}

// 取得搜尋條件 (用 GET 或 POST 都可)
$city = isset($_GET['city']) ? $mysqli->real_escape_string($_GET['city']) : null;
$min_wifi = isset($_GET['min_wifi']) ? (int)$_GET['min_wifi'] : null;
$max_wifi = isset($_GET['max_wifi']) ? (int)$_GET['max_wifi'] : null;
$min_seat = isset($_GET['min_seat']) ? (float)$_GET['min_seat'] : null;
$max_seat = isset($_GET['max_seat']) ? (float)$max_seat : null;
$min_score = isset($_GET['min_score']) ? (float)$_GET['min_score'] : null;  // 例如 tasty 評分

// 建立 SQL 條件
$conditions = [];
if ($city) {
    $conditions[] = "city = '$city'";
}
if (!is_null($min_wifi)) {
    $conditions[] = "wifi >= $min_wifi";
}
if (!is_null($max_wifi)) {
    $conditions[] = "wifi <= $max_wifi";
}
if (!is_null($min_seat)) {
    $conditions[] = "seat >= $min_seat";
}
if (!is_null($max_seat)) {
    $conditions[] = "seat <= $max_seat";
}
if (!is_null($min_score)) {
    $conditions[] = "tasty >= $min_score";
}

// 組合條件字串
$where_sql = '';
if (count($conditions) > 0) {
    $where_sql = 'WHERE ' . implode(' AND ', $conditions);
}

$sql = "SELECT * FROM cafe $where_sql LIMIT 100";  // 限制最多100筆避免爆表

$result = $mysqli->query($sql);
if (!$result) {
    echo json_encode(['error' => 'Query failed']);
    exit;
}

$cafes = [];
while ($row = $result->fetch_assoc()) {
    $cafes[] = $row;
}

echo json_encode(['cafes' => $cafes]);
