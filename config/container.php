<?php

declare(strict_types=1);

$container = new Webman\Container();
$container->addDefinitions(require base_path('config/dependence.php'));

return $container;
