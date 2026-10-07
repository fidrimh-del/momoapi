<?php
// Tidak perlu echo atau error reporting untuk mode API produksi
class SpicyChatAuth {
    private $clientId = 'fb5754f42ee84f4787f9bd8ff49cac7a';
    private $tokenFile = __DIR__ . '/spicychat_tokens.json';

    public function getValidAccessToken() {
        $tokens = $this->loadTokens();

        if (!$tokens) {
            throw new Exception("File token tidak ditemukan atau kosong. Buat file 'spicychat_tokens.json' dulu.");
        }

        if (isset($tokens['access_token'], $tokens['expires_at'])) {
            if (time() < ($tokens['expires_at'] - 300)) {
                return $tokens['access_token'];
            }
        }

        if (isset($tokens['refresh_token'])) {
            return $this->refreshToken($tokens['refresh_token']);
        }

        throw new Exception("Refresh token tidak tersedia di dalam file JSON.");
    }

    private function refreshToken($currentRefreshToken) {
        $url = "https://auth.spicychat.ai/oauth2/token";
        $postData = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $currentRefreshToken,
            'client_id'     => $this->clientId
        ]);

        $headers = [
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "Accept: application/json",
            "Origin: https://spicychat.ai",
            "Referer: https://spicychat.ai/",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => "",
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false 
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        // curl_close($ch); dihapus karena deprecated di PHP 8.5+

        if ($curlError) {
            throw new Exception("cURL Error: " . $curlError);
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $this->saveTokens($data);
            return $data['access_token'];
        }

        throw new Exception("Gagal refresh token (HTTP $httpCode): " . $response);
    }

    private function saveTokens($data) {
        $oldTokens = $this->loadTokens();
        
        $cacheData = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $oldTokens['refresh_token'],
            'expires_at'    => time() + (int)$data['expires_in']
        ];

        $put = file_put_contents($this->tokenFile, json_encode($cacheData, JSON_PRETTY_PRINT));
        
        if ($put === false) {
            throw new Exception("Gagal menyimpan file. Pastikan folder memiliki izin Tulis (Write permission).");
        }
    }

    private function loadTokens() {
        if (file_exists($this->tokenFile)) {
            $content = file_get_contents($this->tokenFile);
            return json_decode($content, true);
        }
        return false;
    }
}
