<?php
require_once 'config.php';
require_once 'functions.php';

$requestEncoded = file_get_contents('php://input');
$request = json_decode($requestEncoded, true);
$user_id = intval($request['user_id']) ?? '';
$broadcaster_id = htmlspecialchars($request['broadcaster_id']) ?? '';
$subscriber_id = intval($request['subscriber_id']) ?? '';
$is_admin = boolval($request['is_admin']) ?? false;
$sender_id = intval($request['sender_id']) ?? '';

if($is_admin && $sender_id != ADMIN_ID){
    echo json_encode(['status' => 'error', 'result' => 'False admin']);
    exit();
}

log_message("Request to '".$_SERVER['REQUEST_URI']."', with data: `" . $requestEncoded ."`");

if(empty($subscriber_id) || empty($broadcaster_id)) { echo json_encode(['status' => 'error', 'result' => 'subscriber id or broadcaster id is empty']); exit(); }

$channels = load_json('notifications.json');

// Ищем канал и удаляем подписку
foreach ($channels as &$channel) {
    if ($channel['broadcaster_id'] == $broadcaster_id) {
        foreach ($channel['notify'] as $key => $value) {
            if ($key == $subscriber_id) {
                if($is_admin) unset($channel['notify'][$key]);
            }
        }
        break;
    }
}

save_json('notifications.json', $channels);

echo json_encode(['status' => 'ok']);
