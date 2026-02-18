<?php

// Telegram Bot Token
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8527515808:AAGDfhZWxCNCSHCoKwHoRlg3sPrKrurq7eo');

// Telegram API URL
define('TELEGRAM_API', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// Directory for persistent storage
define('DATA_DIR', '/var/www/html/data');

// Function to send messages to Telegram
function sendMessage($chat_id, $text, $reply_markup = null) {
    $url = TELEGRAM_API . 'sendMessage';
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": SendMessage: $text\n", FILE_APPEND);
    return json_decode($response, true);
}

// Function to edit an existing message
function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
    $url = TELEGRAM_API . 'editMessageText';
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": EditMessage: $text\n", FILE_APPEND);
    return json_decode($response, true);
}

// Function to make HTTP GET request
function httpGet($url, $github_token = null) {
    $ch = curl_init($url);
    $headers = [
        'Accept: application/vnd.github.v3+json',
        'User-Agent: Telegram-Bot'
    ];
    if ($github_token) {
        $headers[] = 'Authorization: token ' . $github_token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": HTTP GET $url failed: $error\n", FILE_APPEND);
        return [];
    }
    file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": HTTP GET $url: " . json_encode($response) . "\n", FILE_APPEND);
    return json_decode($response, true);
}

// Function to validate GitHub token
function validateGitHubToken($token) {
    return preg_match('/^ghp_[a-zA-Z0-9]{36}$/', $token); // Matches ghp_ followed by 36 alphanumeric chars
}

// Function to validate repository name
function validateRepoName($repo) {
    return preg_match('/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+$/', $repo); // e.g., username/repository
}

// Function to validate file path
function validateFilePath($path) {
    return preg_match('/^[\w\/-]+\.json$/', $path); // e.g., path/to/file.json
}

// Function to validate uid.json content
function validateUidJson($json_data) {
    if (!is_array($json_data) || empty($json_data)) {
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": validateUidJson failed: Not an array or empty\n", FILE_APPEND);
        return false;
    }
    foreach ($json_data as $entry) {
        if (!isset($entry['uid'], $entry['password']) || empty($entry['uid']) || empty($entry['password'])) {
            file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": validateUidJson failed: Missing uid or password\n", FILE_APPEND);
            return false;
        }
    }
    return true;
}

// Function to fetch the authenticated user's email
function getUserEmail($github_token) {
    $url = "https://api.github.com/user/emails";
    $response = httpGet($url, $github_token);
    
    if (!empty($response)) {
        // Look for the primary email that is verified
        foreach ($response as $email_entry) {
            if (isset($email_entry['primary']) && $email_entry['primary'] && isset($email_entry['verified']) && $email_entry['verified']) {
                return $email_entry['email'];
            }
        }
        // If no primary email is found, return the first verified email
        foreach ($response as $email_entry) {
            if (isset($email_entry['verified']) && $email_entry['verified']) {
                return $email_entry['email'];
            }
        }
    }
    // Fallback: If no email is found or API fails, use a default email format (this may still fail if not associated)
    return 'user@users.noreply.github.com'; // This is a placeholder; ideally, we should handle this better
}

// Function to update file on GitHub
function updateGitHubFile($github_token, $repo, $file_path, $content, $commit_message) {
    $url = "https://api.github.com/repos/$repo/contents/$file_path";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $github_token,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: Telegram-Bot'
    ]);
    $response = curl_exec($ch);
    $file_data = json_decode($response, true);
    curl_close($ch);

    if (!isset($file_data['sha'])) {
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": GitHub file fetch failed: " . json_encode($file_data) . "\n", FILE_APPEND);
        return ['error' => 'File not found or invalid repository/token.'];
    }

    // Fetch the user's email using the GitHub token
    $user_email = getUserEmail($github_token);
    if (!$user_email) {
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": Failed to fetch user email\n", FILE_APPEND);
        return ['error' => 'Unable to fetch user email for commit.'];
    }

    $url = "https://api.github.com/repos/$repo/contents/$file_path";
    $data = [
        'message' => $commit_message,
        'content' => base64_encode($content),
        'sha' => $file_data['sha'],
        'branch' => 'main',
        'committer' => [
            'name' => 'NR_CODEX Bot',
            'email' => $user_email // Use the fetched email
        ],
        'author' => [
            'name' => 'NR_CODEX Bot',
            'email' => $user_email // Use the fetched email
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $github_token,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: Telegram-Bot'
    ]);
    $response = curl_exec($ch);
    $result = json_decode($response, true);
    curl_close($ch);

    file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": GitHub Update: " . json_encode($result) . "\n", FILE_APPEND);
    return $result;
}

// Function to format datetime to 12-hour format with AM/PM
function formatDateTime12Hour($datetime) {
    return date('Y-m-d h:i:s A', strtotime($datetime));
}

