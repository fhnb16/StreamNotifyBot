<?php
require_once 'config.php';
require_once 'functions.php';

// detect cron request
if (isset($_GET['cron'])) {
    log_message("############# Cron task #############");
    if ($_GET['cron']=="youtube") {
        log_message("-------------  YouTube  -------------");
    }
}

// detect cron request
if (isset($_GET['cron']) && $_GET['cron'] == "backup") {
    log_message("~~~ Backup channels.json ~~~");
    $channels = load_json('channels.json');
    save_json('backup' . DIRECTORY_SEPARATOR . 'channels_' . time() . '.json', $channels);
    log_message("~~~ Backup notifications.json ~~~");
    $notifications = load_json('notifications.json');
    save_json('backup' . DIRECTORY_SEPARATOR . 'notifications_' . time() . '.json', $notifications);
    exit;
}

// Check if the "telegram_callback" parameter is set
if (isset($_GET['telegram_callback']) || isset($_GET['setup'])) {
    // Construct the full URL for the callback (assuming bot.php and index.php are in the same folder)
    $callbackUrl = TELEGRAM_CALLBACK_URL;

    // Attempt to set the webhook
    if (set_telegram_webhook($callbackUrl)) {
        echo "Telegram webhook has been successfully set to {$callbackUrl}.";
    } else {
        echo "Failed to set Telegram webhook.";
    }

    if(!isset($_GET['setup'])) exit();
}

// Check if the "telegram_callback_remove" parameter is set
if (isset($_GET['telegram_callback_remove'])) {
    // Construct the full URL for the callback (assuming bot.php and index.php are in the same folder)
    $callbackUrl = TELEGRAM_CALLBACK_URL;

    // Attempt to set the webhook
    if (set_telegram_webhook($callbackUrl)) {
        echo "Telegram webhook has been successfully disabled for {$callbackUrl}.";
    } else {
        echo "Failed to disable Telegram webhook.";
    }

    exit();
}

// If accessed with 'subscriptions' parameter, output all active EventSub subscriptions
if (isset($_GET['subscriptions'])) {
    header('Content-Type: application/json');
    echo get_all_eventsub_subscriptions();
    exit;
}

// Clean up all subscriptions
if (isset($_GET['clean'])) {
    cancel_all_eventsub_subscriptions();
    echo "All subscriptions have been cancelled.";
    exit;
}

// Clean up all subscriptions
if (isset($_GET['disabled'])) {
    cancel_disabled_eventsub_subscriptions();
    echo "Disabled subscriptions have been cancelled.";
    exit;
}

// Clean up all subscriptions
if (isset($_GET['youtube_clean'])) {
    $user_ids = [];
    foreach ($channels as $id => $channel) {
        if($channel['platform'] != "youtube") continue;
        $user_ids[] = ["channel_id" => $channel['broadcaster_id']];
        unset($channels[$id]['webhook_expires_at']);
    }
    unsubscribe_from_all_youtube_push_notifications($user_ids);

    $channelsNewHash = hash('sha256', serialize($channels));
    if ($channelsNewHash != $channelsHash) save_json('channels.json', $channels);

    echo "Disabled subscriptions have been cancelled.";
    exit;
}

// Load channels list from JSON file
$channels = load_json('channels.json');
$channelsHash = hash('sha256', serialize($channels));

$waitlistChannels = load_json('waitlist.json');

if ($waitlistChannels !== null) {
    $tempChannels = merge_streamers_with_waitlist($channels, $waitlistChannels);

    if ($waitlistChannels !== null || $tempChannels !== null) {
        $channels = $tempChannels;
        unlink('waitlist.json');
        log_message("Added " . count($waitlistChannels) . ' items to channels.json');
    } else {
        log_message("Error: Failed to merge waitlist with channels.");
    }
}

if ($channels === null) {
    log_message("Error: Failed to load channels.json or the file is empty.");
    exit;
}

twitchSetup($channels);
youtubeSetup($channels);
    
// Save updated JSON with broadcaster_id and webhook expiration time
$channelsNewHash = hash('sha256', serialize($channels));
if ($channelsNewHash != $channelsHash) save_json('channels.json', $channels);

