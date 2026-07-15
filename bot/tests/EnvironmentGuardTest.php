<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Environment.php';
require_once dirname(__DIR__) . '/core/ConfigValidator.php';

final class EnvironmentGuardTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $this->testLegacyProductionCompatibility();
        $this->testLocalDefaults();
        $this->testConflictingEnvironmentFails();
        $this->testUnexpectedHostFails();
        $this->testProductionDevUserFails();
        $this->testStagingProductionHostFails();
        $this->testStagingProductionTokenFails();
        $this->testStagingMissingTokenFails();
        $this->testStagingMissingExpectedBotFails();
        $this->testStagingProductionDataFails();
        $this->testStagingLivePaymentFails();
        $this->testIsolatedStagingPasses();

        fwrite(STDOUT, "Environment guard: {$this->assertions} assertions passed.\n");
    }

    private function testLegacyProductionCompatibility(): void
    {
        $config = ConfigValidator::validate([
            'base_url' => 'https://play.example.com',
            'bot_token' => '100000:production_token_value_for_test',
            'data_dir' => '/srv/mgw_data',
            'allow_browser_dev_user' => true,
        ], ['HTTP_HOST' => 'play.example.com']);

        $this->same('production', $config['environment']);
        $this->same(['play.example.com'], $config['allowed_hosts']);
        $this->same('legacy-inference', $config['environment_context']['source']);
    }

    private function testLocalDefaults(): void
    {
        $config = ConfigValidator::validate([
            'environment' => 'local',
            'base_url' => 'http://localhost:8080',
            'bot_token' => 'PASTE_BOT_TOKEN_HERE',
            'data_dir' => dirname(__DIR__) . '/data',
            'allow_browser_dev_user' => true,
        ], ['HTTP_HOST' => 'localhost:8080']);

        $this->same('local', $config['environment']);
        $this->true(in_array('localhost', $config['allowed_hosts'], true));
    }

    private function testConflictingEnvironmentFails(): void
    {
        putenv('MGW_ENV=staging');
        try {
            $this->throws(fn () => ConfigValidator::validate([
                'environment' => 'production',
                'base_url' => 'https://play.example.com',
                'bot_token' => '100000:production_token_value_for_test',
            ]), 'environment settings conflict');
        } finally {
            putenv('MGW_ENV');
        }
    }

    private function testUnexpectedHostFails(): void
    {
        $this->throws(fn () => ConfigValidator::validate([
            'environment' => 'production',
            'base_url' => 'https://play.example.com',
            'allowed_hosts' => ['play.example.com'],
            'bot_token' => '100000:production_token_value_for_test',
        ], ['HTTP_HOST' => 'evil.example.net']), 'request host is not allowlisted');
    }

    private function testProductionDevUserFails(): void
    {
        $this->throws(fn () => ConfigValidator::validate([
            'environment' => 'production',
            'base_url' => 'https://play.example.com',
            'allowed_hosts' => ['play.example.com'],
            'bot_token' => '100000:production_token_value_for_test',
            'force_browser_dev_user' => true,
        ]), 'forbidden in production');
    }

    private function testStagingProductionHostFails(): void
    {
        $this->throws(fn () => ConfigValidator::validate($this->stagingConfig([
            'base_url' => 'https://play.example.com',
            'allowed_hosts' => ['play.example.com'],
        ])), 'overlaps production');
    }

    private function testStagingProductionTokenFails(): void
    {
        $token = '100000:production_token_value_for_test';
        $this->throws(fn () => ConfigValidator::validate($this->stagingConfig([
            'bot_token' => $token,
            'environment_guard' => [
                'production_hosts' => ['play.example.com'],
                'production_bot_token_sha256' => hash('sha256', $token),
                'production_data_dir' => '/srv/mgw_data',
            ],
        ])), 'token matches production');
    }

    private function testStagingMissingTokenFails(): void
    {
        $this->throws(fn () => ConfigValidator::validate($this->stagingConfig([
            'bot_token' => 'PASTE_STAGING_BOT_TOKEN_HERE',
        ])), 'token is not configured');
    }

    private function testStagingMissingExpectedBotFails(): void
    {
        $this->throws(fn () => ConfigValidator::validate($this->stagingConfig([
            'staging_bot_username' => '',
        ])), 'expected Telegram bot username');
    }

    private function testStagingProductionDataFails(): void
    {
        $this->throws(fn () => ConfigValidator::validate($this->stagingConfig([
            'data_dir' => '/srv/mgw_data',
        ])), 'data directory matches production');
    }

    private function testStagingLivePaymentFails(): void
    {
        $this->throws(fn () => ConfigValidator::validate($this->stagingConfig([
            'telegram_stars_mode' => 'live',
        ])), 'forbidden outside production');
    }

    private function testIsolatedStagingPasses(): void
    {
        $config = ConfigValidator::validate($this->stagingConfig(), [
            'HTTP_HOST' => 'staging.example.com',
        ]);

        $this->same('staging', $config['environment']);
        $this->same(['staging.example.com'], $config['allowed_hosts']);
    }

    private function stagingConfig(array $override = []): array
    {
        $base = [
            'environment' => 'staging',
            'base_url' => 'https://staging.example.com',
            'allowed_hosts' => ['staging.example.com'],
            'bot_token' => '200000:staging_token_value_for_test',
            'staging_bot_username' => 'mgw_test_bot',
            'data_dir' => '/srv/mgw_staging_data',
            'environment_guard' => [
                'production_hosts' => ['play.example.com'],
                'production_data_dir' => '/srv/mgw_data',
            ],
        ];

        return array_replace_recursive($base, $override);
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

(new EnvironmentGuardTest())->run();