// Function to generate and update JWT tokens for all uid/password pairs
function generateAndUpdateToken($chat_id, $state, $message_id = null) {
    if (empty($state['json_data'])) {
        sendMessage($chat_id, "🚫 No credentials available to generate tokens!");
        return;
    }

    $start_time = microtime(true);
    $token_data = [];
    $errors = [];
    $invalid = 0;

    foreach ($state['json_data'] as $cred) {
        $uid = $cred['uid'];
        $password = $cred['password'];
        $api_url = "https://tranhao.vercel.app/token?uid=$uid&password=$password";
        $response = httpGet($api_url);

        if (isset($response['token']) && !empty($response['token'])) {
            $token_data[] = ['token' => $response['token']];
        } else {
            $errors[] = "Failed to generate token for UID: $uid";
            $invalid++;
        }
    }

    $total_accounts = count($state['json_data']);
    $successful = count($token_data);
    $failed = $invalid;
    $time_taken = round((microtime(true) - $start_time) / 60, 2); // Convert to minutes
    $next_update = date('Y-m-d H:i:s', strtotime('+7 hours'));
    $next_update_formatted = formatDateTime12Hour($next_update);

    if (!empty($token_data)) {
        $commit_message = "Update tokens by @NR_CODEX\n\nUpdate tokens by auto update bot credit Nilay Join Telegram - @NR_CODEX";
        $result = updateGitHubFile(
            $state['github_token'],
            $state['repo'],
            $state['file_path'],
            json_encode($token_data, JSON_PRETTY_PRINT),
            $commit_message
        );

        if (isset($result['content'])) {
            $message = "🔢 Total Accounts: $total_accounts\n" .
                       "✅ Successful: $successful\n" .
                       "❌ Failed: $failed\n" .
                       "⚠️ Invalid: $invalid\n" .
                       "⏱️ Time Taken: $time_taken minutes\n" .
                       "🌐 APIs Used: 1\n" .
                       "🔥 Next Update On: $next_update_formatted\n\n" .
                       "🤖 Bot codes by @nr_codex\n" .
                       "🔑 Jwt api by @I_SHOW_akiru\n" .
                       "📲 Join Telegram @nr_codex";

            $reply_markup = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 Generate Again', 'callback_data' => 'generate_again']
                    ]
                ]
            ];

            if ($message_id) {
                editMessage($chat_id, $message_id, $message, $reply_markup);
            } else {
                $response = sendMessage($chat_id, $message, $reply_markup);
                if (isset($response['result']['message_id'])) {
                    $state['last_message_id'] = $response['result']['message_id'];
                }
            }
        } else {
            sendMessage($chat_id, "❌ Error updating GitHub: " . ($result['error'] ?? 'Unknown error'));
        }
    } else {
        $message = "🔢 Total Accounts: $total_accounts\n" .
                   "✅ Successful: 0\n" .
                   "❌ Failed: $failed\n" .
                   "⚠️ Invalid: $invalid\n" .
                   "⏱️ Time Taken: $time_taken minutes\n" .
                   "🌐 APIs Used: 1\n" .
                   "🔥 Next Update On: $next_update_formatted\n\n" .
                   "🤖 Bot codes by @nr_codex\n" .
                   "🔑 Jwt api by @I_SHOW_akiru\n" .
                   "📲 Join Telegram @nr_codex";

        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Generate Again', 'callback_data' => 'generate_again']
                ]
            ]
        ];

        if ($message_id) {
            editMessage($chat_id, $message_id, $message, $reply_markup);
        } else {
            $response = sendMessage($chat_id, $message, $reply_markup);
            if (isset($response['result']['message_id'])) {
                $state['last_message_id'] = $response['result']['message_id'];
            }
        }
    }
}

// Function to save user data
function saveUserData($user_data) {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    file_put_contents(DATA_DIR . '/users.json', json_encode($user_data, JSON_PRETTY_PRINT));
}

// Function to load user data
function loadUserData() {
    if (file_exists(DATA_DIR . '/users.json')) {
        return json_decode(file_get_contents(DATA_DIR . '/users.json'), true);
    }
    return [];
}

// Load user data
$user_data = loadUserData();

// Handle incoming Telegram updates
$update = json_decode(file_get_contents('php://input'), true);

// Extract chat ID and message
$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? 0;
$message = $update['message']['text'] ?? '';
$file = $update['message']['document'] ?? null;
$callback_query = $update['callback_query'] ?? null;

// Initialize user state
if (!isset($user_data[$chat_id])) {
    $user_data[$chat_id] = [
        'step' => 'start',
        'github_token' => '',
        'repo' => '',
        'file_path' => '',
        'json_data' => [],
        'last_message_id' => null
    ];
}

$state = &$user_data[$chat_id];

// Handle callback queries (button clicks)
if ($callback_query) {
    $callback_data = $callback_query['data'];
    $message_id = $callback_query['message']['message_id'];

    if ($callback_data === 'generate_again') {
        generateAndUpdateToken($chat_id, $state, $message_id);
    }

    saveUserData($user_data);
    httpGet(TELEGRAM_API . "answerCallbackQuery?callback_query_id=" . $callback_query['id']);
    exit;
}

