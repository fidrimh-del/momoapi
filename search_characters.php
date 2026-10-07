<?php
// MATIKAN ERROR AGAR JSON TIDAK RUSAK
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// 1. Menangkap Parameter dari Aplikasi Java
$searchQuery   = isset($_GET['q']) ? trim($_GET['q']) : '';$pageParam     = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$sortParam     = isset($_GET['sort']) ? strtolower(trim($_GET['sort'])) : '';$categoryParam = isset($_GET['category']) ? trim($_GET['category']) : ''; // Contoh: "Female,NSFW"

if ($pageParam < 1)$pageParam = 1;

// Jika query kosong, gunakan tanda bintang (*) agar Typesense menampilkan semua karakter
if ($searchQuery === '') {$searchQuery = '*';
}

// 2. Logika Pengurutan (Sort) Meniru Struktur Asli SpicyChat
$sortBy = "_text_match(buckets: 3):desc,num_messages_24h:desc"; // Default (Trending)

switch ($sortParam) {
    case 'popular':
        $sortBy = "_text_match(buckets: 3):desc,num_messages:desc"; 
        break;
    case 'top_rated':
        $sortBy = "_text_match(buckets: 3):desc,rating_score:desc"; 
        break;
    case 'latest':
        // Jika mode jelajah (*), pakai created_at. Jika mencari nama, gabung dengan text_match
        $sortBy = ($searchQuery === '*') ? "created_at:desc" : "_text_match(buckets: 3):desc,created_at:desc";
        break;
}

// 3. Logika Filter Kategori Multi-Tag
// Filter keamanan dasar bawaan
$filterBy = "application_ids:spicychat && tags:![Step-Family]";

// Memecah kategori yang digabung koma menjadi filter yang sah (misal: "Female,NSFW")
if ($categoryParam !== '') {
    $categories = explode(',',$categoryParam);
    foreach ($categories as$cat) {
        $cleanCat = trim($cat);
        if ($cleanCat !== '') {
            // Merangkai sesuai JSON: && tags:[`Kategori`]
            $filterBy .= " && tags:[`" . $cleanCat . "`]";
        }
    }
}

// Kredensial Typesense
$apiKey = "STHKtT6jrC5z1IozTJHIeSN4qN9oL1s3";
$typesenseUrl = "https://ts-lb.nd-api.com:443/multi_search?use_cache=true&x-typesense-api-key={$apiKey}";

// 4. Merakit Payload JSON
$payload = [
    "searches" => [
        [
            "query_by" => "name,title,tags,creator_username,character_id,type",
            "include_fields" => "name,title,tags,creator_username,character_id,avatar_is_nsfw,avatar_url,visibility,definition_visible,num_messages,token_count,rating_score,lora_status,creator_user_id,is_nsfw,type,sub_characters_count,group_size_category,has_lorebooks,voice_id,group_addable",
            "highlight_fields" => "none",
            "enable_highlight_v1" => false,
            "search_cutoff_ms" => 10000,
            "facet_sample_percent" => 20,
            "facet_sample_threshold" => 10000,
            "sort_by" => $sortBy, 
            "highlight_full_fields" => "name,title,tags,creator_username,character_id,type",
            "collection" => "public_characters_alias",
            "q" => $searchQuery, 
            "facet_by" => "definition_size_category,group_size_category,tags,translated_languages",
            "filter_by" => $filterBy, 
            "max_facet_values" => 100,
            "page" => $pageParam,
            "per_page" => 48 
        ]
    ]
];

// Eksekusi cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $typesenseUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        "Content-Type: text/plain",
        "Accept: application/json, text/plain, */*",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko)"
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 15
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 5. Output JSON
if ($httpCode === 200) {$data = json_decode($response, true);$hits = $data['results'][0]['hits'] ?? [];$output = [
        "status"        => "success",
        "current_page"  => $pageParam,
        "data"          => []
    ];
    
    $imageBaseUrl = "https://cdn.nd-api.com/"; 
    
    foreach ($hits as$hit) {
        $doc =$hit['document'];
        
        $doc['id'] =$doc['character_id'] ?? '';
        
        if (!empty($doc['avatar_url'])) {$doc['avatar_url'] = $imageBaseUrl . ltrim($doc['avatar_url'], '/');
        }
        
        $output['data'][] =$doc;
    }
    
    echo json_encode($output, JSON_PRETTY_PRINT);
} else {
    echo json_encode(["status" => "error", "message" => "Typesense API Error"]);
}
?>
