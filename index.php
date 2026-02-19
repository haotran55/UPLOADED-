<?php

define('BOT_TOKEN', '8527515808:AAGDfhZWxCNCSHCoKwHoRlg3sPrKrurq7eo');
define('TELEGRAM_API', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('DATA_DIR', __DIR__ . '/data');

define('GITHUB_TOKEN', getenv('GITHUB_TOKEN'));
define('GITHUB_REPO', 'haotran55/LikeFreeFiree');
define('GITHUB_FILE', 'token_vn.json');


// ================= BASIC =================

function sendMessage($chat_id, $text) {
    $url = TELEGRAM_API . "sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function httpGet($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function saveUserData($data) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    file_put_contents(DATA_DIR . '/users.json', json_encode($data));
}

function loadUserData() {
    if (file_exists(DATA_DIR . '/users.json')) {
        return json_decode(file_get_contents(DATA_DIR . '/users.json'), true);
    }
    return [];
}


// ================= UPDATE GITHUB =================

function updateGitHub($content) {

    $headers = [
        'Authorization: token ' . GITHUB_TOKEN,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: TelegramBot'
    ];

    $url = "https://api.github.com/repos/" . GITHUB_REPO . "/contents/" . GITHUB_FILE;
    $file = httpGet($url, $headers);

    if (!isset($file['sha'])) return false;

    $data = [
        "message" => "Auto token update",
        "content" => base64_encode($content),
        "sha" => $file['sha'],
        "branch" => "main"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true);
}


// ================= GENERATE TOKEN =================

function generateTokens($accounts) {

    $tokens = [];

    foreach ($accounts as $acc) {
        $uid = $acc['uid'];
        $password = $acc['password'];

        $api = "https://tranhao.vercel.app/token?uid=$uid&password=$password";
        $res = httpGet($api);

        if (!empty($res['token'])) {
            $tokens[] = ["token" => $res['token']];
        }
    }

    if (!empty($tokens)) {
        updateGitHub(json_encode($tokens, JSON_PRETTY_PRINT));
    }
}


// ================= CRON MODE =================

if (isset($_GET['cron'])) {

    $users = loadUserData();

    foreach ($users as $chat_id => $data) {

        if (!empty($data['json_data'])) {

            generateTokens($data['json_data']);

            sendMessage($chat_id,
                "⏰ 4 HOURS PASSED\n\n" .
                "🔄 Token updated successfully.\n\n" .
                "📂 Please send new uid.json to continue next cycle."
            );

            $users[$chat_id]['json_data'] = []; // reset
        }
    }

    saveUserData($users);
    exit;
}


// ================= TELEGRAM =================

$update = json_decode(file_get_contents("php://input"), true);
$chat_id = $update['message']['chat']['id'] ?? 0;
$file = $update['message']['document'] ?? null;

$users = loadUserData();

if (!isset($users[$chat_id])) {
    $users[$chat_id] = ["json_data" => []];
}

if ($file) {

    if (strtolower($file['file_name']) != "uid.json") {
        sendMessage($chat_id, "❌ File must be uid.json");
        exit;
    }

    $file_id = $file['file_id'];
    $file_info = httpGet(TELEGRAM_API . "getFile?file_id=" . $file_id);
    $file_path = $file_info['result']['file_path'];

    $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path;
    $json = file_get_contents($file_url);

    $data = json_decode($json, true);

    if (!$data) {
        sendMessage($chat_id, "❌ Invalid JSON format");
        exit;
    }

    $users[$chat_id]['json_data'] = $data;

    sendMessage($chat_id,
        "✅ File received.\n\n" .
        "⏳ System will auto update in 4 hours."
    );

    saveUserData($users);
}

?>
