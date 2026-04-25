<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\service\SessionTitleGenerator;
use app\neuron\store\SessionStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SessionTitleGeneratorTest extends TestCase
{
    public function testSanitizeRemovesListFormattingAndMarkdown(): void
    {
        $generator = new SessionTitleGenerator(new SessionStore());

        self::assertSame(
            '深度解读体检报告',
            $this->sanitize($generator, "为您构思了以下几个标题，均控制在 8 字以内：\n\n1. **深度解读体检报告**")
        );
    }

    public function testSanitizeFallsBackWhenOnlyThinkingTextIsReturned(): void
    {
        $generator = new SessionTitleGenerator(new SessionStore());

        self::assertSame(
            '帮我分析这份体检报告',
            $this->sanitize($generator, "Thinking Process:\n\n1. Analyze the request", '帮我分析这份体检报告')
        );
    }

    private function sanitize(SessionTitleGenerator $generator, string $title, string $fallback = '帮我分析这份体检报告'): string
    {
        $method = new ReflectionMethod($generator, 'sanitize');

        return $method->invoke($generator, $title, $fallback);
    }
}
