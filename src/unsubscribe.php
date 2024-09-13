<?php
require_once 'config.php';
require_once 'functions.php';

$requestEncoded = file_get_contents('php://input');
$request = json_decode($requestEncoded, true);
$user_id = intval($request['user_id']) ?? '';
$broadcaster_id = htmlspecialchars($request['broadcaster_id']) ?? '';

log_message("Request to '".$_SERVER['REQUEST_URI']."', with data: `" . $requestEncoded ."`");

if(empty($user_id) || empty($broadcaster_id)) { echo json_encode(['status' => 'error', 'result' => 'user id or broadcaster id is empty']); exit(); }

$channels = load_json('channels.json');

// Ищем канал и удаляем подписку
foreach ($channels as &$channel) {
    if ($channel['broadcaster_id'] == $broadcaster_id) {
        foreach ($channel['notify'] as $key => $value) {
            if (explode(':', $key)[0] == $user_id) {
                unset($channel['notify'][$key]);
            }
        }
        break;
    }
}

save_json('channels.json', $channels);

echo json_encode(['status' => 'ok']);
