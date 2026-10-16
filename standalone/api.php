<?php
declare(strict_types=1);

// Every request from the chat widget and the dashboard ends up here.
require __DIR__ . '/autoload.php';

use LiveChat\Core\Http\Response;
use LiveChat\Core\Support\ChatException;
use LiveChat\Standalone\{App, HttpBridge};

$app = null;

try {
    $app = App::boot(__DIR__);
    $response = $app->router->handle(HttpBridge::request());
} catch (ChatException $e) {
    // only "not installed" gets here, the router handles its own errors
    $response = new Response($e->httpStatus, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
} catch (Throwable $e) {
    error_log('[livechat] ' . $e);
    $response = new Response(500, ['error' => ['code' => 'server_error', 'message' => 'Something went wrong on the server.']]);
}

HttpBridge::send($response);

// reply is out, now it's safe to be slow
if ($app !== null) {
    HttpBridge::finish();
    try {
        $app->notifier->flush();
    } catch (Throwable $e) {
        error_log('[livechat] ' . $e);
    }
}
