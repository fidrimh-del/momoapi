<?php
// MATIKAN ERROR AGAR JSON TIDAK RUSAK
error_reporting(0);

// 1. MULAI SESSION UNTUK MENGINGAT RECOMM_ID
session_start();

header('Content-Type: application/json; charset=utf-8');

// Menangkap parameter page dari aplikasi
$pageParam = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pageParam < 1) $pageParam = 1;

// 2. LOGIKA PENGINGAT (SESSION)
// Jika pengguna merefresh/kembali ke halaman 1, hapus ingatan recommId yang lama
if ($pageParam === 1) {
    unset($_SESSION['saved_recomm_id']);
}
// Ambil recommId dari ingatan PHP (jika ada)
$recommId = isset($_SESSION['saved_recomm_id']) ? $_SESSION['saved_recomm_id'] : '';

$limit = 24;
$offset = ($pageParam - 1) * $limit; 

// Kredensial Recombee
$dbName = "spicychat-prod";
$region = "ca-east";
$token  = "ClJahowCqrmMWSoRj211JIZXWsdEAuDVza3HVJaF0CZK0AbMg8Vb1bhlK5rMfzGi";

$userId = "kp:3A8dbae5efff9d4fd2804d3cf41ae256f9";
$encodedUserId = urlencode($userId); 

$timestamp = time();
$uri = "/{$dbName}/recomms/users/{$encodedUserId}/items/?frontend_timestamp={$timestamp}";
$frontendSign = hash_hmac('sha1', $uri, $token);

$baseUrl = "https://client-rapi-{$region}.recombee.com";
$finalUrl = "{$baseUrl}{$uri}&frontend_sign={$frontendSign}";

// Merakit Payload JSON
$payload = [
    "count" => $limit,
    "offset" => $offset,
    "scenario" => "homepage-characters",
    "cascadeCreate" => true,
    "returnProperties" => true,
    "includedProperties" => ["name", "title", "avatar_url", "tags", "num_messages", "rating_score", "creator_username"]
];

// Sisipkan recommId ke payload jika PHP mengingatnya
if ($recommId !== '') {
    $payload["recommId"] = $recommId;
}

// Eksekusi cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $finalUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "Accept: application/json",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15
]);


$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $characters = $data['recomms'] ?? [];
    
    // 3. SIMPAN RECOMM_ID BARU KE INGATAN SERVER
    if (isset($data['recommId'])) {
        $_SESSION['saved_recomm_id'] = $data['recommId'];
    }
    
    $output = [
        "status"        => "success",
        "current_page"  => $pageParam,
        "data"          => []
    ];
    
    $imageBaseUrl = "https://cdn.nd-api.com/"; 
    
    foreach ($characters as $hit) {
        $doc = $hit['values'];
        $doc['id'] = $hit['id']; 
        
        if (!empty($doc['avatar_url'])) {
            $doc['avatar_url'] = $imageBaseUrl . ltrim($doc['avatar_url'], '/');
        }
        
        $output['data'][] = $doc;
    }
    
    echo json_encode($output, JSON_PRETTY_PRINT);
} else {
    echo json_encode(["status" => "error", "message" => "Recombee API Error"]);
}
?>
