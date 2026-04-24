<?php

declare(strict_types=1);

namespace tests\Controller;

use app\controller\SessionController;
use app\neuron\DocumentManager;
use app\neuron\SessionChatService;
use app\neuron\SessionStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use support\Response;

use function json_decode;
use function sprintf;

final class SessionControllerTest extends TestCase
{
    public function testCreateReturnsCreatedSessionId(): void
    {
        $store = $this->createMock(SessionStore::class);
        $documents = $this->createMock(DocumentManager::class);
        $chat = $this->createMock(SessionChatService::class);

        $store->expects(self::once())->method('create')->willReturn([
            'id' => 'sess_test_1',
            'title' => 'New Session',
            'created_at' => 1,
            'updated_at' => 1,
            'pending_interrupt' => null,
        ]);

        $controller = new SessionController($store, $documents, $chat);
        $response = $controller->create();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 'sess_test_1'], $this->decodeJson($response));
    }

    public function testRenderReturnsJsonErrorWhenSessionIsMissing(): void
    {
        $store = $this->createMock(SessionStore::class);
        $documents = $this->createMock(DocumentManager::class);
        $chat = $this->createMock(SessionChatService::class);

        $store->expects(self::once())->method('get')->with('sess_missing')->willReturn(null);

        $controller = new SessionController($store, $documents, $chat);
        $response = $controller->render('sess_missing');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Session not found'], $this->decodeJson($response));
    }

    public function testUploadReturnsValidationErrorWhenFileIsMissing(): void
    {
        $store = $this->createMock(SessionStore::class);
        $documents = $this->createMock(DocumentManager::class);
        $chat = $this->createMock(SessionChatService::class);
        $request = $this->makeJsonRequest('POST', '/sessions/sess_upload/upload', '{}');

        $store->expects(self::once())->method('get')->with('sess_upload')->willReturn([
            'id' => 'sess_upload',
            'title' => 'New Session',
            'created_at' => 1,
            'updated_at' => 1,
            'pending_interrupt' => null,
        ]);

        $controller = new SessionController($store, $documents, $chat);
        $response = $controller->upload($request, 'sess_upload');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'No file uploaded'], $this->decodeJson($response));
    }

    public function testChatReturnsValidationErrorWhenMessageIsEmpty(): void
    {
        $store = $this->createMock(SessionStore::class);
        $documents = $this->createMock(DocumentManager::class);
        $chat = $this->createMock(SessionChatService::class);
        $request = $this->makeJsonRequest('POST', '/sessions/sess_chat/chat', '{"message":"   "}');

        $store->expects(self::once())->method('get')->with('sess_chat')->willReturn([
            'id' => 'sess_chat',
            'title' => 'New Session',
            'created_at' => 1,
            'updated_at' => 1,
            'pending_interrupt' => null,
        ]);

        $controller = new SessionController($store, $documents, $chat);
        $response = $controller->chat($request, 'sess_chat');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'Message is required'], $this->decodeJson($response));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function makeJsonRequest(string $method, string $uri, string $body): Request
    {
        return new Request(sprintf(
            "%s %s HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s",
            $method,
            $uri,
            strlen($body),
            $body,
        ));
    }
}
