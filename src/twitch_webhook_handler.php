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

    foreach ($channels as $id => $channel) {
        if ($channels[$id]['broadcaster_id'] === $event['broadcaster_user_id']) {
            if ($channels[$id]['name'] !== $event['broadcaster_user_name']) {
                $channels[$id]['name'] = $event['broadcaster_user_name'];
            }
            if ($channels[$id]['nickname'] !== $event['broadcaster_user_login']) {
                $channels[$id]['nickname'] = $event['broadcaster_user_login'];
            }

            $livestreamInfo = null;

            if($type == "update"){
                $livestreamInfo = get_stream_info_by_user_id($channels[$id]['broadcaster_id']);
            }

            $channel['title'] = $livestreamInfo['title'];
            $channels[$id]['title'] = $livestreamInfo['title'];
            $channel['category'] = $livestreamInfo['game_name'];
            $channels[$id]['category'] = $livestreamInfo['game_name'];
            $startedAt = $event['started_at'];
            
            $broadcastingTime = "";
            if (isset($channels[$id]['startedAt'])) {
                $broadcastingTime = broadcastCalculatedTime($channels[$id]['startedAt'], $event['started_at']);
            } else {
                $broadcastingTime = "-1";
            }

            $message = "";


            switch($type) {
                case 'online':
                    $channels[$id]['startedAt'] = $startedAt;
                    $message = generateStreamMessage($channel, $locales, $livestreamInfo, $broadcastingTime, "online", $event);
                    break;
                case 'update': 
                    $message = generateStreamMessage($channel, $locales, $livestreamInfo, $broadcastingTime, "update", $event);
                    break;
                case 'offline': 
                    $message = generateStreamMessage($channel, $locales, $livestreamInfo, $broadcastingTime, "offline", $event);
                    unset($channels[$id]['startedAt']);
                    break;
            }

/*
            if ($type === 'live') {
                $channels[$id]['title'] = $title;
                $channels[$id]['category'] = $category;
                $channels[$id]['startedAt'] = $startedAt;
                $message =
                    "🚀 <a href='https://twitch.tv/{$channels[$id]['nickname']}'><u>{$channels[$id]['name']}</u></a> has started streaming!".PHP_EOL.
                    "Category: <b>{$livestreamInfo['game_name']}</b>.".PHP_EOL.
                    "Title: <i>{$livestreamInfo['title']}</i>.";
            } elseif ($type === 'update') {
                $channels[$id]['title'] = $title;
                $channels[$id]['category'] = $category;
                $broadcastingTime = "";
                if (isset($channels[$id]['startedAt'])) {
                    $broadcastingTime = broadcastCalculatedTime($channels[$id]['startedAt'], $eventTime);
                } else {
                    $broadcastingTime = "Unknown";
                }
                $message = 
                    "🎮 <a href='https://twitch.tv/{$channels[$id]['nickname']}'><u>{$channels[$id]['name']}</u></a> stream time is ".$broadcastingTime.PHP_EOL.
                    "Category: <b>{$livestreamInfo['game_name']}</b>.".PHP_EOL.
                    "Title: <i>{$livestreamInfo['title']}</i>.".PHP_EOL.
                    "<b>{$livestreamInfo['viewer_count']}</b> viewers.";
            } elseif ($type === 'ends') {
                if (isset($channels[$id]['startedAt'])) {
                    $broadcastingTime = broadcastCalculatedTime($channels[$id]['startedAt'], $eventTime);
                } else {
                    $broadcastingTime = "Unknown";
                }
                unset($channels[$id]['startedAt']);
                $message = 
                    "😞 <a href='https://twitch.tv/{$channels[$id]['nickname']}'><u>{$channels[$id]['name']}</u></a> ended stream with <b>{$livestreamInfo['viewer_count']}</b> viewers.".PHP_EOL.
                    "Broadcast duration: ".$broadcastingTime;
            }
*/
            // Notify main channel and other chats
            notify_channels($channels[$id], $message, $type);
        }
    }
    save_json('channels.json', $channels);
}

function notify_channels($channel, $message, $type) {
    log_message("Sending notification to channels for {$channel['nickname']}: {$message}");
    
    if (defined('MAIN_CHAT_ID') && null !== MAIN_CHAT_ID && !empty(MAIN_CHAT_ID)) {
        log_message("Send message to main hub. Telegram Request Result: " . send_telegram_message(MAIN_CHAT_ID, $message)); // Notify main chat
    }

    foreach ($channel['notify'] as $chat_id => $notify_type) {
        $chat_id = explode(':', $chat_id)[0];
        $chat_name = explode(':', $chat_id)[1];
        if ($notify_type === 'all') {
            log_message("Send message to '" . $chat_name . "'. Telegram Request Result: " . send_telegram_message($chat_id, $message));
        }
        if ($notify_type === 'updates' && ($type == 'update' || $type == 'offline' )) {
            log_message("Send message to '" . $chat_name . "'. Telegram Request Result: " . send_telegram_message($chat_id, $message));
        }
        if ($notify_type === 'live' && $type == 'online') {
            log_message("Send message to '" . $chat_name . "'. Telegram Request Result: " . send_telegram_message($chat_id, $message));
        }
    }
}
?>
