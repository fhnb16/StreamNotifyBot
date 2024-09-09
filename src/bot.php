<?php
require_once 'config.php';
require_once 'functions.php';

// detect cron request
if (isset($_GET['cron'])) {
    log_message("############# Cron task #############");
}

// detect cron request
if (isset($_GET['cron']) && $_GET['cron'] == "backup") {
    log_message("~~~ Backup channels.json ~~~");
    $channels = load_json('channels.json');
    save_json('backup/channels_' . date('c') . '.json', $channels);
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

// Load channels list from JSON file
$channels = load_json('channels.json');

if ($channels === null) {
    log_message("Error: Failed to load channels.json or the file is empty.");
    exit;
}

// Register EventSub for each channel using broadcaster_id or getting it if necessary
foreach ($channels as $index => $channel) {
    $currentTime = time();

    // Check if webhook is registered and has not expired
    /*if (isset($channel['webhook_expires_at']) && $currentTime < strtotime($channel['webhook_expires_at']) && isset($channel['broadcaster_id'])) {
        log_message("Webhook still active for {$channel['nickname']}. Skipping.");
        continue;
    }*/

    // Check if webhook is registered and has not expired
    if (isset($channel['webhook_expires_at']) && isset($channel['broadcaster_id'])) {
        // Attempt to parse the timestamp in both ISO 8601 and Y-m-d H:i:s formats
        $webhookExpiresAt = strtotime($channel['webhook_expires_at']);
        if ($webhookExpiresAt === false) {
            $webhookExpiresAt = strtotime(date('Y-m-d H:i:s', strtotime($channel['webhook_expires_at'])));
        }

        if ($currentTime < $webhookExpiresAt) {
            if(!isset($_GET['cron'])) log_message("Webhook still active for {$channel['nickname']}. Skipping.");
            continue;
        }
    }

    // Get broadcaster_id if not present
    if (!isset($channel['broadcaster_id'])) {
        $broadcaster_id = get_broadcaster_id($channel['nickname']);
        
        if ($broadcaster_id) {
            // Save broadcaster_id to the channel array
            $channels[$index]['broadcaster_id'] = $broadcaster_id;
            log_message("Broadcaster ID for {$channel['nickname']} is {$broadcaster_id}");
        } else {
            log_message("Failed to get broadcaster ID for {$channel['nickname']}. Skipping.");
            continue;
        }
    } else {
        $broadcaster_id = $channel['broadcaster_id'];
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
        log_message("Failed to register EventSub for {$channel['nickname']}");
    }
}

// Save updated JSON with broadcaster_id and webhook expiration time
save_json('channels.json', $channels);
?>
