<?php
require_once 'config.php';
require_once 'functions.php';

// Проверка, является ли это запросом на подтверждение подписки (challenge verification)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    // Получаем hub.challenge
    $challenge = $_GET['hub_challenge'];

    // Логируем подтверждение подписки
    log_message('Subscription verification request received: ' . json_encode($_GET));

    // Возвращаем hub.challenge в ответ
    echo $challenge;
    http_response_code(200); // OK
    exit;
}

// Загружаем тело запроса (уведомление)
$rawPostData = file_get_contents('php://input');
$xml = simplexml_load_string($rawPostData);

// Проверка на наличие данных
if ($xml === false) {
    log_message('Invalid XML received.');
    http_response_code(400);
    exit;
}

// Логируем полученное уведомление
log_message('Push notification received: ' . $rawPostData);

// Получаем данные из XML
$entry = $xml->entry ?? null;
$del_entry = $xml->{'at:deleted-entry'} ?? null;
if ($entry == null && $del_entry == null) {
    log_message('No entry found in push notification.');
    http_response_code(204); // Нет контента
    exit;
}

// Проверяем тип события (публикация нового видео)
$videoId = str_replace("yt:video:", "", (string)$entry->id) ?? null;
$channelId = (string)$entry->author->uri ?? null;
$publishedDate = (string)$entry->published ?? null;

// Опционально проверяем заголовок или ссылку на видео
$videoTitle = (string)$entry->title ?? null;
$videoLink = (string)$entry->link->attributes()->href ?? null;

if($entry == null && $del_entry != null){
    $videoId = str_replace("yt:video:", "", (string)$del_entry->attributes()->ref) ?? null;
}

// Если это не трансляция, можно игнорировать
/*if (strpos($videoTitle, 'LIVE') === false) {
    log_message('This is not a live stream notification, ignoring.');
    http_response_code(204); // Нет контента
    exit;
}*/

// Получаем информацию о стриме через API
//$apiKey = YOUTUBE_API_KEY;
//$videoInfoUrl = "https://www.googleapis.com/youtube/v3/videos?part=snippet,liveStreamingDetails&id={$videoId}&key={$apiKey}";

//$response = make_get_request($videoInfoUrl);
//$videoInfo = json_decode($response, true);
$videoInfo = get_stream_details($videoId);
//log_message("Get user livestream info after callback: " . json_encode($videoInfo['items'][0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Проверяем, что данные о стриме получены корректно
if (isset($videoInfo)) {
    $liveStreamInfo = $videoDetails = $videoInfo;

    // Load channels list from JSON file
    $channels = load_json('channels.json');
    $channelsHash = hash('sha256', serialize($channels));

    if ($channels === null) {
        log_message("Error: Failed to load channels.json or the file is empty.");
        http_response_code(500);
        exit;
    }

    $id = null;

    $broadcaster_id = $videoDetails['snippet']['channelId'];

    foreach($channels as $key => $channel){
        if($channel['broadcaster_id'] == $broadcaster_id) $id = $key; break;
    }

    if($liveStreamInfo == null || $del_entry != null || $liveStreamInfo['viewers'] == "-1" || !isset($liveStreamInfo['viewers']) || $videoDetails['snippet']['liveBroadcastContent'] == "upcoming") {
        log_message("Offline or can't get info. Skip ".$broadcaster_nickname.' ('.$broadcaster_id.')');
        if(isset($channels[$id]['viewers'])){
            // Load locale strings list from JSON file
            $locales = load_json('strings.json');
            $broadcastingTime = broadcastCalculatedTime($channels[$id]['startedAt'], date('c'));
            $message = generateStreamMessage($channel, $locales, $liveStreamInfo, $broadcastingTime, "offline");
            unset($channels[$id]['startedAt']);
            unset($channels[$id]['url']);
            unset($channels[$id]['viewers']);

            // Save updated JSON with broadcaster_id and webhook expiration time
            $channelsNewHash = hash('sha256', serialize($channels));
            if ($channelsNewHash != $channelsHash) save_json('channels.json', $channels);

            notify_channels($channels[$id], $message, 'offline', false);
            return;
        }
            return;
    }
    
    $notifyAboutOnline = false;
    $locales = null;

    if(!isset($channels[$id]['viewers']) && $liveStreamInfo['viewers']){
        $notifyAboutOnline = true;
        // Load locale strings list from JSON file
        $locales = load_json('strings.json');
    }

    $channels[$id]['name'] = $liveStreamInfo['username'];
    $channels[$id]['broadcaster_id'] = $broadcaster_id;
    $channels[$id]['title'] = $liveStreamInfo['title'];
    $channels[$id]['category'] = $liveStreamInfo['category'];
    $channels[$id]['viewers'] = $liveStreamInfo['viewers'];
    $channels[$id]['startedAt'] = $liveStreamInfo['start_time'];
    $channels[$id]['url'] = $liveStreamInfo['url'];
    $channels[$id]['language'] = strtolower($liveStreamInfo['country']);

    // Save updated JSON with broadcaster_id and webhook expiration time
    $channelsNewHash = hash('sha256', serialize($channels));
    if ($channelsNewHash != $channelsHash) save_json('channels.json', $channels);

    // Извлекаем нужные поля
    $channelTitle = $videoDetails['snippet']['channelTitle'];
    $streamTitle = $videoDetails['snippet']['title'];
    $liveViewers = $videoDetails['liveStreamingDetails']['concurrentViewers'] ?? 0;
    $startTime = $videoDetails['liveStreamingDetails']['actualStartTime'] ?? null;
    $category = $videoDetails['snippet']['categoryId']; // Категория видео

    $broadcastingTime = broadcastCalculatedTime($liveStreamInfo['start_time'], date('c'));

    if($notifyAboutOnline){

        $message = generateStreamMessage($channel, $locales, $liveStreamInfo, $broadcastingTime, "online");

        notify_channels($channels[$id], $message, 'online', true);

    }

    // Логируем информацию о стриме
    log_message("Live stream detected: Channel - {$channelTitle}, Title - {$streamTitle}, Viewers - {$liveViewers}");

    // Выполняем действия: отправляем уведомления, обновляем базу данных, и т.д.
    // Пример: отправка уведомления в Telegram
    //$message = "🔴 <b>{$channelTitle}</b> is now live!\n" .
               "<b>Title:</b> {$streamTitle}\n" .
               "<b>Viewers:</b> {$liveViewers}\n" .
               "<b>Watch here:</b> <a href='{$videoLink}'>Click here</a>";

    //send_telegram_message(YOUR_CHAT_ID, $message);

    http_response_code(200); // Успешная обработка
    exit;
} else {
    log_message("Failed to retrieve live stream details for videoId: {$videoId}");
    http_response_code(404); // Не найдено
    exit;
}
