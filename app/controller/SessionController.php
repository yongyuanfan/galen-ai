<?php

declare(strict_types=1);

namespace app\controller;

use app\neuron\DocumentManager;
use app\neuron\SessionChatService;
use app\neuron\SessionStore;
use app\neuron\SessionAgentFactory;
use app\neuron\ChatUiRenderer;
use support\Request;
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
    private SessionAgentFactory $agentFactory;
    private DocumentManager $documents;
    private ChatUiRenderer $renderer;
    public function __construct()
    {
        $this->store = new SessionStore();
        $this->documents = new DocumentManager($this->store);
        $this->renderer = new ChatUiRenderer();
        $this->agentFactory = new SessionAgentFactory($this->store, $this->documents);
        $this->chat = new SessionChatService($this->store, $this->agentFactory, $this->renderer);
    }

    public function index()
    {
        return json($this->store->all());
    }

    public function create()
    {
        $session = $this->store->create();
        return json(['id' => $session['id']]);
    }

    public function delete(string $id)
    {
        $this->store->delete($id);
        return json(['ok' => true]);
    }

    public function render(string $id)
    {
        if ($this->store->get($id) === null) {
            return response('Session not found', 404);
        }

        return response($this->chat->renderSession($id), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function upload(Request $request, string $id)
    {
        if ($this->store->get($id) === null) {
            return response(json_encode(['error' => 'Session not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 404, ['Content-Type' => 'application/json']);
        }

        $file = $request->file('file');
        if ($file === null) {
            return response(json_encode(['error' => 'No file uploaded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 422, ['Content-Type' => 'application/json']);
        }

        try {
            $document = $this->documents->save($id, $file);
            $this->store->touch($id);

            return json([
                'name' => $document['name'],
                'path' => $document['path'],
            ]);
        } catch (\Throwable $exception) {
            return response(json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 500, ['Content-Type' => 'application/json']);
        }
    }

    public function chat(Request $request, string $id)
    {
        if ($this->store->get($id) === null) {
            return response(json_encode(['error' => 'Session not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 404, ['Content-Type' => 'application/json']);
        }

        $payload = json_decode($request->rawBody(), true);
        $message = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';
        if ($message === '') {
            return response(json_encode(['error' => 'Message is required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 422, ['Content-Type' => 'application/json']);
        }

        try {
            $this->streamSse($request, $this->chat->streamChat($id, $message));
            return '';
        } catch (\Throwable $exception) {
            return response(json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 500, ['Content-Type' => 'application/json']);
        }
    }

    public function approve(Request $request, string $id)
    {
        if ($this->store->get($id) === null) {
            return response(json_encode(['error' => 'Session not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 404, ['Content-Type' => 'application/json']);
        }

        $payload = json_decode($request->rawBody(), true);
        $approved = (bool) ($payload['approved'] ?? false);
        $reason = trim((string) ($payload['reason'] ?? ''));

        try {
            $this->streamSse($request, $this->chat->streamApprove($id, $approved, $reason));
            return '';
        } catch (\Throwable $exception) {
            return response(json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 500, ['Content-Type' => 'application/json']);
        }
    }

    private function streamSse(Request $request, iterable $messages): void
    {
        $connection = $request->connection;
        $connection->send((string) new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Transfer-Encoding' => 'chunked',
        ], ''), true);

        try {
            foreach ($messages as $message) {
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
