<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TinyTank\Console\Console;
use TinyTank\Console\StaticBuildCommand;
use TinyTank\Console\StaticPublishCommand;

/** `bin/console publish:static`: the build on Cloudflare, with an API token and the forms' SMTP secrets from .env. */
final class StaticPublishTest extends FrameworkTestCase
{
    private const KEYS = ['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD', 'PUSHOVER_TOKEN', 'PUSHOVER_USER'];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    private function tester(string $root): CommandTester
    {
        return new CommandTester(Console::application($root)->find('publish:static'));
    }

    public function testItNeedsAnApiTokenAndAnAccount(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        $tester = $this->tester($root);

        $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID', $tester->getDisplay());
    }

    public function testItNeedsABuildForCloudflare(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        self::write($root, ['.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\n"]);
        $tester = $this->tester($root);

        $tester->execute(['--no-build' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("There's no build for Cloudflare in deploy/", $tester->getDisplay());
    }

    public function testSmtpFormsGetTheirSecretsNeverTheirValuesShown(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        self::write($root, [
            '.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\n",
            'deploy/' . StaticBuildCommand::MARKER => '', 'deploy/wrangler.jsonc' => '{}', 'deploy/worker.js' => 'const FORMS = {"contact": {"send": "cloudflare"}};',
        ]);
        $tester = $this->tester($root);

        $tester->execute(['--dry-run' => true]);
        self::assertSame("cd '{$root}/deploy' && npx --yes wrangler@4 deploy\nNo secrets.", trim($tester->getDisplay()), 'sent by Cloudflare: no secrets');

        self::write($root, ['deploy/worker.js' => 'const FORMS = {"contact": {"send": "smtp"}};']);
        $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('set SMTP_HOST', $tester->getDisplay());

        self::write($root, ['.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\nSMTP_HOST=mail.example.com\nSMTP_PORT=465\nSMTP_USER=me\nSMTP_PASSWORD=s3cret\n"]);
        $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("wrangler@4 deploy\n", $tester->getDisplay());
        self::assertStringContainsString('wrangler@4 secret bulk, with SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD (values not shown)', $tester->getDisplay(), 'after the deploy: a new Worker has no deployments to look up');
        self::assertStringNotContainsString('s3cret', $tester->getDisplay());

        self::write($root, ['.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\nSMTP_HOST=mail.example.com\nSMTP_USER='a\$b'\nSMTP_PASSWORD=\"s3c\$ret\"\n"]);
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString("SMTP_PASSWORD in .env has a $ outside single quotes", $tester->getDisplay(), 'it would arrive changed');
        self::assertStringNotContainsString('SMTP_USER in .env', $tester->getDisplay(), 'single quotes keep it as written');
    }

    public function testPushoverKeysGoWithAnyFormWhenBothAreSet(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        self::write($root, [
            '.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\nPUSHOVER_TOKEN=app\nPUSHOVER_USER=me\n",
            'deploy/' . StaticBuildCommand::MARKER => '', 'deploy/wrangler.jsonc' => '{}', 'deploy/worker.js' => 'const FORMS = {"contact": {"send": "cloudflare"}};',
        ]);
        $tester = $this->tester($root);

        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('secret bulk, with PUSHOVER_TOKEN, PUSHOVER_USER (values not shown)', $tester->getDisplay(), 'whatever sends the form');

        putenv('PUSHOVER_USER');
        self::write($root, ['.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\nPUSHOVER_TOKEN=app\n"]);
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('need both PUSHOVER_TOKEN and PUSHOVER_USER', $tester->getDisplay());
        self::assertStringContainsString('No secrets.', $tester->getDisplay());
    }

    public function testTheSecretsReachWranglerOnItsInputOnly(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        // A stand-in for wrangler, which notes its calls and what it reads.
        self::write($root, [
            '.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\nSMTP_HOST=mail.example.com\nSMTP_PASSWORD='p\"a\\ss'\n",
            'deploy/' . StaticBuildCommand::MARKER => '', 'deploy/wrangler.jsonc' => '{}', 'deploy/worker.js' => '{"send": "smtp"}',
            'node_modules/.bin/wrangler' => "#!/bin/sh\necho \"$@\" >> calls.txt\nif [ \"$1\" = secret ]; then cat > secrets.txt; fi\n",
        ]);
        chmod("{$root}/node_modules/.bin/wrangler", 0755);
        $tester = $this->tester($root);

        $tester->execute(['--no-build' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame("deploy\nsecret bulk\n", file_get_contents("{$root}/deploy/calls.txt"), 'the deploy first, then the secrets');
        self::assertSame(['SMTP_HOST' => 'mail.example.com', 'SMTP_PASSWORD' => 'p"a\\ss'], json_decode((string) file_get_contents("{$root}/deploy/secrets.txt"), true), 'only the SMTP keys');
        self::assertStringNotContainsString('p"a', $tester->getDisplay());
    }
}
