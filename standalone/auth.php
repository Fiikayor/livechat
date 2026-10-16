<?php
declare(strict_types=1);

// Dashboard login: auth.php/login, auth.php/logout, auth.php/me
require __DIR__ . '/autoload.php';

use LiveChat\Core\Http\Response;
use LiveChat\Core\Support\ChatException;
use LiveChat\Standalone\{App, HttpBridge};

try {
    $response = App::boot(__DIR__)->auth->handle(HttpBridge::request());
} catch (ChatException $e) {
    $response = new Response($e->httpStatus, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
} catch (Throwable $e) {
    error_log('[livechat] ' . $e);
    $response = new Response(500, ['error' => ['code' => 'server_error', 'message' => 'Something went wrong on the server.']]);
}

HttpBridge::send($response);
