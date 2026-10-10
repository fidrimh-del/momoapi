<?php
// MATIKAN ERROR AGAR JSON TIDAK RUSAK
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Endpoint ini hanya menerima metode POST."]);
    exit;
}

$inputData = json_decode(file_get_contents('php://input'), true);
$textToTranslate = $inputData['text'] ?? $_POST['text'] ?? '';
$targetLang      = $inputData['target_lang'] ?? $_POST['target_lang'] ?? 'id'; 

if (empty($textToTranslate)) {
    echo json_encode(["status" => "error", "message" => "Parameter 'text' tidak boleh kosong."]);
    exit;
}

$cacheFile = 'imt_cache.json';

// Fungsi untuk Generate Token Baru dan menyimpannya ke file
function generateNewToken($cacheFile) {
    $fakeDeviceId = hash('sha256', uniqid('android_device_', true));
    $tokenUrl = "https://api2.immersivetranslate.com/free-model/get-token?deviceId={$fakeDeviceId}&l=0";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tokenUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            "accept-language: id",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        $token = $data['data'] ?? '';
        
        if (!empty($token)) {
            // Simpan ke file cache
            file_put_contents($cacheFile, json_encode([
                "device_id" => $fakeDeviceId,
                "token" => $token,
                "time" => time()
            ]));
            return $token;
        }
    }
    return false;
}

// Fungsi untuk Menjalankan Terjemahan
function executeTranslation($text, $targetLang, $token) {
    $url = "https://aigw1.immersivetranslate.com/v1/translation/tasks";
    $payload = [
        "task_type" => "text_page",
        "stream" => false,
        "payload" => [
            "to" => $targetLang,
            "content_type" => "plain_text",
            "segments" => [ ["id" => "seg-0", "text" => $text] ]
        ],
        "ui_language" => "id"
    ];

    $headers = [
        "Accept: application/json",
        "Content-Type: application/json",
        "authorization: Bearer " . $token,
        "x-imt-product-line: text_page",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ["http_code" => $httpCode, "response" => $response];
}

// ==========================================
// ALUR UTAMA (SMART CACHE LOGIC)
// ==========================================

$activeToken = "";

// 1. Cek apakah kita sudah punya token yang tersimpan di file
if (file_exists($cacheFile)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    $activeToken = $cacheData['token'] ?? '';
}

// 2. Jika tidak ada token (file tidak ada/kosong), buat baru
if (empty($activeToken)) {
    $activeToken = generateNewToken($cacheFile);
    if (!$activeToken) {
        echo json_encode(["status" => "error", "message" => "Gagal mendapatkan token awal."]);
        exit;
    }
}

// 3. Eksekusi Terjemahan dengan token yang ada
$transResult = executeTranslation($textToTranslate, $targetLang, $activeToken);

// 4. JIKA GAGAL (Token Expired / Device Diblokir) -> AUTO RETRY 1x
if ($transResult['http_code'] !== 200) {
    // Hapus token lama yang bermasalah, paksa buat yang baru
    $activeToken = generateNewToken($cacheFile);
    
    if ($activeToken) {
        // Coba terjemahkan ulang dengan token yang benar-benar baru
        $transResult = executeTranslation($textToTranslate, $targetLang, $activeToken);
    } else {
        echo json_encode(["status" => "error", "message" => "Gagal memulihkan token baru."]);
        exit;
    }
}

// 5. Parsing dan Output Hasil Akhir
if ($transResult['http_code'] === 200 && $transResult['response']) {
    $responseData = json_decode($transResult['response'], true);
    $translatedText = $responseData['result']['segments'][0]['translated_text'] ?? '';

    echo json_encode([
        "status"          => "success",
        "original_text"   => $textToTranslate,
        "translated_text" => $translatedText
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        "status"    => "error",
        "message"   => "Gagal menerjemahkan teks setelah retry",
        "http_code" => $transResult['http_code']
    ]);
}
?>
