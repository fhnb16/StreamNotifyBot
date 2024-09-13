<?php
require_once 'config.php';
require_once 'functions.php';

$requestEncoded = file_get_contents('php://input');
$request = json_decode($requestEncoded, true);
$user_id = intval($request['user_id']) ?? '';
$is_admin = boolval($request['is_admin']) ?? false;
$channels_list = boolval($request['justList']) ?? false;
$sender_id = intval($request['sender_id']) ?? '';

if($is_admin && $sender_id != ADMIN_ID){
    echo json_encode(['status' => 'error', 'result' => 'False admin']);
    exit();
}

log_message("Request to '".$_SERVER['REQUEST_URI']."', with data: `" . $requestEncoded ."`");

$channels = load_json('channels.json');
$response = [];

// Если администратор, загружаем все каналы
if ($is_admin) {
    $response['channels'] = $channels;
} else {
    if ($channels_list) {
        $userNotify = [];
        foreach ($channels as $channel) {
            $userNotify[] = [
                "broadcaster_id" => $channel['broadcaster_id'],
                "name" => $channel['name']
            ];
        }
        $response['channels'] = $userNotify;
    } else {
        // Если обычный пользователь, фильтруем подписки
        $userNotify = [];
        foreach ($channels as $channel) {
            foreach ($channel['notify'] as $key => $value) {
                if (explode(':', $key)[0] == $user_id) {
                    $userNotify[] = [
                        "broadcaster_id" => $channel['broadcaster_id'],
                        "name" => $channel['name'],
                        "notify" => [$key => $value]
                    ];
                }
            }
        }
        $response['channels'] = $userNotify;
    }
}

echo json_encode($response);
