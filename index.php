<?php
// index.php

// 簡單首頁回應
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '/index.php')) {
    echo "Caffinder PHP API is running!";
    exit;
}

// 如果是 /test_ai，就測試 AI API
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/test_ai') {

    header("Content-Type: application/json");

    $apiKey = "你的_API_KEY";  // <- 替換成你的 OpenAI Key

    $requestData = [
        "location" => "台北市中正區",
        "mrt" => "",
        "preferences" => ["咖啡","甜點"],
        "style" => "文青",
        "time_preference" => "標準",
        "user_goals" => ["休閒放鬆"],
        "search_mode" => "address"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    $payload = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "system", "content" => "你是一個旅遊行程生成 AI。"],
            ["role" => "user", "content" => json_encode($requestData)]
        ],
        "temperature" => 0.7
    ];

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
        echo $response;
    }

    exit;
}

// 其他路由可以在這裡處理
echo "路由不存在或未實作";
