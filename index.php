<?php
// index.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 取得 API Key
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    echo json_encode(["success" => false, "error" => "API Key 未設定"]);
    exit;
}

// 根據路由處理請求
$uri = $_SERVER['REQUEST_URI'];

// 根目錄 /index.php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($uri === '/' || $uri === '/index.php')) {
    echo json_encode(["success" => true, "message" => "Caffinder PHP API is running!"]);
    exit;
}

// /test_ai 路由
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $uri === '/test_ai') {

    $requestData = [
        "location" => "台北市中正區",
        "mrt" => "",
        "preferences" => ["咖啡","甜點"],
        "style" => "文青",
        "time_preference" => "標準",
        "user_goals" => ["休閒放鬆"],
        "search_mode" => "address"
    ];

    $payload = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "system", "content" => "你是一個旅遊行程生成 AI。"],
            ["role" => "user", "content" => json_encode($requestData)]
        ],
        "temperature" => 0.7
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode(["success" => false, "error" => $err]);
    } else {
        $data = json_decode($response, true);
        echo json_encode(["success" => true, "data" => $data]);
    }
    exit;
}

// 其他未實作路由
echo json_encode(["success" => false, "error" => "路由不存在或未實作"]);
