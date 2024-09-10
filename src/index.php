<?php
require_once 'config.php';
require_once 'functions.php';

// Получаем данные из запроса Telegram
$content = file_get_contents('php://input');
$update = json_decode($content, true);

// Если запрос невалиден — выходим
if (!$update) {
    log_message("Error: Invalid request received.");
    exit;
}

// Обрабатываем команды чата и callback
if (isset($update['message'])) {
    handle_message($update['message']);
} elseif (isset($update['callback_query'])) {
    handle_callback($update['callback_query']);
} elseif (isset($update['inline_query'])) {
    handle_inline_query($update['inline_query']);
}

/**
 * Обработка текстовых сообщений и команд
 */
function handle_message($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];

    switch (true) {
        case stristr($text,'/start'):
            send_telegram_message($chatId, "Привет! Я бот для мониторинга стримеров. Используйте /help, чтобы получить список команд.\nОбратная связь: @stickers_feedback_bot");
            break;

        case stristr($text,'/help'):
            send_telegram_message($chatId, "Обратная связь: @stickers_feedback_bot\nДоступные команды:\n/start - Начать\n/help - Помощь\n/list - Список стримеров\n/online - Список стримеров в сети\n/new - добавить стримера\n/edit - редактировать уведомления");
            break;

        case stristr($text,'/online'):
            $streamers = get_streamers_online_list();
            $responseText = "Список стримеров:\n";
            foreach($streamers as $streamer) {
                $responseText .= $streamer['name'];
                $responseText .= ' ['.$streamer['platform'].']' . PHP_EOL;
            }
            send_telegram_message($chatId, $responseText);
            break;

        case stristr($text,'/list'):
            $streamers = load_json('channels.json');
            $responseText = "Список стримеров:\n";
            foreach($streamers as $streamer) {
                $responseText .= $streamer['name'];
                $responseText .= ' ['.$streamer['platform'].']' . PHP_EOL;
            }
            send_telegram_message($chatId, $responseText);
            break;

        case stristr($text,'/edit'):
            if($message['from']['id'] != ADMIN_ID) {send_telegram_message($chatId, "Недостаточно полномочий для выполнения"); exit();}
            break;

        case stristr($text,'/new'):
            if($message['from']['id'] != ADMIN_ID) {send_telegram_message($chatId, "Недостаточно полномочий для выполнения"); exit();}
            $pattern = '/https?:\/\/(www\.)?(?<domain>[^\/]+)\/(?<nickname>[^\/]+)/';
            preg_match($pattern, $message['text'], $matches);
            if($matches){
                $replaceArray = [
                    '.com' => "",
                    '.ru' => "",
                    '.tv' => "",
                    'live.' => ""
                ];
                $platform = replaceMultipleStrings($matches['domain'], $replaceArray);
                $name = $matches['nickname'];
                $nickname = strtolower($matches['nickname']);
                $responseMessage = "Проверьте и подтвердите добавление." . PHP_EOL;
                $responseMessage .= "<pre>".json_encode(array("name"=>$name,"nickname"=>$nickname,"platform"=>$platform), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                $buttons = [
                    [
                        ['text' => 'Добавить в очередь', 'callback_data' => 'add_streamer'],
                        ['text' => 'Отменить', 'callback_data' => 'buttons_remove']
                    ]
                ];
                send_telegram_message($chatId, $responseMessage, $buttons);
            }else {
                send_telegram_message($chatId, "Пример команды:".PHP_EOL.PHP_EOL."<pre>/new https://twitch.tv/HoneyMad</pre>".PHP_EOL.PHP_EOL."Поддерживаются ссылки на Twitch, VkPlayLive и Youtube");
            }
            break;

        default:
            //send_telegram_message($chatId, "Извините, я не понимаю эту команду.");
    }
}

/**
 * Обработка callback_query от нажатия кнопок
 */
function handle_callback($callback) {
    $chatId = $callback['message']['chat']['id'];
    $callbackData = $callback['data'];
    $messageId = $callback['message']['message_id'];
    log_message("Callback recieved: ".json_encode($callback));

    switch ($callbackData) {
        case 'buttons_remove':
            remove_buttons_from_message($chatId, $messageId);
            break;

        case 'add_streamer':
            $extractedJson = get_pre_entity_content($callback);
            //send_telegram_callback_alert($callback['id'], $extractedJson['name']);
            //send_telegram_message($chatId, $callback['message']['text']);
            add_streamer_to_waitlist($extractedJson);
            send_telegram_callback_alert($callback['id'], $extractedJson['name'] . " Добавлен в список ожидания.");
            remove_buttons_from_message($chatId, $messageId);
            break;

        case 'streamer_info':
            send_telegram_message($chatId, "Вы выбрали просмотр информации о стримере.");
            break;

        case 'notify_me':
            send_telegram_message($chatId, "Теперь вы подписаны на уведомления.");
            break;

        default:
            send_telegram_message($chatId, "Неизвестная команда.");
    }

    // Подтверждаем получение callback
    answer_callback_query($callback['id']);
}

