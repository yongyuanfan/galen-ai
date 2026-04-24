<?php

declare(strict_types=1);

namespace app\controller;

use app\neuron\DocumentManager;
use app\neuron\SessionChatService;
use app\neuron\SessionStore;
use support\Request;
use support\Response;

use function dechex;
use function is_array;
use function json_decode;
use function json_encode;
use function strlen;

class SessionController extends BaseController
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

    public function index(): Response
    {
        return $this->renderJson($this->store->all());
    }

    public function create(): Response
    {
        $session = $this->store->create();
        return $this->renderJson(['id' => $session['id']]);
    }

    public function delete(string $id): Response
    {
        $this->store->delete($id);
        return $this->renderJson(['ok' => true]);
    }

    public function render(string $id): Response
    {
        if ($this->store->get($id) === null) {
            return $this->jsonError('Session not found', 404);
        }

        return response($this->chat->renderSession($id), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function upload(Request $request, string $id): Response
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

            return $this->renderJson([
                'name' => $document['name'],
                'path' => $this->documents->resolvePath($id, $document),
            ]);
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage(), 500);
        }
    }

    public function chat(Request $request, string $id): Response|string
    {
        if ($this->store->get($id) === null) {
            return $this->jsonError('Session not found', 404);
        }

        $payload = json_decode($request->rawBody(), true);
        $message = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';
        $deepThinking = is_array($payload) ? (bool) ($payload['deep_thinking'] ?? false) : false;
        if ($message === '') {
            return $this->jsonError('Message is required', 422);
        }

        try {
            $this->streamSse($request, $this->chat->streamChat($id, $message, $deepThinking));
            return '';
        } catch (\Throwable $exception) {
            return $this->jsonError($exception->getMessage(), 500);
        }
    }

    public function approve(Request $request, string $id): Response|string
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
}
