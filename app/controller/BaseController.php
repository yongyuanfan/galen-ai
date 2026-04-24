<?php

declare(strict_types=1);

namespace app\controller;

use support\Request;
use support\Response;

use function dechex;
use function json;
use function json_encode;
use function strlen;
use function view;

abstract class BaseController
{
    protected function renderTemplate(string $view, array $data = []): Response
    {
        return view($view, $data);
    }

    protected function renderJson(array $data): Response
    {
        return json($data);
    }

   protected function jsonError(string $message, int $status): Response
    {
        return response(
            json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * @param iterable<int, array<string, mixed>> $messages
     */
    protected function streamSse(Request $request, iterable $messages): void
    {
        $connection = $request->connection;
        // 先发送 SSE 响应头，后续分块数据才能被客户端持续消费。
        $connection->send((string) new \Webman\Http\Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Transfer-Encoding' => 'chunked',
        ], ''), true);

        try {
            foreach ($messages as $message) {
                // 连接已启用 chunked 传输，这里将每个 SSE 帧包装成一个 HTTP 分块。
                $payload = 'data: ' . json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $connection->send(dechex(strlen($payload)) . "\r\n" . $payload . "\r\n", true);
            }
        } catch (\Throwable $exception) {
            $payload = 'data: ' . json_encode([
                'error' => [
                    'message' => $exception->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            $connection->send(dechex(strlen($payload)) . "\r\n" . $payload . "\r\n", true);
        }

        $connection->close("0\r\n\r\n", true);
    }
}