function youtubeSetup(&$channels){

    $counter = 0;

    $user_ids = [];

    foreach ($channels as $id => $channel) {
        if($channel['platform'] != "youtube") continue;

        $currentTime = time();

        $broadcaster_nickname = $channel['nickname'];

        $broadcaster_name = "";

        $broadcaster_id = "";

        if(!isset($channel['broadcaster_id']) || $broadcaster_name == $broadcaster_nickname){
            $newBidName = get_channel_id_by_username($broadcaster_nickname, true);
            $channels[$id]['broadcaster_id'] = $newBidName['broadcaster_id'];
            $broadcaster_id = $newBidName['broadcaster_id'];
            $channels[$id]['name'] = $newBidName['name'];
            $broadcaster_name = $newBidName['name'];
        } else {
            $broadcaster_name = $channel['name'];
            $broadcaster_id = $channel['broadcaster_id'];
            checkNotifyJson($broadcaster_id, $channel['name'], $channel['nickname']);
        }

        if(isset($_GET['cron']) && $_GET['cron'] == "youtube"){

            if(isset($channels[$id]['startedAt']) || isset($channels[$id]['url']) || isset($channels[$id]['viewers'])){

                $liveStreamInfo = get_stream_details($channels[$id]['url']);

                if($liveStreamInfo != null && isset($liveStreamInfo['viewer_count']) && $liveStreamInfo['viewer_count'] != "-1" && $liveStreamInfo['liveBroadcastContent'] != "none"){

                    $channels[$id]['viewers'] = $liveStreamInfo['viewer_count'];
                    $channels[$id]['title'] = $liveStreamInfo['title'];
                    $channels[$id]['category'] = $liveStreamInfo['category'];
                    $channels[$id]['url'] = $liveStreamInfo['url'];
                    
                    $counter++;
                    continue;

                } else {

                    $locales = load_json('strings.json');
                    $broadcastingTime = broadcastCalculatedTime($channels[$id]['startedAt'], date('c'));
                    $message = generateStreamMessage($channels[$id], $locales, $liveStreamInfo, $broadcastingTime, "offline");
                    notify_channels($channels[$id], $message, 'offline', false);

                    unset($channels[$id]['startedAt']);
                    unset($channels[$id]['url']);
                    unset($channels[$id]['viewers']);
                    

                    $counter++;
                    continue;

                }

            } /*else {

                $liveStreamInfo = get_live_stream_info_by_channel_id($channels[$id]['broadcaster_id']);

                if($liveStreamInfo != null && $liveStreamInfo['viewers']){

                    $channels[$id]['viewers'] = $liveStreamInfo['viewers'];
                    $channels[$id]['title'] = $liveStreamInfo['title'];
                    $channels[$id]['category'] = $liveStreamInfo['category'];
                    $channels[$id]['startedAt'] = $liveStreamInfo['start_time'];
                    $channels[$id]['url'] = $liveStreamInfo['url'];

                }

            }*/

        }

        // Check if webhook is registered and has not expired
        if (isset($channel['webhook_expires_at']) && isset($channel['broadcaster_id'])) {
            // Attempt to parse the timestamp in both ISO 8601 and Y-m-d H:i:s formats
            $webhookExpiresAt = strtotime($channel['webhook_expires_at']);
            if ($webhookExpiresAt === false) {
                $webhookExpiresAt = strtotime(date('Y-m-d H:i:s', strtotime($channel['webhook_expires_at'])));
            }

            if ($currentTime < $webhookExpiresAt) {
                if(!isset($_GET['cron'])) log_message("Webhook still active for {$channel['nickname']}. Skipping.");
                $counter++;
                continue;
            }
        }

        $user_ids[] = $channel['broadcaster_id'];


/*
        $liveStreamInfo = get_youtube_stream_info($broadcaster_nickname, $broadcaster_id);

        if($liveStreamInfo == null) {
            log_message("Offline or can't get info. Skip ".$broadcaster_nickname.' ('.$broadcaster_id.')');
            if(isset($channels[$id]['viewers'])){
                // Load locale strings list from JSON file
                $locales = load_json('strings.json');
                $broadcastingTime = broadcastCalculatedTime($channels[$id]['startedAt'], date('c'));
                $message = generateStreamMessage($channel, $locales, $liveStreamInfo, $broadcastingTime, "offline");
                unset($channels[$id]['startedAt']);
                unset($channels[$id]['url']);
                unset($channels[$id]['viewers']);
                notify_channels($channels[$id], $message, 'offline', true);
            }
            $counter++;
            continue;
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


        $broadcastingTime = broadcastCalculatedTime($liveStreamInfo['start_time'], date('c'));

        if($notifyAboutOnline){

            $message = generateStreamMessage($channel, $locales, $liveStreamInfo, $broadcastingTime, "online");
    
            notify_channels($channels[$id], $message, 'online', true);

        }
*/

        $counter++;
    }

    $youtubePushSubscribe = subscribe_to_youtube_push_notifications($user_ids);
    
    if($youtubePushSubscribe !== null){
        /*foreach($youtubePushSubscribe as $item){
            if($channels[$item])
            $channels[$item["broadcaster_id"]]['webhook_expires_at'] = $item["webhook_expires_at"];
            log_message($channels[$item["broadcaster_id"]]['name'] . " [youtube] subscribed to webhooks.");
        }*/

        $channels = merge_webhook_with_channels($channels, $youtubePushSubscribe);
    }

    log_message($counter . " [youtube] items processed.");


}

