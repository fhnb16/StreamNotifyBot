<?php
require_once 'config.php';
require_once 'functions.php';

// Handle incoming webhook requests from Twitch
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Log the incoming webhook request
    log_message("Incoming webhook request: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Check the Twitch EventSub Message Type from headers
    if (isset($_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_TYPE'])) {
        $message_type = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_TYPE'];

        // Handle webhook callback verification
        if ($message_type === 'webhook_callback_verification' && isset($data['challenge'])) {
            // Respond with the challenge to verify the webhook
            echo $data['challenge'];
            log_message("Responded to webhook verification challenge: " . $data['challenge']);
            exit;
        }

        // Handle stream.online or stream.offline or channel.update events
        if ($message_type === 'notification') {
            
            // Handle the event based on its type
            if (isset($data['subscription']['type'])) {
                $event_type = $data['subscription']['type'];
                log_message("Received Twitch event: " . $event_type);

                switch ($event_type) {
                    case 'stream.online':
                        handle_stream_event($data['event'], 'online');
                        break;

                    case 'stream.offline':
                        handle_stream_event($data['event'], 'offline');
                        break;

                    case 'channel.update':
                        handle_stream_event($data['event'], 'update');
                        break;

                    // Add more cases for other types of events
                }
            }
        }
    } else {
        log_message("Error: Twitch-Eventsub-Message-Type header missing");
    }
} else {
    log_message("Error: Received non-POST request");
}

function handle_stream_event($event, $type) {
    global $channels, $locales;

    // Load channels list from JSON file
    $channels = load_json('channels.json');

    // Load locale strings list from JSON file
    $locales = load_json('strings.json');

    if ($channels === null) {
        log_message("Error: Failed to load channels.json or the file is empty. Can't send telegram notiications.");
    }

    // Собираем все user_id в массив
    /*$user_ids = [];
    foreach ($channels as $channel) {
        if($channel['platform'] != "twitch") continue;
        $user_ids[] = $channel['broadcaster_id'];
    }
    // Получаем информацию для всех стримеров разом
    $streams_info = get_stream_info_by_user_ids($user_ids);
    // Обрабатываем данные для каждого стримера
    if (!$streams_info) {
        log_message("No streams are live for the given user IDs.");
    }*/

    foreach ($channels as $id => $channel) {
        if($channel['platform'] != "twitch") continue;
        if($channel['broadcaster_id'] !== $event['broadcaster_user_id']) continue;

        if ($channels[$id]['broadcaster_id'] === $event['broadcaster_user_id']) {
            if ($channels[$id]['name'] !== $event['broadcaster_user_name']) {
                $channels[$id]['name'] = $event['broadcaster_user_name'];
            }
            if ($channels[$id]['nickname'] !== $event['broadcaster_user_login']) {
                $channels[$id]['nickname'] = $event['broadcaster_user_login'];
            }

            $livestreamInfo = null;

            if(
                isset($event['category_name']) &&
                isset($event['title']) &&
                isset($event['viewer_count'])
            ) {

            }

            if($type == "update" || $type == "online"){
                //$livestreamInfo = $streams_info[$channels[$id]['broadcaster_id']];
                //$livestreamInfo = null;

                // Проходим по массиву $streams_info и ищем стрим с необходимым user_id
                /*foreach ($streams_info as $stream) {
                    if (isset($stream['user_id']) && $stream['user_id'] == $channel['broadcaster_id']) {
                        $livestreamInfo = $stream;
                        break; // Прерываем цикл, если нашли нужный стрим
                    }
                }*/
                $livestreamInfo = get_stream_info_by_user_id($channels[$id]['broadcaster_id']);
            }

            if($livestreamInfo !== null) {
                $channel['title'] = $livestreamInfo['title'];
                $channels[$id]['title'] = $livestreamInfo['title'];
                $channel['category'] = $livestreamInfo['game_name'];
                $channels[$id]['category'] = $livestreamInfo['game_name'];
                $channel['language'] = $livestreamInfo['language'];
                $channels[$id]['language'] = $livestreamInfo['language'];
                if(isset($livestreamInfo['viewer_count'])){
                    $channel['viewers'] = $livestreamInfo['viewer_count'];
                    $channels[$id]['viewers'] = $livestreamInfo['viewer_count'];
                }
            }
            
            if($event['category_name'] !== null) {
                $channel['category'] = $event['category_name'];
                $channels[$id]['category'] = $event['category_name'];
            }
            if($event['title'] !== null) {
                $channel['title'] = $event['title'];
                $channels[$id]['title'] = $event['title'];
            }
            if($event['language'] !== null) {
                $channel['language'] = $event['language'];
                $channels[$id]['language'] = $event['language'];
            }

            $startedAt = $event['started_at'];
            
            $broadcastingTime = "";
            if (isset($channels[$id]['startedAt'])) {
                $broadcastingTime = broadcastCalculatedTime($channels[$id]['startedAt'], $event['started_at']);
            } else {
                $broadcastingTime = "-1";
            }

            $message = "";

            $previewEnabled = false;

            switch($type) {
                case 'online':
                    $channels[$id]['startedAt'] = $startedAt;
                    $message = generateStreamMessage($channel, $locales, $livestreamInfo, $broadcastingTime, "online", $event);
                    $previewEnabled = false;
                    break;
                case 'update': 
                    $message = generateStreamMessage($channel, $locales, $livestreamInfo, $broadcastingTime, "update", $event);
                    if($broadcastingTime != "-1"){
                        $previewEnabled = true;
                    } else {
                        $previewEnabled = false;
                    }
                    break;
                case 'offline': 
                    $message = generateStreamMessage($channel, $locales, $livestreamInfo, $broadcastingTime, "offline", $event);
                    unset($channels[$id]['startedAt']);
                    unset($channels[$id]['viewers']);
                    $previewEnabled = false;
                    break;
            }

            // Notify main channel and other chats
            notify_channels($channels[$id], $message, $type, $previewEnabled);
        }
    }
    save_json('channels.json', $channels);
}
?>
