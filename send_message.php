<?php
require_once 'SpicyChatAuth.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Tangkap Input dari Frontend (via POST)
// PENTING: Deklarasikan $inputData terlebih dahulu sebelum mengambil isinya
$inputData = json_decode(file_get_contents('php://input'), true);

$inferenceModel = $inputData['inference_model'] ?? 'default'; // Gunakan default jika tidak dikirim
$characterId    = $inputData['character_id'] ?? '';
$conversationId = $inputData['conversation_id'] ?? null; // Null jika obrolan baru
$userMessage    = $inputData['message'] ?? '';
$localUserName  = $inputData['local_user_name'] ?? 'User'; // Data dari DB Lokal Anda
$localPersona   = $inputData['local_persona'] ?? 'Seorang manusia.'; // Data dari DB Lokal
$localGender    = $inputData['local_gender'] ?? 'Tidak disebutkan'; // Data gender dari DB Lokal
$appLanguage    = $inputData['app_language'] ?? 'en';
// Nama asli akun SpicyChat Anda (untuk di-filter nanti)
$accountName    = "hellcat"; 

if (empty($characterId) || empty($userMessage)) {
    echo json_encode(["status" => "error", "message" => "Parameter character_id atau message tidak boleh kosong."]);
    exit;
}

$langNames = [
    'id' => 'Indonesian', 'en' => 'English', 'es' => 'Spanish',
    'pt' => 'Portuguese', 'fr' => 'French', 'de' => 'German',
    'ja' => 'Japanese', 'ko' => 'Korean',
];
$replyLang = $langNames[$appLanguage] ?? 'English';

try {
    // 2. Dapatkan Token API
    $auth = new SpicyChatAuth();
    $accessToken = $auth->getValidAccessToken();

    // 3. Rakit Pesan dengan Prompt Injection Agresif (HANYA PADA PESAN PERTAMA)
    $finalMessage = $userMessage;
    
    // Jika conversation_id kosong, ini adalah awal obrolan. Kita suntikkan instruksi mutlak.
    if ($conversationId === null) {
        // LAPIS PERTAMA: Instruksi mutlak nama + Aturan Format Ekstrem untuk tanda bintang
        $injection = "[System Directive: The human's name is STRICTLY '{$localUserName}' and their gender is {$localGender}. You are FORBIDDEN from using the names '{$accountName}' or '{{user}}'. Acknowledge the user's description: {$localPersona}. LANGUAGE RULE: You MUST reply ENTIRELY in {$replyLang}. All dialogue, narration, thoughts, and actions must be in {$replyLang}. Never mix in other languages. CRITICAL FORMATTING RULES: 1. You MUST enclose all your actions, thoughts, and narrations strictly within asterisks (e.g., *I smile softly.*). 2. You MUST meticulously ensure every single opening asterisk has a matching closing asterisk. Never leave an asterisk unclosed! 3. When the USER wraps text in asterisks (e.g., *aku sedang ragu*), it represents their character's actions or PRIVATE inner thoughts, NOT spoken words. Your character CANNOT hear or know private thoughts. NEVER react to, quote, or reveal knowledge of the user's inner thoughts unless they speak aloud or perform a visible action.]\n\n";

        $finalMessage = $injection . $userMessage;
    }

    // 4. Kirim Request Chat ke Server
    $url = "https://prod.nd-api.com/chat";
    
    $payload = [
        "conversation_id"    => $conversationId, 
        "character_id"       => $characterId,
        "user_persona_id"    => "aa7c735b-a39b-492e-8d93-7f4366a6459b", // KOSONGKAN agar tidak memakai persona global akun Anda
        "language"           => $appLanguage, // Sesuaikan jika bot Anda berbahasa Indonesia
        "inference_model"    => $inferenceModel,
        "inference_settings" => [
            "max_new_tokens" => 180,
            "temperature"    => 0.7,
            "top_p"          => 0.7,
            "top_k"          => 90
        ],
        "autopilot"          => false,
        "continue_chat"      => false,
        "message"            => $finalMessage
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
        CURLOPT_TIMEOUT        => 60 // Waktu tunggu bot membalas
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        throw new Exception("cURL Error: " . $curlError);
    }

    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        $botReply = $responseData['message']['content'] ?? '';
        
        // 5. LAPIS KEDUA: Intersepsi & Replace Paksa
        // Daftar kata yang dilarang muncul di layar aplikasi Anda
        $forbiddenNames = [
            $accountName,              // "hellcat"
            ucfirst($accountName),     // "Hellcat"
            strtoupper($accountName),  // "HELLCAT"
            "{{user}}", 
            "{{User}}"
        ];
        
        // Timpa kata terlarang dengan nama pengguna lokal
        $botReply = str_ireplace($forbiddenNames, $localUserName, $botReply);

        // 6. LAPIS KETIGA: Auto-Fixer Format Bintang (Markdown)
        // Hitung jumlah tanda bintang di dalam teks balasan
        $asteriskCount = substr_count($botReply, '*');

        // Jika jumlah bintang ganjil (berarti ada 1 bintang yang lupa ditutup di akhir)
        if ($asteriskCount % 2 !== 0) {
            // Tambahkan bintang penutup secara paksa di akhir teks
            $botReply .= '*';
        }

        // Output balasan bersih dan rapi untuk Frontend Anda
        echo json_encode([
            "status"          => "success",
            "conversation_id" => $responseData['message']['conversation_id'], 
            "bot_reply"       => $botReply
        ], JSON_PRETTY_PRINT);

    } else {
        throw new Exception("Gagal mengirim pesan (HTTP $httpCode): " . $response);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