function twitchSetup(&$channels){
    if(isset($_GET['cron']) && $_GET['cron'] == "youtube") return;
    
    $counter = 0;
    // Собираем все user_id в массив
    $user_ids = [];
    foreach ($channels as $channel) {
        if($channel['platform'] != "twitch") continue;
        if(!isset($channel['broadcaster_id'])) continue;
        $user_ids[] = $channel['broadcaster_id'];
    }
    // Получаем информацию для всех стримеров разом
    $streams_info = get_stream_info_by_user_ids($user_ids);
    // Обрабатываем данные для каждого стримера
    if (!$streams_info) {
        log_message("No streams are live for the given user IDs.");
    }

    // Register EventSub for each channel using broadcaster_id or getting it if necessary
    foreach ($channels as $index => $channel) {
        if($channel['platform'] != "twitch") continue;
        $currentTime = time();

        if (isset($channel['broadcaster_id'])) {
            //$livestreamInfo = get_stream_info_by_user_id($channel['broadcaster_id']);
            $livestreamInfo = null;

            // Проходим по массиву $streams_info и ищем стрим с необходимым user_id
            foreach ($streams_info as $stream) {
                if (isset($stream['user_id']) && $stream['user_id'] == $channel['broadcaster_id']) {
                    $livestreamInfo = $stream;
                    //log_message($livestreamInfo['viewer_count']);
                    break; // Прерываем цикл, если нашли нужный стрим
                }
            }
            //$livestreamInfo = $streams_info[$channel['broadcaster_id']];
            
            if($livestreamInfo == null){
                unset($channels[$index]['startedAt']);
                unset($channels[$index]['viewers']);
            }else {
                if(isset($livestreamInfo['started_at']) && !isset($channel['startedAt'])){
                    $channels[$index]['startedAt'] = $livestreamInfo['started_at'];
                }
                $channels[$index]['viewers'] = $livestreamInfo['viewer_count'];
            }
        }

        // Get broadcaster_id if not present
        if (!isset($channel['broadcaster_id'])) {
            $broadcaster_id = get_broadcaster_id($channel['nickname']);
            
            if ($broadcaster_id) {
                // Save broadcaster_id to the channel array
                $channels[$index]['broadcaster_id'] = $broadcaster_id;
                checkNotifyJson($broadcaster_id, $channel['name'], $channel['nickname']);
                log_message("Broadcaster ID for {$channel['nickname']} is {$broadcaster_id}");
            } else {
                log_message("Failed to get broadcaster ID for {$channel['nickname']}. Skipping.");
                $counter++;
                continue;
            }
        } else {
            $broadcaster_id = $channel['broadcaster_id'];
            checkNotifyJson($broadcaster_id, $channel['name'], $channel['nickname']);
        }

        // Check if webhook is registered and has not expired
        if (isset($channel['webhook_expires_at']) && isset($channel['broadcaster_id'])) {
            // Attempt to parse the timestamp in both ISO 8601 and Y-m-d H:i:s formats
            $webhookExpiresAt = strtotime($channel['webhook_expires_at']);
            if ($webhookExpiresAt === false) {
                $webhookExpiresAt = strtotime(date('Y-m-d H:i:s', strtotime($channel['webhook_expires_at'])));
            }

            //log_message("current time: " . $currentTime . ", expires at: " . $webhookExpiresAt);

            if ($currentTime < $webhookExpiresAt) {
                if(!isset($_GET['cron'])) log_message("Webhook still active for {$channel['nickname']}. Skipping.");
                $counter++;
                continue;
            }
        }

        // Register EventSub webhook for the broadcaster
        echo "Add sub for id: " . $channel['nickname'] . " (" . $broadcaster_id . ") - ";
        log_message("EventSub subscription response for {$channel['nickname']}: ");
        $onlineResponse = register_twitch_eventsub($broadcaster_id);
        log_message("EventSub subscription online OK");
        echo "OK, ";
        $offlineResponse = register_twitch_eventsub($broadcaster_id, "stream.offline");
        log_message("EventSub subscription offline OK");
        echo "OK, ";
        $updateResponse = register_twitch_eventsub($broadcaster_id, "channel.update");
        log_message("EventSub subscription updates OK");
        echo "OK";
        echo "<br/>";

        if (isset($onlineResponse['data']) && isset($offlineResponse['data']) && isset($updateResponse['data'])) {
            // Set the expiration time for the webhook (10 days from now)
            $channels[$index]['webhook_expires_at'] = date("c", strtotime("+".TWITCH_WEBHOOK_LEASE_DAYS." days"));
            $channels[$index]['webhook_online_id'] = $onlineResponse['data'][0]['id'];
            $channels[$index]['webhook_offline_id'] = $offlineResponse['data'][0]['id'];
            $channels[$index]['webhook_updates_id'] = $updateResponse['data'][0]['id'];
            log_message("EventSub registered for {$channel['nickname']} with expiration date " . $channels[$index]['webhook_expires_at']);
        } else {
            if($onlineResponse['status'] == 409 || $offlineResponse['status'] == 409 || $updateResponse['status'] == 409){
                $channels[$index]['webhook_expires_at'] = date("c", strtotime("+1 day"));
                log_message("EventSub is still active for {$channel['nickname']}, retry tomorrow.");
            }else{
                log_message("Failed to register EventSub for {$channel['nickname']}");
            }
        }

        $counter++;
    }

    log_message($counter . " [twitch] items processed.");

}
?>
