<?php

declare(strict_types=1);

use app\controller\SessionController;
use app\neuron\AsyncDispatcher;
use app\neuron\ChatUiRenderer;
use app\neuron\DocumentManager;
use app\neuron\SessionAgentFactory;
use app\neuron\SessionChatService;
use app\neuron\SessionStore;
use app\neuron\SessionTitleGenerator;
use app\neuron\SessionTitleService;
use Psr\Container\ContainerInterface;

return [
    SessionStore::class => static fn (): SessionStore => new SessionStore(),
    DocumentManager::class => static fn (ContainerInterface $container): DocumentManager => new DocumentManager(
        $container->get(SessionStore::class)
    ),
    AsyncDispatcher::class => static fn (): AsyncDispatcher => new AsyncDispatcher(),
    ChatUiRenderer::class => static fn (): ChatUiRenderer => new ChatUiRenderer(),
    SessionAgentFactory::class => static fn (ContainerInterface $container): SessionAgentFactory => new SessionAgentFactory(
        $container->get(SessionStore::class),
        $container->get(DocumentManager::class),
    ),
    SessionTitleGenerator::class => static fn (ContainerInterface $container): SessionTitleGenerator => new SessionTitleGenerator(
        $container->get(SessionStore::class),
    ),
    SessionTitleService::class => static fn (ContainerInterface $container): SessionTitleService => new SessionTitleService(
        $container->get(SessionStore::class),
        $container->get(SessionTitleGenerator::class),
        $container->get(AsyncDispatcher::class),
    ),
    SessionChatService::class => static fn (ContainerInterface $container): SessionChatService => new SessionChatService(
        $container->get(SessionStore::class),
        $container->get(SessionAgentFactory::class),
        $container->get(ChatUiRenderer::class),
        $container->get(SessionTitleService::class),
    ),
    SessionController::class => static fn (ContainerInterface $container): SessionController => new SessionController(
        $container->get(SessionStore::class),
        $container->get(DocumentManager::class),
        $container->get(SessionChatService::class),
    ),
];
