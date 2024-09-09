<?php
// Register Twitch EventSub subscription webhook
function register_twitch_eventsub($broadcaster_id, $event_type = "stream.online") {  // 'stream.offline' or 'channel.update'
    $url = 'https://api.twitch.tv/helix/eventsub/subscriptions';
    $postData = [
        'type' => $event_type,
        'version' => TWITCH_EVENTSUB_VERSION,
        'condition' => [
            'broadcaster_user_id' => $broadcaster_id
        ],
        'transport' => [
            'method' => 'webhook',
            'callback' => TWITCH_CALLBACK_URL,
            'secret' => 'StreamNotifyBot' // A secret you define to verify the payloads
        ]
    ];

    $headers = [
        'Client-ID: ' . TWITCH_CLIENT_ID,
        'Authorization: Bearer ' . get_twitch_access_token(),
        'Content-Type: application/json'
    ];

    log_message("EventSub subscription request for broadcaster ID {$broadcaster_id}: ");
    $response = make_post_request($url, json_encode($postData), $headers);

    return json_decode($response, true);
}


// Send Telegram message
function send_telegram_message($chat_id, $message) {
    $url = TELEGRAM_API_URL . '/sendMessage';
    $getData = [
        'chat_id' => $chat_id,
        'parse_mode' => "HTML",
        //'disable_web_page_preview' => true,
        'text' => $message
    ];

    // Создаем строку запроса
    $queryString = http_build_query($getData);

    // Объединяем базовый URL и строку запроса
    $finalUrl = $url . "?" . $queryString;

    log_message("Telegram message sent to chat {$chat_id} with url `" . $finalUrl . "`");
    $response = make_get_request($finalUrl);

    return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Get Twitch access token (no changes)
/*function get_twitch_access_token() {
    $url = 'https://id.twitch.tv/oauth2/token';
    $postData = [
        'client_id' => TWITCH_CLIENT_ID,
        'client_secret' => TWITCH_SECRET,
        'grant_type' => 'client_credentials'
    ];

    log_message("Twitch access token response: ");
    $response = make_post_request($url, http_build_query($postData));

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}*/

// Функция для получения и кэширования токена
function get_twitch_access_token() {
    $token_file = '.twitch_token';

    // Проверяем наличие кэшированного токена
    if (file_exists($token_file)) {
        $token_data = json_decode(file_get_contents($token_file), true);
        if (isset($token_data['access_token']) && isset($token_data['expires_at'])) {
            // Если токен еще не истек, возвращаем его
            if (time() < $token_data['expires_at']) {
                log_message("Using cached Twitch token.");
                return $token_data['access_token'];
            }
        }
    }

    // Если токен истек или не найден, запрашиваем новый
    $new_token = json_decode(request_new_twitch_access_token(), true);

    if ($new_token) {
        // Кэшируем новый токен и сохраняем время его истечения
        $token_data = [
            'access_token' => $new_token['access_token'],
            'expires_at' => time() + $new_token['expires_in'] - 10 // небольшой буфер
        ];
        file_put_contents($token_file, json_encode($token_data, JSON_PRETTY_PRINT));
        log_message("New Twitch token obtained and cached.");
        return $new_token['access_token'];
    }

    log_message("Failed to obtain new Twitch token.");
    return null;
}

// Функция для запроса нового токена у Twitch
function request_new_twitch_access_token() {
    $url = "https://id.twitch.tv/oauth2/token";
    $params = [
        'client_id' => TWITCH_CLIENT_ID,
        'client_secret' => TWITCH_SECRET,
        'grant_type' => 'client_credentials'
    ];

    // Используем make_post_request для выполнения POST запроса
    $response = make_post_request($url, $params);

    if ($response) {
        log_message("Twitch token response: " . json_encode($response));
    } else {
        log_message("Failed to get Twitch token.");
    }

    return $response;
}

// Get broadcaster ID by Twitch login name
function get_broadcaster_id($nickname) {
    $url = 'https://api.twitch.tv/helix/users?login=' . $nickname;

    $headers = [
        'Client-ID: ' . TWITCH_CLIENT_ID,
        'Authorization: Bearer ' . get_twitch_access_token()
    ];

    log_message("Broadcaster ID request for {$nickname}:");
    $response = make_get_request($url, $headers);

    $data = json_decode($response, true);
    return $data['data'][0]['id'] ?? null;
}

// Make GET request with cURL
/*function make_get_request($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    log_message("GET request to {$url}, Response: " . $response);

    if (curl_errno($ch)) {
        log_message("cURL error: " . curl_error($ch));
    }

    curl_close($ch);
    return $response;
}*/

// Helper function to perform GET requests
function make_get_request($url, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if($httpCode >= 400) error_message("Error ". $httpCode . ": Url" . $url . ", Response: " . $response);
    curl_close($ch);
    return $response;
}

// Make POST request with cURL (unchanged)
function make_post_request($url, $data, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if($httpCode >= 400) error_message("Error ". $httpCode . ": Url" . $url . ", Response: " . $response);
    log_message("POST request to {$url}, Data: " . $data . ", Response: " . $response);
    
    if (curl_errno($ch)) {
        log_message("cURL error: " . curl_error($ch));
    }

    curl_close($ch);
    return $response;
}

// Set Telegram webhook to the specified URL
function set_telegram_webhook($url) {
    $webhookUrl = TELEGRAM_API_URL . '/setWebhook';
    $data = [
        'url' => $url
    ];

    $response = json_decode(make_post_request($webhookUrl, $data), true);

    if (isset($response['ok']) && $response['ok'] === true) {
        log_message("Telegram webhook successfully set to {$url}");
        return true;
    } else {
        log_message("Failed to set Telegram webhook. Response: " . json_encode($response));
        return false;
    }
}

// Set Telegram webhook to the specified URL
function remove_telegram_webhook($url) {
    $webhookUrl = TELEGRAM_API_URL . '/removeWebhook';
    $data = [
        'url' => $url
    ];

    $response = json_decode(make_post_request($webhookUrl, $data), true);

    if (isset($response['ok']) && $response['ok'] === true) {
        log_message("Telegram webhook {$url} was removed");
        return true;
    } else {
        log_message("Failed to remove Telegram webhook. Response: " . json_encode($response));
        return false;
    }
}

// Log message to file
function log_message($message) {
    if (!DEBUG) { return;}
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents(LOG_FILE, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Log error to file
function error_message($message) {
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents(ERR_FILE, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Load JSON from file safely
function load_json($file) {
    if (!file_exists($file)) {
        log_message("Error: JSON file {$file} not found.");
        return null;
    }

    $data = file_get_contents($file);
    if ($data === false) {
        log_message("Error: Failed to read JSON file {$file}.");
        return null;
    }

    $json = json_decode($data, true);
    if ($json === null) {
        log_message("Error: Invalid JSON format in {$file}.");
        return null;
    }

    return $json;
}

// Save JSON to file safely with pretty print and file locking
function save_json($file, $data) {
    if ($data === null) {
        error_message("Error: Attempted to save null data to JSON file {$file}. Operation aborted.");
        return false;
    }

    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonData === false) {
        error_message("Error: Failed to encode data as JSON for file {$file}.");
        return false;
    }

    // Open file for writing, 'c' mode opens the file for writing, creates the file if it does not exist
    $fp = fopen($file, 'c');
    if (!$fp) {
        error_message("Error: Failed to open file {$file} for writing.");
        return false;
    }

    // Try to lock the file for exclusive writing
    if (!flock($fp, LOCK_EX)) {
        error_message("Error: Unable to lock file {$file} for writing.");
        fclose($fp);
        return false;
    }

    // Clear file contents before writing new data
    ftruncate($fp, 0);

    // Write the JSON data to the file
    if (fwrite($fp, $jsonData) === false) {
        error_message("Error: Failed to write JSON data to file {$file}.");
        flock($fp, LOCK_UN); // Release the lock
        fclose($fp);
        return false;
    }

    // Unlock the file
    flock($fp, LOCK_UN);
    
    // Close the file
    fclose($fp);

    log_message("JSON file {$file} successfully saved.");
    return true;
}

// Get all active Twitch EventSub subscriptions with pagination handling
function get_all_eventsub_subscriptions() {
    $url = 'https://api.twitch.tv/helix/eventsub/subscriptions';
    $subscriptions = [];
    $pagination = null;

    do {
        $fullUrl = $url;
        if ($pagination) {
            $fullUrl .= '?after=' . $pagination;
        }

        $headers = [
            'Client-ID: ' . TWITCH_CLIENT_ID,
            'Authorization: Bearer ' . get_twitch_access_token(),
            'Content-Type: application/json'
        ];

        $response = make_get_request($fullUrl, $headers);

        $data = json_decode($response, true);

        if (isset($data['data'])) {
            // Append received subscriptions to the final list
            $subscriptions = array_merge($subscriptions, $data['data']);
        }

        // Handle pagination
        if (isset($data['pagination']['cursor'])) {
            $pagination = $data['pagination']['cursor'];
        } else {
            $pagination = null;
        }
    } while ($pagination);

    log_message("Twitch EventSub subscriptions request total: " . $data['data']);

    return json_encode($subscriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Get all inactive Twitch EventSub subscriptions with pagination handling
function get_disabled_eventsub_subscriptions() {
    $url = 'https://api.twitch.tv/helix/eventsub/subscriptions';
    $subscriptions = [];
    $pagination = null;

    do {
        $fullUrl = $url;
        if ($pagination) {
            $fullUrl .= '?after=' . $pagination;
        }

        $headers = [
            'Client-ID: ' . TWITCH_CLIENT_ID,
            'Authorization: Bearer ' . get_twitch_access_token(),
            'Content-Type: application/json'
        ];

        $response = make_get_request($fullUrl, $headers);

        $data = json_decode($response, true);

        if (isset($data['data'])) {
            // Фильтрация подписок по статусу (оставляем только те, которые не имеют status: 'enabled')
            $filteredSubscriptions = array_filter($data['data'], function ($subscription) {
                return $subscription['status'] !== 'enabled';
            });

            // Добавляем отфильтрованные подписки в итоговый список
            $subscriptions = array_merge($subscriptions, $filteredSubscriptions);
        }

        // Handle pagination
        if (isset($data['pagination']['cursor'])) {
            $pagination = $data['pagination']['cursor'];
        } else {
            $pagination = null;
        }
    } while ($pagination);

    log_message("Twitch EventSub subscriptions request total (disabled): " . count($subscriptions));

    return json_encode($subscriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Cancel all Twitch EventSub subscriptions
function cancel_all_eventsub_subscriptions() {
    $subscriptions = json_decode(get_all_eventsub_subscriptions(), true);

    if (!empty($subscriptions)) {
        foreach ($subscriptions as $subscription) {
            unsubscribe_by_id($subscription['id']);

        }
    } else {
        log_message("No active subscriptions to delete.");
    }

    // Update JSON file by removing webhook IDs and expiration times
    $json_data = load_json('channels.json');
    if ($json_data) {
        foreach ($json_data as &$streamer) {
            unset($streamer['webhook_online_id']);
            unset($streamer['webhook_offline_id']);
            unset($streamer['webhook_updates_id']);
            unset($streamer['webhook_expires_at']);
        }
        save_json('channels.json', $json_data);
    }

    log_message("All subscriptions have been cancelled and webhook data removed from JSON.");
}

// Cancel disabled Twitch EventSub subscriptions
function cancel_disabled_eventsub_subscriptions() {
    $subscriptions = json_decode(get_disabled_eventsub_subscriptions(), true);

    if (!empty($subscriptions)) {
        foreach ($subscriptions as $subscription) {
            unsubscribe_by_id($subscription['id']);

        }
    } else {
        log_message("No active subscriptions to delete.");
    }

    // Update JSON file by removing webhook IDs and expiration times
    $json_data = load_json('channels.json');
    if ($json_data) {
        foreach ($json_data as &$streamer) {
            unset($streamer['webhook_online_id']);
            unset($streamer['webhook_offline_id']);
            unset($streamer['webhook_updates_id']);
            unset($streamer['webhook_expires_at']);
        }
        save_json('channels.json', $json_data);
    }

    log_message("All subscriptions have been cancelled and webhook data removed from JSON.");
}

function unsubscribe_by_id($subscription_id) {
    $url = "https://api.twitch.tv/helix/eventsub/subscriptions?id=$subscription_id";

    $headers = [
        'Client-ID: ' . TWITCH_CLIENT_ID,
        'Authorization: Bearer ' . get_twitch_access_token(),
    ];

    // Use DELETE method to cancel the subscription
    $response = make_delete_request($url, $headers);
    echo "Delete sub id: " .$subscription_id . " - " . $response["subscription"]["status"] . "<br/>";
    log_message("Deleted subscription ID: " . $subscription_id . ", Response: " . $response);
}

// Helper function to perform DELETE requests
function make_delete_request($url, $headers) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if($httpCode >= 400) error_message("Error ". $httpCode . ": Url" . $url . ", Response: " . $response);
    curl_close($ch);
    return $response;
}

// Get stream information, including viewer count, for a given user_id
function get_stream_info_by_user_id($user_id) {
    $url = "https://api.twitch.tv/helix/streams?user_id=" . urlencode($user_id);
    $headers = [
        'Client-ID: ' . TWITCH_CLIENT_ID,
        'Authorization: Bearer ' . get_twitch_access_token(),
    ];

    $response = make_get_request($url, $headers);
    $data = json_decode($response, true);
    $data = $data['data'][0];

    log_message("Get user: " . $user_id . " info, Response: " . json_encode($data));

    // Check if the response contains stream data
    if (isset($data)) {
        return $data; // Return stream data, including viewer count
    } else {
        return null; // No stream found for this user_id
    }
}

function broadcastCalculatedTime($start_time, $end_time){
    // Create DateTime objects
    $start = new DateTime($start_time);
    $end = new DateTime($end_time);

    // Calculate difference
    $interval = $end->diff($start);

    // Format the result
    return $interval->format('%H:%I:%S');
}

function replaceMultipleStrings($sourceString, $replaceArray) {
    try {
        return strtr($sourceString, $replaceArray);
    } catch (Exception $e) {
        log_message("String replace error: " . $e->getMessage());
        return $sourceString;
    }
}

// Основная функция для формирования сообщения
function generateStreamMessage($channel, $locales, $livestreamInfo, $StreamTime, $status, $event) {

    $category = $lang = $title = $viewers = "";

    if($livestreamInfo !== null) {
        $category = $livestreamInfo['game_name'];
        $title = $livestreamInfo['title'];
        $lang = $livestreamInfo['language'];
        if(isset($livestreamInfo['viewer_count'])){
            $viewers = $livestreamInfo['viewer_count'];
        } elseif (isset($channel['viewers'])) {
            $viewers = $channel['viewers'];
        }
    }

    if($event['category_name'] !== null) {
        $category = $event['category_name'];
    } else {
        $category = $channel['category'];
    }
    if($event['title'] !== null) {
        $title = $event['title'];
    } else {
        $title = $channel['title'];
    }
    if($event['language'] !== null) {
        $lang = $event['language'];
    } else {
        $lang = $channel['language'];
    }
    $viewers = $channel['viewers'];

    $strings = isset($locales[$lang]) ? $locales[$lang] : $locales['en']; // Выбираем язык, если нет языка — используем английский
    
    // Если у стримера в json есть кастомные строки - используем их
    if (isset($channel['strings'])) {
        $strings = array_merge($strings, $channel['strings']);
    }

    $broadcastPlatform = "";
    switch($channel['platform']){
        case 'twitch': $broadcastPlatform = "https://twitch.tv/";
        break;
    }
    
    $previewUrl = replaceMultipleStrings($livestreamInfo['thumbnail_url'], [
        '{width}' => '1920',
        '{height}' => '1080'
    ]);

    // Подготовка переменных для замены

    $replaceArray = [
        '{Name}' => '<a href="' . $broadcastPlatform . $channel['nickname'] . '"><b><u>' . $event['broadcaster_user_name'] . '</u></b></a>',
        '{Category}' => "<b>".$category."</b>",
        '{Title}' => "<i>".$title."</i>",
        '{Viewers}' => "<u>".$viewers."</u>",
        '{Time}' => "<b>".$StreamTime."</b>",
        '{NewLine}' => PHP_EOL
    ];

    $message = '';

    // Обработка различных статусов
    switch ($status) {
        case 'online':
            $message = replaceMultipleStrings($strings['livestream_start'], $replaceArray);
            break;

        case 'offline':
            $message = replaceMultipleStrings($strings['livestream_end'], $replaceArray);
            if ($viewers != "-1") {
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_viewers'], $replaceArray);
            }
            break;

        case 'update':
            $updated = false;

            $message = '<a href="' . $previewUrl . '?v=' . time().'">&#8205;</a>';
            $message .= replaceMultipleStrings("{Name} ", $replaceArray);

            if (!isset($channel['viewers'])){
                $message .= " " . replaceMultipleStrings($strings['livestream_offline'], $replaceArray);
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_update_category'], $replaceArray);
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_update_title'], $replaceArray);
            } else {
                $message .= " " . replaceMultipleStrings($strings['livestream_update_category'], $replaceArray);
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_update_title'], $replaceArray);
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_viewers'], $replaceArray);
                if ($StreamTime !== "-1") {
                    $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_duration'], $replaceArray);
                }
            }
            
/*

            // Проверяем изменение категории
            if ($channel['category'] != $category) {
                $message .= replaceMultipleStrings($strings['livestream_update_category'], $replaceArray);
                $updated = true;
            }else {
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_category'], $replaceArray);
            }

            // Проверяем изменение названия стрима
            if ($channel['title'] != $title) {
                if ($updated) $message .= PHP_EOL;
                $message .= replaceMultipleStrings($strings['livestream_update_title'], $replaceArray);
                $updated = true;
            }

            if ($updated && $channel['category'] == $category) {
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_category'], $replaceArray);
            }

            if ($updated && $channel['title'] == $title) {
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_title'], $replaceArray);
            }

            // Если были обновления, добавляем информацию о длительности и зрителях
            if ($updated) {
                if ($StreamTime !== "-1") {
                    $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_duration'], $replaceArray);
                }
                if (isset($channel['viewers'])) {
                    $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_viewers'], $replaceArray);
                }
            } elseif (!isset($channel['viewers'])){
                $message .= " " . replaceMultipleStrings($strings['livestream_offline'], $replaceArray);
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_update_title'], $replaceArray);
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_update_category'], $replaceArray);
            }

*/

            break;
    }

    return $message;
}

?>