<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/StagingSetupGuard.php';

final class StagingSetupGuardTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $this->testProductionIsRejected();
        $this->testMissingKeyIsRejected();
        $this->testWrongKeyIsRejected();
        $this->testCorrectKeyPasses();
        $this->testWebhookUrlUsesStagingBaseUrl();
        $this->testUnexpectedBotIsRejected();
        $this->testExpectedBotPasses();

        fwrite(STDOUT, "Staging setup guard: {$this->assertions} assertions passed.\n");
    }

    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'environment' => 'staging',
            'base_url' => 'https://staging.example.com',
            'staging_setup_key' => 'correct-key-with-20-characters',
            'staging_bot_username' => 'mgw_test_bot',
        ], $overrides);
    }

    private function testProductionIsRejected(): void
    {
        $this->throws(
            fn () => StagingSetupGuard::assertStaging($this->config(['environment' => 'production'])),
            'unavailable'
        );
    }

    private function testMissingKeyIsRejected(): void
    {
        $this->throws(
            fn () => StagingSetupGuard::authorize($this->config(['staging_setup_key' => '']), 'correct-key-with-20-characters'),
            'not configured'
        );
    }

    private function testWrongKeyIsRejected(): void
    {
        $this->throws(
            fn () => StagingSetupGuard::authorize($this->config(), 'wrong-key-with-20-characters'),
            'access denied'
        );
    }

    private function testCorrectKeyPasses(): void
    {
        StagingSetupGuard::authorize($this->config(), 'correct-key-with-20-characters');
        $this->true(true);
    }

    private function testWebhookUrlUsesStagingBaseUrl(): void
    {
        $this->same(
            'https://staging.example.com/bot/webhook.php',
            StagingSetupGuard::webhookUrl($this->config())
        );
    }

    private function testUnexpectedBotIsRejected(): void
    {
        $this->throws(
            fn () => StagingSetupGuard::assertExpectedBot($this->config(), ['username' => 'production_bot']),
            'unexpected bot'
        );
    }

    private function testExpectedBotPasses(): void
    {
        StagingSetupGuard::assertExpectedBot($this->config(), ['username' => '@MGW_TEST_BOT']);
        $this->true(true);
    }

    private function throws(callable $callback, string $messagePart): void
    {
        try {
            $callback();
        } catch (RuntimeException $exception) {
            $this->true(str_contains(strtolower($exception->getMessage()), strtolower($messagePart)));
            return;
        }

        throw new RuntimeException('Expected RuntimeException was not thrown.');
    }

    private function same(mixed $expected, mixed $actual): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException('Assertion failed: values are not identical.');
        }
    }

    private function true(bool $condition): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException('Assertion failed: condition is false.');
        }
    }
}

(new StagingSetupGuardTest())->run();
