<?php

declare(strict_types=1);

namespace app\controller;

use support\Request;
use support\Response;

class IndexController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->renderTemplate('index/view', ['name' => 'Galen AI']);
    }
}