// Функция для удаления кнопок из сообщения
function remove_buttons_from_message($chat_id, $message_id) {
    $url = TELEGRAM_API_URL . '/editMessageReplyMarkup';

    $getData = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'reply_markup' => json_encode(['inline_keyboard' => []]) // Пустой массив для удаления кнопок
    ];

    // Создаем строку запроса
    $queryString = http_build_query($getData);

    // Объединяем базовый URL и строку запроса
    $finalUrl = $url . "?" . $queryString;

    log_message("Removing buttons from message {$message_id} in chat {$chat_id}");
    $response = make_get_request($finalUrl);

    return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Обработка inline-запросов
 */
function handle_inline_query($inlineQuery) {
    $queryId = $inlineQuery['id'];
    $queryText = $inlineQuery['query'];

    // Получаем список стримеров (по запросу или все)
    if($queryText == "offline") {
        $streamers = get_streamers_offline_list();
    } else {
        $streamers = get_streamers_online_list($queryText);
    }

    // Формируем результаты для показа в inline режиме
    $results = [];
    foreach ($streamers as $streamer) {

        $broadcastPlatform = "";
        switch($streamer['platform']){
            case 'twitch': $broadcastPlatform = "https://twitch.tv/";
            break;
        }

        $streamTime = broadcastCalculatedTime($streamer['startedAt'], time('c'));

        $message = "";
    
        if($queryText != "offline"){
            $message .= '<a href="https://static-cdn.jtvnw.net/previews-ttv/live_user_' . $streamer['nickname'] . '-1920x1080.jpg?v=' . time().'">&#8205;</a>';
        }
        $message .= $queryText == "offline" ? '{Name} сейчас оффлайн.{NewLine}' : '{Name} сейчас в сети.{NewLine}';
        if($queryText != "offline"){
            $message .= 'Категория: <b>{Category}</b>{NewLine}Название: {Title}{NewLine}'.
            'Стрим идет: {Time}{NewLine}'.
            'Зрителей: {Viewers}';
        }

        $replaceArray = [
            '{Name}' => "<a href='{$broadcastPlatform}{$streamer['nickname']}'><b><u>{$streamer['name']}</u></b></a>",
            '{Category}' => "<b>".$streamer['category']."</b>",
            '{Title}' => "<i>".$streamer['title']."</i>",
            '{Viewers}' => "<u>".$streamer['viewers']."</u>",
            '{Time}' => "<b>".$streamTime."</b>",
            '{NewLine}' => PHP_EOL
        ];
        $responseMessage = replaceMultipleStrings($message, $replaceArray);

        $results[] = [
            'type' => 'article',
            'id' => md5($streamer['name']),
            'title' => $streamer['name'],
            'input_message_content' => [
                'parse_mode' => "HTML",
                'message_text' => $responseMessage,
            ],
            'description' => $queryText == "offline" ? "Offline [{$streamer['platform']}]" : "Online [{$streamer['platform']}], {$streamer['viewers']} viewers, {$streamer['category']}"
        ];
    }

    // Отправляем ответ на inline-запрос
    send_inline_query_results($queryId, $results);
}

/**
 * Ответ на callback_query
 */
function answer_callback_query($callbackId) {
    $url = TELEGRAM_API_URL . "/answerCallbackQuery";
    $data = [
        'callback_query_id' => $callbackId
    ];

    make_post_request($url, $data);
}

/**
 * Отправка inline-результатов
 */
function send_inline_query_results($queryId, $results) {
    $url = TELEGRAM_API_URL . "/answerInlineQuery";
    $data = [
        'inline_query_id' => $queryId,
        'results' => json_encode($results),
        'cache_time' => 0 // Чтобы результаты обновлялись каждый раз
    ];

    make_post_request($url, $data);
}

/**
 * Получение списка стримеров из файла channels.json с фильтрацией и сортировкой по viewers
 */
function get_streamers_online_list($filter = null) {
    // Load channels list from JSON file
    $channels = load_json('channels.json');

    // Проверяем, успешно ли декодированы данные
    if ($channels === null) {
        log_message("Error: Ошибка при чтении JSON данных.");
        return [];
    }

    // Фильтруем стримеров, у которых есть параметр viewers
    $streamers = array_filter($channels, function($channel) {
        return isset($channel['viewers']);
    });

    // Если передан фильтр, фильтруем стримеров по запросу
    if ($filter) {
        $streamers = array_filter($streamers, function($streamer) use ($filter) {
            return stripos($streamer['name'], $filter) !== false; // Фильтр по имени стримера
        });
    }

    // Сортировка по viewers (по убыванию)
    usort($streamers, function($a, $b) {
        return $b['viewers'] <=> $a['viewers'];
    });

    // Возвращаем массив со всеми данными о стримерах
    return $streamers;
}

/**
 * Получение списка стримеров из файла channels.json с фильтрацией и сортировкой по viewers
 */
function get_streamers_offline_list($filter = null) {
    // Load channels list from JSON file
    $channels = load_json('channels.json');

    // Проверяем, успешно ли декодированы данные
    if ($channels === null) {
        log_message("Error: Ошибка при чтении JSON данных.");
        return [];
    }

    // Фильтруем стримеров, у которых есть параметр viewers
    $streamers = array_filter($channels, function($channel) {
        return !isset($channel['viewers']);
    });

    // Если передан фильтр, фильтруем стримеров по запросу
    if ($filter) {
        $streamers = array_filter($streamers, function($streamer) use ($filter) {
            return stripos($streamer['name'], $filter) !== false; // Фильтр по имени стримера
        });
    }

    // Возвращаем массив со всеми данными о стримерах
    return $streamers;
}
