<?php

declare(strict_types=1);

use app\controller\SessionController;
use app\neuron\ChatUiRenderer;
use app\neuron\DocumentManager;
use app\neuron\SessionAgentFactory;
use app\neuron\SessionChatService;
use app\neuron\SessionStore;
use Psr\Container\ContainerInterface;

return [
    SessionStore::class => static fn (): SessionStore => new SessionStore(),
    DocumentManager::class => static fn (ContainerInterface $container): DocumentManager => new DocumentManager(
        $container->get(SessionStore::class)
    ),
    ChatUiRenderer::class => static fn (): ChatUiRenderer => new ChatUiRenderer(),
    SessionAgentFactory::class => static fn (ContainerInterface $container): SessionAgentFactory => new SessionAgentFactory(
        $container->get(SessionStore::class),
        $container->get(DocumentManager::class),
    ),
    SessionChatService::class => static fn (ContainerInterface $container): SessionChatService => new SessionChatService(
        $container->get(SessionStore::class),
        $container->get(SessionAgentFactory::class),
        $container->get(ChatUiRenderer::class),
    ),
    SessionController::class => static fn (ContainerInterface $container): SessionController => new SessionController(
        $container->get(SessionStore::class),
        $container->get(DocumentManager::class),
        $container->get(SessionChatService::class),
    ),
];
