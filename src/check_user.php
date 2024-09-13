<?php
require_once 'config.php';
require_once 'functions.php';

// Получаем POST-запрос
$requestEncoded = file_get_contents('php://input');
$request = json_decode($requestEncoded, true);
$user_id = intval($request['user_id']) ?? '';

log_message("Request to '".$_SERVER['REQUEST_URI']."', with data: `" . $requestEncoded ."`");

$response = ['status' => 'error'];

// Проверяем, является ли пользователь администратором
if ($user_id == ADMIN_ID) {
    $response = [
        'status' => 'ok',
        'is_admin' => true
    ];
} elseif (!empty($user_id)) {
    $response = [
        'status' => 'ok',
        'is_admin' => false
    ];
}

echo json_encode($response);
