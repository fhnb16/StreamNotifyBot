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
    handle_message($update['message'], $addStreamerMessage);
} elseif (isset($update['callback_query'])) {
    handle_callback($update['callback_query'], $addStreamerMessage);
} elseif (isset($update['inline_query'])) {
    handle_inline_query($update['inline_query']);
}

function addStreamerMessage($chatId){
    $msg = "Пример команды:".PHP_EOL.PHP_EOL."<pre>/new https://twitch.tv/HoneyMad</pre>".PHP_EOL."или".PHP_EOL."<pre>/new https://www.youtube.com/@DeadP47</pre>".PHP_EOL.PHP_EOL."Поддержка Kick и Vk Play возможно появится позднее.";
    send_telegram_message($chatId, $msg);
}

/**
 * Обработка текстовых сообщений и команд
 */
function handle_message($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    $buttons = [
        [
            ['text' => 'Подписаться на уведомления', 'web_app'=> ['url' => 'https://bot.fhnb.ru/StreamNotifyBot/editNotify.php']]
        ],
        [
            ['text' => 'Узнать статус стримера', 'switch_inline_query' => '']
        ],
        [
            ['text' => 'Добавить стримера', 'callback_data' => 'addStreamerCallback'],
            ['text' => 'Поддержка', 'url' => 'https://t.me/stickers_feedback_bot']
        ]
    ];

    switch (true) {
        case stristr($text,'/start'):
            send_telegram_message($chatId, "Привет! Я бот для мониторинга стримеров.\nНажмите на <b>Subscribe</b> чтобы подписаться на уведомления о стримерах.\nИспользуйте /help, чтобы получить список команд.\nОбратная связь: @stickers_feedback_bot", $buttons);
            break;

        case stristr($text,'/help'):
            send_telegram_message($chatId, "Нажмите на <b>Subscribe</b> чтобы подписаться на уведомления о стримерах или отписаться от них.\n\nДоступные команды:\n/start - Начать\n/support - Поддержать\n/help - Помощь\n/new - добавить стримера\n\nДля предложения функций и сотрудничества пишите @stickers_feedback_bot\n\nДоступен inline-режим, напишите <code>@currentlyLive_bot </code> в любом чате и выберите стримера.", $buttons);
            break;

        case stristr($text,'/support'):
            $tempEditedMessage = "Вы можете поддержать меня по данным реквизитам:" . PHP_EOL;
            foreach(CFG_MONEY as $method=>$code) {
                $tempMethod = "";
                switch($method){
                    case "donationalerts": $tempMethod = "DonationAlerts";
                    break;
                    case "btc": $tempMethod = "Bitcoin";
                    break;
                    case "ton": $tempMethod = "Ton";
                    break;
                    case "usdt": $tempMethod = "USDT";
                    break;
                    case "visa": $tempMethod = "Visa";
                    break;
                    case "mastercard": $tempMethod = "MasterCard";
                    break;
                    case "mir": $tempMethod = "Мир";
                    break;
                }
                //$tempEditedMessage .= "**" . $tempMethod . "**:\n" . "`" . $code . "`\n\n";
                $tempEditedMessage .= "<b>" . $tempMethod . "</b>" . PHP_EOL . '<code>'.$code.'</code>' . PHP_EOL;
            }
            send_telegram_message($chatId, $tempEditedMessage);
            break;


        case stristr($text,'/online'):
            $streamers = get_streamers_online_list();
            $responseText = "Список стримеров в сети:\n";
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

        case stristr($text,'/new'):
            //if($message['from']['id'] != ADMIN_ID) {send_telegram_message($chatId, "Недостаточно полномочий для выполнения"); exit();}
            $pattern = '/https?:\/\/(www\.)?(?<domain>[^\/]+)\/(?<nickname>[^\/]+)/';
            preg_match($pattern, htmlspecialchars($message['text']), $matches);
            if($matches){
                $replaceArray = [
                    '.com' => "",
                    '.ru' => "",
                    '.tv' => "",
                    'live.' => ""
                ];
                $result = false;
                $broadcaster_id = "";
                $platform = replaceMultipleStrings($matches['domain'], $replaceArray);
                $nickname = strtolower($matches['nickname']);
                $name = $matches['nickname'];
                switch($platform){
                    case 'youtube': 
                        $fetchStreamerData = get_channel_id_by_username($nickname, true);
                        $name = $fetchStreamerData['name'];
                        $broadcaster_id = $fetchStreamerData['broadcaster_id'];
                        break;
                    case 'twitch': 
                        $fetchStreamerData = get_broadcaster_id($nickname, true);
                        $name = $fetchStreamerData['name'];
                        $broadcaster_id = $fetchStreamerData['broadcaster_id'];
                        break;
                }
                if(!empty($broadcaster_id)){
                    $result = true;
                }
                if($result){
                    $responseMessage = "Проверьте и подтвердите добавление." . PHP_EOL . PHP_EOL;
                    $responseMessage .= "<pre>".json_encode(array("name"=>$name,"nickname"=>$nickname,"platform"=>$platform,"broadcaster_id"=>$broadcaster_id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                    $buttons = [
                        [
                            ['text' => 'Добавить в очередь', 'callback_data' => 'add_streamer'],
                            ['text' => 'Отменить', 'callback_data' => 'buttons_remove']
                        ]
                    ];
                    send_telegram_message($chatId, $responseMessage, $buttons);
                }else{
                    send_telegram_message($chatId, "Не удалось найти пользователя Youtube или Twitch, проверьте корректность введенного адреса.");
                }
            }else {
                addStreamerMessage($chatId);
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
        case 'addStreamerCallback':
            addStreamerMessage($chatId);
            break;
        case 'buttons_remove':
            remove_buttons_from_message($chatId, $messageId);
            break;
        case 'editNotifications':
            $userId = $callback['from']['id'];
            $webAppUrl = TELEGRAM_NOTIFY_EDITOR_URL . "?user_id=" . $userId;
            
            $response = [
                'method' => 'answerCallbackQuery',
                'callback_query_id' => $callback['id'],
                'url' => $webAppUrl
            ];
        
            // Отправляем ссылку на WebApp
            send_telegram_message($chatId, "Click the link to edit notifications: " . $webAppUrl);
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
        //$streamers += get_streamers_offline_list($queryText);
    }

    // Формируем результаты для показа в inline режиме
    $results = [];
    foreach ($streamers as $streamer) {

        $youtubeThumb = 'https://i.ytimg.com/vi/{videoId}/{imgSize}_live.jpg?v={timestamp}';

        $previewUrlYoutube = replaceMultipleStrings($youtubeThumb, [
            '{videoId}' => $streamer['url'],
            '{timestamp}' => time(),
            '{imgSize}' => 'maxresdefault'
        ]);

        $previewUrlYoutubeSmol = replaceMultipleStrings($youtubeThumb, [
            '{videoId}' => $streamer['url'],
            '{timestamp}' => time(),
            '{imgSize}' => 'default'
        ]);

        $twitchThumb = 'https://static-cdn.jtvnw.net/previews-ttv/live_user_{username}-{width}x{height}.jpg?v={timestamp}';
        
        $previewUrlTwitch = replaceMultipleStrings($twitchThumb, [
            '{width}' => '1920',
            '{height}' => '1080',
            '{username}' => $streamer['nickname'],
            '{timestamp}' => time()
        ]);
        
        $previewUrlTwitchSmol = replaceMultipleStrings($twitchThumb, [
            '{width}' => '120',
            '{height}' => '90',
            '{username}' => $streamer['nickname'],
            '{timestamp}' => time()
        ]);

        $broadcastPlatform = $previewUrl = $previewUrlSmol = "";
        switch($streamer['platform']){
            case 'twitch': $broadcastPlatform = "https://twitch.tv/"; $previewUrl = $previewUrlTwitch; $previewUrlSmol = $previewUrlTwitchSmol;
                break;
            case 'youtube': $broadcastPlatform = "https://youtube.com/"; $previewUrl = $previewUrlYoutube; $previewUrlSmol = $previewUrlYoutubeSmol;
                break;
        }

        $categoryText = $streamer['category'];

        if($streamer['platform'] == "youtube"){
            $categoryText = getCategoryFromID($streamer['category']);
        }

        $streamTime = broadcastCalculatedTime($streamer['startedAt'], time('c'));

        $message = "";
    
        if(isset($streamer['viewers'])){
            $message .= '<a href="'.$previewUrl.'">&#8205;</a>';
        }
        $message .= !isset($streamer['viewers']) ? '🔴 {Name} сейчас оффлайн.' : '🟢 {Name} сейчас в сети.';
        if(isset($streamer['viewers'])){
            $message .= PHP_EOL;
            $message .= 'Категория: <b>{Category}</b>{NewLine}Статус: {Title}{NewLine}'.
            'Стрим идет: {Time}{NewLine}'.
            'Зрителей: {Viewers}';
        }

        $liveStreamUrlYoutube = "https://www.youtube.com/watch?v=";
        if(isset($streamer['url'])){
            $message .= PHP_EOL . "<a href='" . $liveStreamUrlYoutube . $streamer['url'] . "'><u>Ссылка на стрим</u></a>";
        }
        
        $message .= PHP_EOL . PHP_EOL . "#" . str_replace('@', '', str_replace('-', '_', $streamer['nickname'])) . " #{platform} #{status}";

        $replaceArray = [
            '{Name}' => "<a href='{$broadcastPlatform}{$streamer['nickname']}'><b><u>{$streamer['name']}</u></b></a>",
            '{Category}' => "<b>".$categoryText."</b>",
            '{Title}' => "<i>".$streamer['title']."</i>",
            '{Viewers}' => "~ <u>".$streamer['viewers']."</u>",
            '{Time}' => "<b>".$streamTime."</b>",
            '{status}' => !isset($streamer['viewers']) ? 'offline' : 'online',
            '{platform}' => $streamer['platform'],
            '{NewLine}' => PHP_EOL
        ];

        $responseMessage = replaceMultipleStrings($message, $replaceArray);

        $results[] = [
            'type' => 'article',
            'id' => md5($streamer['name']),
            'title' => $streamer['name'],
            /*'thumb_url' => $previewUrl,
            'thumb_height' => '32',*/
            'input_message_content' => [
                'parse_mode' => "HTML",
                'message_text' => $responseMessage,
            ],
            'description' => !isset($streamer['viewers']) ? "🔴 [{$streamer['platform']}]" : "🟢 [{$streamer['platform']}], ~{$streamer['viewers']} viewers, {$categoryText}"
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

    // Фильтруем стримеров, у которых нет параметр viewers
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