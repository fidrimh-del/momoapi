<?php
require_once 'SpicyChatAuth.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Tangkap Input JSON
$inputData = json_decode(file_get_contents('php://input'), true);

$characterId    = $inputData['character_id'] ?? '';
$conversationId = $inputData['conversation_id'] ?? null;
$inferenceModel = $inputData['inference_model'] ?? 'default';

// Rekomendasi biasanya butuh riwayat, jadi conversation_id wajib ada
if (empty($characterId) || empty($conversationId)) {
    echo json_encode(["status" => "error", "message" => "character_id dan conversation_id tidak boleh kosong."]);
    exit;
}

try {
    $auth = new SpicyChatAuth();
    $accessToken = $auth->getValidAccessToken();

    $url = "https://prod.nd-api.com/chat";
    
    $payload = [
        "conversation_id"    => $conversationId, 
        "character_id"       => $characterId,
        "user_persona_id"    => "", 
        "language"           => "en", 
        "inference_model"    => $inferenceModel,
        "inference_settings" => [
            "max_new_tokens" => 180,
            "temperature"    => 0.7,
            "top_p"          => 0.7,
            "top_k"          => 90
        ],
        "autopilot"          => true,  // KUNCI: Autopilot aktif
        "continue_chat"      => false,
        "message"            => ""     // KUNCI: Pesan dikosongkan
    ];

    $headers = [
        "Accept: application/json",
        "Content-Type: application/json",
        "Authorization: Bearer " . $accessToken,
        "x-app-id: spicychat",
        "x-platform: WEB"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 60 
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        throw new Exception("cURL Error: " . $curlError);
    }

    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        
        echo json_encode([
            "status"      => "success",
            "suggestion"  => $responseData['message']['content'] ?? ''
        ], JSON_PRETTY_PRINT);

    } else {
        throw new Exception("Gagal meminta rekomendasi (HTTP $httpCode): " . $response);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
