<?php

declare(strict_types=1);

use app\controller\SessionController;
use app\neuron\document\DocumentManager;
use app\neuron\factory\SessionAgentFactory;
use app\neuron\service\SessionChatService;
use app\neuron\service\SessionTitleGenerator;
use app\neuron\service\SessionTitleService;
use app\neuron\store\SessionStore;
use app\neuron\ui\ChatUiRenderer;
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
    SessionTitleGenerator::class => static fn (ContainerInterface $container): SessionTitleGenerator => new SessionTitleGenerator(
        $container->get(SessionStore::class),
    ),
    SessionTitleService::class => static fn (ContainerInterface $container): SessionTitleService => new SessionTitleService(
        $container->get(SessionStore::class),
        $container->get(SessionTitleGenerator::class),
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
        $container->get(SessionTitleService::class),
    ),
];
