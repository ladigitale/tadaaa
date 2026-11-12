<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Mcp\Processor\ArtifactRunnerUiProcessor;
use PHPUnit\Framework\TestCase;

final class ArtifactRunnerUiTest extends TestCase
{
    private function template(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/src/Mcp/Ui/artifact-runner.html');
    }

    public function testOriginsAreInjectedAsJsonStrings(): void
    {
        $html = ArtifactRunnerUiProcessor::render($this->template(), 'https://artifacts.example', 'https://api.example');

        self::assertStringContainsString('var ARTIFACTS_ORIGIN = "https://artifacts.example";', $html);
        self::assertStringContainsString('var API_ORIGIN = "https://api.example";', $html);
        self::assertStringNotContainsString('__ARTIFACTS_ORIGIN__', $html);
        self::assertStringNotContainsString('__API_ORIGIN__', $html);
    }

    public function testInjectedValuesCannotCloseTheScript(): void
    {
        $html = ArtifactRunnerUiProcessor::render($this->template(), 'https://a.example/</script><script>x()', '');

        self::assertStringNotContainsString('</script><script>x()', $html);
    }

    public function testCspListsOwnOriginsOnce(): void
    {
        $csp = ArtifactRunnerUiProcessor::csp('https://artifacts.example', 'https://api.example', 'https://api.example');

        self::assertSame(['https://artifacts.example', 'https://api.example'], \array_slice($csp['resourceDomains'], 0, 2));
        self::assertContains('https://cdn.jsdelivr.net', $csp['connectDomains']);
        self::assertSame(\count($csp['connectDomains']), \count(array_unique($csp['connectDomains'])));
    }
}
