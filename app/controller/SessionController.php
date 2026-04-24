<?php

declare(strict_types=1);

namespace app\controller;

use app\neuron\DocumentManager;
use app\neuron\SessionChatService;
use app\neuron\SessionStore;
use support\Request;
use support\Response as SupportResponse;
use Webman\Http\Response;

use function dechex;
use function is_array;
use function json_decode;
use function json_encode;
use function strlen;

class SessionController
{
    private SessionStore $store;
    private SessionChatService $chat;
    private DocumentManager $documents;

    public function __construct(SessionStore $store, DocumentManager $documents, SessionChatService $chat)
    {
        $this->store = $store;
        $this->documents = $documents;
        $this->chat = $chat;
    }

    public function index(): SupportResponse
    {
        return json($this->store->all());
    }

    public function create(): SupportResponse
    {
        $session = $this->store->create();
        return json(['id' => $session['id']]);
    }

    public function delete(string $id): SupportResponse
    {
        $this->store->delete($id);
        return json(['ok' => true]);
    }

    public function render(string $id): SupportResponse
    {
        if ($this->store->get($id) === null) {
            return $this->jsonError('Session not found', 404);
        }

        return response($this->chat->renderSession($id), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function upload(Request $request, string $id): SupportResponse
    {
        if ($this->store->get($id) === null) {
            return $this->jsonError('Session not found', 404);
        }

        $file = $request->file('file');
        if ($file === null) {
            return $this->jsonError('No file uploaded', 422);
        }

        try {
            $document = $this->documents->save($id, $file);
            $this->store->touch($id);

            return json([
                'name' => $document['name'],
                'path' => $this->documents->resolvePath($id, $document),
            ]);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage(), 500);
        }
    }

    public function chat(Request $request, string $id): SupportResponse|string
    {
        if ($this->store->get($id) === null) {
            return $this->jsonError('Session not found', 404);
        }

        $payload = json_decode($request->rawBody(), true);
        $message = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';
        if ($message === '') {
            return $this->jsonError('Message is required', 422);
        }

        try {
            $this->streamSse($request, $this->chat->streamChat($id, $message));
            return '';
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage(), 500);
        }
    }

    public function approve(Request $request, string $id): SupportResponse|string
    {
        if ($this->store->get($id) === null) {
            return $this->jsonError('Session not found', 404);
        }

        $payload = json_decode($request->rawBody(), true);
        $approved = (bool) ($payload['approved'] ?? false);
        $reason = trim((string) ($payload['reason'] ?? ''));

        try {
            $this->streamSse($request, $this->chat->streamApprove($id, $approved, $reason));
            return '';
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage(), 500);
        }
    }

    private function jsonError(string $message, int $status): SupportResponse
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
    private function streamSse(Request $request, iterable $messages): void
    {
        $connection = $request->connection;
        // 先发送 SSE 响应头，后续分块数据才能被客户端持续消费。
        $connection->send((string) new Response(200, [
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
