<?php
include 'index.php';
$user_data = loadUserData();
foreach ($user_data as $chat_id => $state) {
    if (!empty($state['json_data'])) {
        generateAndUpdateToken($chat_id, $state);
    }
}
saveUserData($user_data);
?>