// Log interaction
file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": Chat ID: $chat_id, Step: {$state['step']}, Message: " . json_encode($message) . ", File: " . json_encode($file) . "\n", FILE_APPEND);

// Handle commands and steps
if ($message === '/start') {
    $state['step'] = 'ask_github_token';
    $state['github_token'] = '';
    $state['repo'] = '';
    $state['file_path'] = '';
    $state['json_data'] = [];
    $state['last_message_id'] = null;
    sendMessage($chat_id, "🚀 Let's get started! Please send your GitHub Personal Access Token:");
    saveUserData($user_data);
} elseif ($state['step'] === 'ask_github_token' && $message) {
    if (validateGitHubToken($message)) {
        $state['github_token'] = $message;
        $state['step'] = 'ask_repo';
        sendMessage($chat_id, "✅ Awesome, token received! Now, please send the GitHub repository name in the format username/repository:");
        saveUserData($user_data);
    } else {
        sendMessage($chat_id, "❌ Oops, that token doesn't look right. It should start with 'ghp_' and be 40 characters long. Try again:");
    }
} elseif ($state['step'] === 'ask_repo' && $message) {
    if (validateRepoName($message)) {
        $state['repo'] = $message;
        $state['step'] = 'ask_file_path';
        sendMessage($chat_id, "📂 Great! Now, please send the path to the JWT file (must end with .json) like -token_ind.json");
        saveUserData($user_data);
    } else {
        sendMessage($chat_id, "❌ Invalid repository name. Please use the format username/repository. Try again:");
    }
} elseif ($state['step'] === 'ask_file_path' && $message) {
    if (validateFilePath($message)) {
        $state['file_path'] = $message;
        $state['step'] = 'ask_json';
        sendMessage($chat_id, "📄 <b>Almost there!</b> Please upload a valid <b>uid.json</b> file with this format: ✅\n<code>\n[\n  {\"uid\": \"1234567890\", \"password\": \"PASSWORD1\"},\n  {\"uid\": \"0987654321\", \"password\": \"PASSWORD2\"}\n]\n</code>");
        saveUserData($user_data);
    } else {
        sendMessage($chat_id, "❌ Invalid file path. It should end with .json (e.g., path/to/file.json). Try again:");
    }
} elseif ($state['step'] === 'ask_json' && $file) {
    if (strtolower($file['file_name']) !== 'uid.json') {
        sendMessage($chat_id, "❌ Please upload a file named 'uid.json'. Try again:");
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": Invalid file name: " . $file['file_name'] . "\n", FILE_APPEND);
        return;
    }

    $file_id = $file['file_id'];
    $file_info = httpGet(TELEGRAM_API . 'getFile?file_id=' . $file_id);
    if (!$file_info || !isset($file_info['ok']) || !$file_info['ok']) {
        sendMessage($chat_id, "❌ Error retrieving file info from Telegram. Please try again:");
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": getFile API failed: " . json_encode($file_info) . "\n", FILE_APPEND);
        return;
    }

    $file_path = $file_info['result']['file_path'];
    $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path;
    $json_content = file_get_contents($file_url);
    if ($json_content === false) {
        sendMessage($chat_id, "❌ Error downloading uid.json from Telegram. Please try again:");
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": Failed to download file: $file_url\n", FILE_APPEND);
        return;
    }

    $json_data = json_decode($json_content, true);
    if ($json_data === null) {
        sendMessage($chat_id, "❌ Invalid JSON in uid.json. Ensure it’s a valid JSON array like:\n<code>\n[\n  {\"uid\": \"...\", \"password\": \"...\"}\n]\n</code>");
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": JSON decode failed: " . json_last_error_msg() . "\n", FILE_APPEND);
        return;
    }

    if (validateUidJson($json_data)) {
        $state['json_data'] = $json_data;
        $state['step'] = 'process';
        generateAndUpdateToken($chat_id, $state);
        saveUserData($user_data);
    } else {
        sendMessage($chat_id, "❌ Invalid uid.json content. It must be a JSON array like:\n<code>\n[\n  {\"uid\": \"...\", \"password\": \"...\"}\n]\n</code>");
        file_put_contents(DATA_DIR . '/log.txt', date('Y-m-d H:i:s') . ": Invalid JSON content: " . json_encode($json_data) . "\n", FILE_APPEND);
    }
} elseif ($state['step'] !== 'start') {
    $step_prompts = [
        'ask_github_token' => 'GitHub Personal Access Token',
        'ask_repo' => 'GitHub repository name (username/repository)',
        'ask_file_path' => 'JWT file path (ending with .json)',
        'ask_json' => 'valid uid.json file'
    ];
    sendMessage($chat_id, "🙈 Please provide the {$step_prompts[$state['step']]}. Or use /start to begin again! 🚀");
} else {
    sendMessage($chat_id, "🌟 Let's get rolling! Please use /start to begin. 🚀");
}

?>
