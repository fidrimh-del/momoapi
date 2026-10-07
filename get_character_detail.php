<?php
require_once 'SpicyChatAuth.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Menangkap ID Karakter dan Nama Pengguna Lokal dari URL
$characterId = isset($_GET['id']) ? trim($_GET['id']) : '';
// Menangkap nama user dari aplikasi, default 'User' jika tidak dikirim
$localUserName = isset($_GET['user_name']) ? trim($_GET['user_name']) : 'User'; 

if (empty($characterId)) {
    echo json_encode([
        "status" => "error",
        "message" => "Parameter 'id' karakter tidak boleh kosong."
    ]);
    exit;
}

try {
    $auth = new SpicyChatAuth();
    $accessToken = $auth->getValidAccessToken();

    $url = "https://prod.nd-api.com/v2/characters/" . urlencode($characterId);

    $headers = [
        "Accept: application/json",
        "Authorization: Bearer " . $accessToken,
        "x-app-id: spicychat",
        "x-platform: WEB"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => "",
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        throw new Exception("cURL Error: " . $curlError);
    }

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        
        // Ambil data mentah
        $charName = $data['name'] ?? 'Unknown';
        $originalTitle = $data['title'] ?? '';
        $originalGreeting = $data['greeting'] ?? '';
        
        // 2. Membersihkan Greeting dari variabel makro bawaan kreator
        $placeholders = ['{{char}}', '{{user}}'];
        $replacements = [$charName, $localUserName];
        
        // Mengganti {{char}} menjadi nama karakter, dan {{user}} menjadi nama user lokal
        $greetingBersih = str_ireplace($placeholders, $replacements, $originalGreeting);

        $imageBaseUrl = "https://cdn.spicychat.ai/";
        if (!empty($data['avatar_url'])) {
            $data['avatar_url'] = $imageBaseUrl . ltrim($data['avatar_url'], '/');
        }

        // 3. Menyusun output JSON murni (Tanpa Terjemahan PHP)
        echo json_encode([
            "status" => "success",
            "data" => [
                "id"               => $data['id'] ?? $characterId,
                "name"             => $charName,
                "title"            => $originalTitle,     // Kembali menggunakan title asli berbahasa Inggris
                "greeting"         => $greetingBersih,    // Menggunakan greeting asli yang sudah dibersihkan makronya
                "avatar_url"       => $data['avatar_url'] ?? '',
                "creator"          => $data['creator_username'] ?? '',
                "tags"             => $data['tags'] ?? [],
                "num_messages"     => $data['num_messages'] ?? 0,
                "is_nsfw"          => $data['is_nsfw'] ?? true
            ]
        ], JSON_PRETTY_PRINT);

    } else {
        throw new Exception("Gagal mengambil data karakter (HTTP $httpCode): " . $response);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
