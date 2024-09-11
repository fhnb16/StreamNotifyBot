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


// Send Telegram message with optional inline buttons
function send_telegram_message($chat_id, $message, $buttons = null) {
    $url = TELEGRAM_API_URL . '/sendMessage';

    $getData = [
        'chat_id' => $chat_id,
        'parse_mode' => "HTML",
        'text' => $message
    ];

    // Если передан параметр отключения превью для ссылок
    if ($buttons !== null && is_bool($buttons)) {
        $getData += ['disable_web_page_preview' => !$buttons];
    }

    // Если кнопки переданы, добавляем их в параметры запроса
    if ($buttons !== null && is_array($buttons)) {
        $inlineKeyboard = [
            'inline_keyboard' => $buttons
        ];
        $getData['reply_markup'] = json_encode($inlineKeyboard);
    }

    // Создаем строку запроса
    $queryString = http_build_query($getData);

    // Объединяем базовый URL и строку запроса
    $finalUrl = $url . "?" . $queryString;

    log_message("Telegram message sent to chat {$chat_id} with url `" . $finalUrl . "`");
    $response = make_get_request($finalUrl);

    return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function send_telegram_callback_alert($callback_query_id, $text) {
    $url = TELEGRAM_API_URL . '/answerCallbackQuery';

    $data = [
        'callback_query_id' => $callback_query_id,
        'text' => $text,
        'show_alert' => false  // true, если нужно показать всплывающее уведомление
    ];

    $queryString = http_build_query($data);
    $finalUrl = $url . '?' . $queryString;

    $response = make_get_request($finalUrl);
    return json_decode($response, true);
}

function get_pre_entity_content($callback) {
    // Проверяем, есть ли entities в сообщении
    if (isset($callback['message']['entities'])) {
        $entities = $callback['message']['entities'];
        $text = $callback['message']['text'];

        // Проходим по всем entities
        foreach ($entities as $entity) {
            // Ищем entity с типом pre
            if ($entity['type'] === 'pre') {
                // Извлекаем offset и length для получения нужного текста
                $offset = $entity['offset'];
                $length = $entity['length'];

                // Используем mb_substr для корректной обработки текста в кодировке UTF-8
                return json_decode(mb_substr($text, $offset, $length, 'UTF-8'), true);
            }
        }
    }

    // Если ничего не найдено
    return null;
}

function add_streamer_to_waitlist($newStreamer){
    
    $tempChannels = load_json('waitlist.json');
    $tempChannels[] = $newStreamer;
    save_json('waitlist.json', $tempChannels);
    log_message("Added to waitlist.");

}

// Функция для объединения массивов, исключая дубли на основе nickname и platform
function merge_streamers_with_waitlist($streamers, $waitlist) {
    // Преобразуем $streamers в ассоциативный массив для быстрого поиска
    $existingStreamers = [];

    // Заполняем ассоциативный массив, где ключ — это комбинация nickname и platform
    foreach ($streamers as $streamer) {
        $key = $streamer['nickname'] . '_' . $streamer['platform'];
        $existingStreamers[$key] = $streamer;
    }

    // Обрабатываем $waitlist и добавляем новые стримеры, если они не существуют в $streamers
    foreach ($waitlist as $streamer) {
        $key = $streamer['nickname'] . '_' . $streamer['platform'];

        // Если ключ не найден в существующих стримерах, добавляем нового стримера
        if (!isset($existingStreamers[$key])) {
            switch($streamer['platform']){
                case 'twitch':
                    if(get_broadcaster_id($streamer['nickname']) !== null){
                        log_message("Waitlist item [youtube]" . $streamer['nickname'] . " added to channels list.");
                        $streamers[] = $streamer;
                    }
                break;
                case 'youtube':
                    if(get_channel_id_by_username($streamer['nickname']) !== null){
                        log_message("Waitlist item [youtube]" . $streamer['nickname'] . " added to channels list.");
                        $streamers[] = $streamer;
                    }
                break;
            }
        }
    }

    return $streamers;
}

/**
 * Объединение двух массивов по ключу `broadcaster_id`.
 *
 * @param array $array1 Первый массив с данными стримеров.
 * @param array $array2 Второй массив с данными для объединения (например, `webhook_expire_at`).
 * @return array Массив с добавленными данными.
 */
function merge_webhook_with_channels($array1, $array2) {
    // Перебираем первый массив
    foreach ($array1 as &$item1) {
        // Ищем соответствующий элемент в массиве $array2
        foreach ($array2 as $item2) {
            // Если broadcaster_id совпадает, добавляем данные
            if ($item1['broadcaster_id'] === $item2['broadcaster_id']) {
                // Добавляем или обновляем нужное поле (например, webhook_expires_at)
                $item1['webhook_expires_at'] = $item2['webhook_expires_at'];
                // Если нужно добавить больше полей, можно делать это здесь
                // $item1['some_other_field'] = $item2['some_other_field'];
                break; // Если нашли совпадение, можно выйти из цикла
            }
        }
    }

    return $array1;
}

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

// Получение информации о стримах для нескольких user_id с учётом пагинации
function get_stream_info_by_user_ids(array $user_ids) {
    $url = "https://api.twitch.tv/helix/streams";
    $streams = [];
    $pagination = null;
    
    // Разбиваем массив на куски по 100 user_id (максимум на один запрос)
    $user_chunks = array_chunk($user_ids, 100);

    foreach ($user_chunks as $chunk) {
        do {
            // Формируем URL с параметрами user_id
            $query_params = http_build_query([
                'user_id' => $chunk,
                'after' => $pagination, // Добавляем курсор для пагинации
            ]);
            $fullUrl = $url . '?' . $query_params;

            $headers = [
                'Client-ID: ' . TWITCH_CLIENT_ID,
                'Authorization: Bearer ' . get_twitch_access_token(),
            ];

            // Выполняем запрос к API
            $response = make_get_request($fullUrl, $headers);
            $data = json_decode($response, true);

            // Добавляем полученные данные о стримах в общий массив
            if (isset($data['data'])) {
                $streams = array_merge($streams, $data['data']);
            }

            // Обрабатываем пагинацию
            if (isset($data['pagination']['cursor'])) {
                $pagination = $data['pagination']['cursor'];
            } else {
                $pagination = null;
            }
        } while ($pagination);
    }

    log_message("Stream information for user_ids: " . json_encode($streams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $streams; // Возвращаем массив данных о стримах
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
function generateStreamMessage($channel, $locales, $livestreamInfo, $StreamTime, $status, $event = null) {

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
    
    $previewUrlYoutube = replaceMultipleStrings('https://i.ytimg.com/vi/{videoId}/maxresdefault_live.jpg?v={timestamp}', [
        '{videoId}' => $channel['url'],
        '{timestamp}' => time()
    ]);
    
    $previewUrlTwitch = replaceMultipleStrings('https://static-cdn.jtvnw.net/previews-ttv/live_user_{username}-{width}x{height}.jpg?v={timestamp}', [
        '{width}' => '1920',
        '{height}' => '1080',
        '{username}' => $channel['nickname'],
        '{timestamp}' => time()
    ]);

    $broadcastPlatform = $previewUrl = $streamerName = "";
    switch($channel['platform']){
        case 'twitch': $broadcastPlatform = "https://twitch.tv/";
            $previewUrl = $previewUrlTwitch;
            $streamerName = $event['broadcaster_user_name'];
            break;
        case 'youtube': $broadcastPlatform = "https://youtube.com/";
            $previewUrl = $previewUrlYoutube;
            $streamerName = str_replace('@', '', $livestreamInfo['name']);
            break;
    }

    // Подготовка переменных для замены

    $replaceArray = [
        '{Name}' => '<a href="' . $broadcastPlatform . $channel['nickname'] . '"><b><u>' . $streamerName . '</u></b></a>',
        '{Category}' => "<b>".$category."</b>",
        '{Title}' => "<i>".$title."</i>",
        '{Viewers}' => "~ <u>".$viewers."</u>",
        '{Time}' => "<b>".$StreamTime."</b>",
        '{NewLine}' => PHP_EOL
    ];

    $liveStreamUrlYoutube = "https://www.youtube.com/watch?v=";

    $message = '';

    // Обработка различных статусов
    switch ($status) {
        case 'online':
            $message = replaceMultipleStrings($strings['livestream_start'], $replaceArray);
            if(isset($channel['url'])){
                $message .= PHP_EOL . "<a href='" . $liveStreamUrlYoutube . $channel['url'] . "'><u>Ссылка на стрим</u></a>";
            }
            $message .= PHP_EOL . PHP_EOL . "#" . str_replace('@', '', $channel['nickname']) . " #".$channel['platform'] . ' #'.$status;
            break;

        case 'offline':
            $message = '<a href="' . $previewUrl . '">&#8205;</a>';
            $message .= replaceMultipleStrings($strings['livestream_end'], $replaceArray);
            if ($viewers != "-1") {
                $message .= PHP_EOL . replaceMultipleStrings($strings['livestream_viewers'], $replaceArray);
            }
            $message .= PHP_EOL . PHP_EOL . "#" . str_replace('@', '', $channel['nickname']) . " #".$channel['platform'] . ' #'.$status;
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
                if(isset($channel['url'])){
                    $message .= PHP_EOL . "<a href='" . $liveStreamUrlYoutube . $channel['url'] . "'><u>Ссылка на стрим</u></a>";
                }
            }
            $message .= PHP_EOL . PHP_EOL . "#" . str_replace('@', '', $channel['nickname']) . " #".$channel['platform'] . ' #'.$status;

            break;
    }

    return $message;
}

function notify_channels($channel, $message, $type, $previewEnabled) {
    log_message("Sending notification to channels for {$channel['nickname']}: {$message}");
    
    if (defined('MAIN_CHAT_ID') && null !== MAIN_CHAT_ID && !empty(MAIN_CHAT_ID)) {
        log_message("Send message to main hub. Telegram Request Result: " . send_telegram_message(MAIN_CHAT_ID, $message, $previewEnabled)); // Notify main chat
    }

    foreach ($channel['notify'] as $chatid => $notify_type) {
        $chat_id = explode(':', $chatid)[0];
        $chat_name = explode(':', $chatid)[1];
        if ($notify_type === 'all') {
            log_message("Send message to '" . $chat_name . "'. Telegram Request Result: " . send_telegram_message($chat_id, $message, $previewEnabled));
        }
        if ($notify_type === 'updates' && ($type == 'update' || $type == 'offline' )) {
            log_message("Send message to '" . $chat_name . "'. Telegram Request Result: " . send_telegram_message($chat_id, $message, $previewEnabled));
        }
        if ($notify_type === 'live' && ($type == 'online' || $type == 'offline')) {
            log_message("Send message to '" . $chat_name . "'. Telegram Request Result: " . send_telegram_message($chat_id, $message, $previewEnabled));
        }
        if ($notify_type === 'online' && $type == 'online') {
            log_message("Send message to '" . $chat_name . "'. Telegram Request Result: " . send_telegram_message($chat_id, $message, $previewEnabled));
        }
    }
}

/**
 * Отписка от всех push уведомлений о YouTube
 * 
 * @param array $channels Массив с информацией о каналах (темах), от которых нужно отписаться
 */
function unsubscribe_from_all_youtube_push_notifications($channels) {
    $hubUrl = 'https://pubsubhubbub.appspot.com/subscribe'; // URL PubSubHubBub для управления подписками

    foreach ($channels as $channel) {
        // Формируем параметры для отписки
        $postData = [
            'hub.mode' => 'unsubscribe',
            'hub.topic' => 'https://www.youtube.com/xml/feeds/videos.xml?channel_id=' . $channel['channel_id'], // Тема (канал YouTube)
            'hub.callback' => YOUTUBE_CALLBACK_URL, // URL вашего callback обработчика
        ];

        // Отправляем запрос на отписку
        $response = make_post_request($hubUrl, $postData);

        // Логируем результат
        if ($response) {
            log_message("Successfully unsubscribed from channel ID: " . $channel['channel_id']);
        } else {
            log_message("Failed to unsubscribe from channel ID: " . $channel['channel_id']);
        }
    }
}

// youtube livestream info
function get_youtube_stream_info($username, &$broadcaster_id = null) {

    $channelId = null;

    if($broadcaster_id == null) {
        // 1. Получаем идентификатор канала по имени пользователя
        $channelId = get_channel_id_by_username($username);
    } else {
        $channelId = $broadcaster_id;
    }
    
    
    if (!$channelId) {
        log_message("Channel not found.");
        return null;
    }

    // 2. Получаем информацию о текущей трансляции
    $liveStreamInfo = get_live_stream_info_by_channel_id($channelId);
    
    if (!$liveStreamInfo) {
        log_message("No active live stream found.");
        return null;
    }
    
    return $liveStreamInfo;  // Возвращаем информацию о трансляции
}

// Вспомогательная функция для получения channelId по username
function get_channel_id_by_username($username) {
    $url = "https://www.googleapis.com/youtube/v3/channels?part=id,snippet&forHandle=" . urlencode($username) . "&key=" . YOUTUBE_API_KEY;
    
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    log_message("Get id by username respone: " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if (isset($data['items'][0])) {
        return $data['items'][0]['id'];  // Возвращаем идентификатор канала
    }

    log_message("Can't get id by nickname.");
    
    return null;
}

// Вспомогательная функция для получения информации о текущей трансляции по channelId
function get_live_stream_info_by_channel_id($channelId) {
    $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&channelId=" . urlencode($channelId) . "&type=video&eventType=live&key=" . YOUTUBE_API_KEY;
    
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    log_message("Get stream info by id respone: " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if (isset($data['items'][0])) {
        $videoId = $data['items'][0]['id']['videoId'];
        $streamDetails = get_stream_details($videoId);
        return $streamDetails;  // Возвращаем информацию о трансляции
    }
    
    log_message("Can't stream info.");
    return null;
}

// Вспомогательная функция для получения деталей трансляции по videoId
function get_stream_details($videoId) {
    $url = "https://www.googleapis.com/youtube/v3/videos?part=snippet,liveStreamingDetails&id=" . urlencode($videoId) . "&key=" . YOUTUBE_API_KEY;
    
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    log_message("Get livestream details response: ". json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if (isset($data['items'][0])) {
        $snippet = $data['items'][0]['snippet'];
        $liveDetails = $data['items'][0]['liveStreamingDetails'];

        // Получаем нужную информацию
        $streamInfo = [
            'username' => $snippet['channelTitle'],  // Имя пользователя (канала)
            'title' => $snippet['title'],  // Название трансляции
            'category' => $snippet['categoryId'],  // Категория (ID категории, для отображения нужно сопоставление с именами категорий)
            'viewers' => isset($liveDetails['concurrentViewers']) ? $liveDetails['concurrentViewers'] : '-1',  // Количество зрителей
            'start_time' => $liveDetails['actualStartTime'],  // Время начала трансляции
            'url' => $videoId,  // Ссылка на трансляцию
            'country' => isset($liveDetails['country']) ? $liveDetails['concurrentViewers'] : null
        ];
        
        return $streamInfo;
    }
    
    log_message("Can't stream details.");
    return null;
}

function subscribe_to_youtube_push_notifications($user_ids) {
    $callback_url = YOUTUBE_CALLBACK_URL;
    $hub_url = 'https://pubsubhubbub.appspot.com/subscribe';
    $tempArray = [];
    $lease_seconds = 432000; // 5 дней = 432000 секунд
    $current_time = time();
    $expiration_date = date('c', $current_time + $lease_seconds); // Дата истечения подписки (текущая дата + 5 дней)

    foreach ($user_ids as $user_id) {
        $topic_url = "https://www.youtube.com/xml/feeds/videos.xml?channel_id={$user_id}";

        // Параметры для подписки
        $postData = [
            'hub.callback' => $callback_url,
            'hub.mode' => 'subscribe',
            'hub.topic' => $topic_url,
            'hub.lease_seconds' => $lease_seconds
        ];

        // Выполняем запрос на подписку
        $response = make_post_request($hub_url, $postData);
        
        // Логируем успешную подписку
        log_message("Subscribed to YouTube push notifications for user_id: {$user_id}, expires on: {$expiration_date}");
        $tempArray[] = Array("broadcaster_id"=>$user_id, "webhook_expires_at"=>$expiration_date, "platform"=>"youtube");
        /*if ($response) {
            log_message("Subscribed to YouTube push notifications for user_id: {$user_id}, expires on: {$expiration_date}");
            $tempArray[] = Array("broadcaster_id"=>$user_id, "webhook_expires_at"=>$expiration_date);
        } else {
            log_message("Failed to subscribe for user_id: {$user_id}");
        }*/
    }

    if(count($tempArray) == 0) return null;

    // Возвращаем дату истечения подписки для информации
    return $tempArray;
}



?>
