<?php
require_once 'config.php';
require_once 'functions.php';

$requestEncoded = file_get_contents('php://input');
$request = json_decode($requestEncoded, true);
$user_id = intval($request['user_id']) ?? '';
$broadcaster_id = htmlspecialchars($request['broadcaster_id']) ?? '';
$user_data = htmlspecialchars($request['user_data']) ?? '';
$sub_level = htmlspecialchars($request['sub_level']) ?? 'online';
$sub_edit = boolval($request['sub_edit']) ?? false;
$is_admin = boolval($request['is_admin']) ?? false;
$sender_id = intval($request['sender_id']) ?? '';

if($is_admin && $sender_id != ADMIN_ID){
    echo json_encode(['status' => 'error', 'result' => 'False admin']);
    exit();
}

log_message("Request to '".$_SERVER['REQUEST_URI']."', with data: `" . $requestEncoded ."`");

if(empty($user_id) || empty($broadcaster_id) || empty($user_data)) { echo json_encode(['status' => 'error', 'result' => 'user id or info, or broadcaster id is empty']); exit(); }

$channels = load_json('notifications.json');

// Ищем канал и удаляем добавляем
foreach ($channels as &$channel) {
    if ($channel['broadcaster_id'] == $broadcaster_id) {
        foreach ($channel['notify'] as $key => $value) {
            if ($key == $user_id) {
                if($sub_edit && $is_admin) {
                    unset($channel['notify'][$key]);
                    $channel['notify'][$user_id]['about'] = $user_data;
                    $channel['notify'][$user_id]['type'] = $sub_level;
                    save_json('notifications.json', $channels);
                    echo json_encode(['status' => 'ok']);
                    exit();
                } else {
                    echo json_encode(['status' => 'error', 'result' => 'User ' . $user_id . ' already subscribed to notifications from broadcaster: ' . $broadcaster_id]);
                    exit();
                }
            }
        }
        $meta = !$is_admin ? meta() : "";
        $channel['notify'][$user_id]['about'] = $user_data . $meta;
        $channel['notify'][$user_id]['type'] = $sub_level;
        break;
    }
}

save_json('notifications.json', $channels);

echo json_encode(['status' => 'ok']);
