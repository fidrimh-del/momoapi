<?php
header('Content-Type: application/json; charset=utf-8');

// 1. Menangkap parameter URL utama
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1)$page = 1;

$category = isset($_GET['category']) ? trim($_GET['category']) : '';$searchQuery = isset($_GET['q']) &&$_GET['q'] !== '' ? trim($_GET['q']) : '*';$sort = isset($_GET['sort']) ? strtolower(trim($_GET['sort'])) : 'trending';

// 2. Menentukan parameter sort_by untuk Typesense
switch ($sort) {
    case 'top_rated':
        $sortBy = "rating_score:desc";
        break;
    case 'popular':
        $sortBy = "num_messages:desc";
        break;
    case 'latest':
        $sortBy = "createdAt:desc"; 
        break;
    case 'trending':
    default:
        $sortBy = "_text_match(buckets: 3):desc,num_messages_24h:desc";
        break;
}

// 3. Merakit Filter Typesense
$filterBy = "application_ids:spicychat && tags:![Step-Family,NSFW,Oral,Vore,Flatulence,Masochistic,Watersport,CNC,Impregnation,Lactation,Anal] && is_nsfw:true";

if ($category !== '') {
    $catArray = explode(',',$category);
    foreach ($catArray as $cat) {$filterBy .= " && tags:`" . trim($cat) . "`";
    }
}

// 4. Payload Endpoint Typesense
$url = "https://ts-lb.nd-api.com:443/multi_search?use_cache=true&x-typesense-api-key=STHKtT6jrC5z1IozTJHIeSN4qN9oL1s3";

$payload = [
    "searches" => [
        [
            "collection"     => "public_characters_alias",
            "q"              => $searchQuery,
            "query_by"       => "name,title,tags,creator_username,character_id,type",
            "include_fields" => "name,title,tags,creator_username,character_id,avatar_url,num_messages,rating_score",
            "filter_by"      => $filterBy,
            "sort_by"        => $sortBy, 
            "page"           => $page,
            "per_page"       => 24
        ]
    ]
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        "Accept: application/json",
        "Content-Type: text/plain"
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => "",
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false 
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($curlError) {
    echo json_encode(["status" => "error", "message" => "cURL Error: " . $curlError]);
    exit;
}

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $characters =$data['results'][0]['hits'] ?? [];
    $totalFound =$data['results'][0]['found'] ?? 0;
    
    $output = [
        "status"        => "success",
        "current_page"  => $page,
        "category"      => $category === '' ? 'All' :$category,
        "search_query"  => $searchQuery,
        "total_results" => $totalFound,
        "data"          => []
    ];
    
    // KEMBALI KE URL CDN ASLI AGAR GAMBAR TIDAK ERROR
    $imageBaseUrl = "https://cdn.nd-api.com/"; 
    
    foreach ($characters as$hit) {
        $doc =$hit['document'];
        
        // Memperbaiki URL avatar
        if (!empty($doc['avatar_url'])) {$doc['avatar_url'] = $imageBaseUrl . ltrim($doc['avatar_url'], '/');
        }
        
        $output['data'][] =$doc;
    }
    
    echo json_encode($output, JSON_PRETTY_PRINT);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal mengambil data. HTTP Code: " . $httpCode]);
}
