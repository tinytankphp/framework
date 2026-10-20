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
    private const KEYS = ['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD'];

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
        self::assertSame("cd '{$root}/deploy' && npx --yes wrangler@4 deploy\n without secrets", trim($tester->getDisplay()), 'sent by Cloudflare: no secrets');

        self::write($root, ['deploy/worker.js' => 'const FORMS = {"contact": {"send": "smtp"}};']);
        $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('set SMTP_HOST', $tester->getDisplay());

        self::write($root, ['.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\nSMTP_HOST=mail.example.com\nSMTP_PORT=465\nSMTP_USER=me\nSMTP_PASSWORD=s3cret\n"]);
        $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("deploy --secrets-file '{$root}/var/static-secrets.env'", $tester->getDisplay());
        self::assertStringContainsString('with the secrets SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD (values not shown)', $tester->getDisplay());
        self::assertStringNotContainsString('s3cret', $tester->getDisplay());
        self::assertFileDoesNotExist("{$root}/var/static-secrets.env", 'a dry run writes nothing');
    }

    public function testTheSecretsFileIsWrittenForWranglerAndRemoved(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        // A stand-in for wrangler, which copies the secrets file it was given.
        self::write($root, [
            '.env' => "CLOUDFLARE_API_TOKEN=token\nCLOUDFLARE_ACCOUNT_ID=account\nSMTP_HOST=mail.example.com\nSMTP_PASSWORD='p\"a\\ss'\n",
            'deploy/' . StaticBuildCommand::MARKER => '', 'deploy/wrangler.jsonc' => '{}', 'deploy/worker.js' => '{"send": "smtp"}',
            'node_modules/.bin/wrangler' => "#!/bin/sh\necho \"$@\" > args.txt\ncp \"$3\" secrets.txt\nstat -c %a \"$3\" > mode.txt\n",
        ]);
        chmod("{$root}/node_modules/.bin/wrangler", 0755);
        $tester = $this->tester($root);

        $tester->execute(['--no-build' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame("deploy --secrets-file {$root}/var/static-secrets.env", trim((string) file_get_contents("{$root}/deploy/args.txt")));
        self::assertSame("SMTP_HOST=\"mail.example.com\"\nSMTP_PASSWORD=\"p\\\"a\\\\ss\"\n", file_get_contents("{$root}/deploy/secrets.txt"), 'only the SMTP keys, quoted');
        self::assertSame('600', trim((string) file_get_contents("{$root}/deploy/mode.txt")), 'readable by its owner only');
        self::assertFileDoesNotExist("{$root}/var/static-secrets.env", 'removed right after');
        self::assertStringNotContainsString('p"a', $tester->getDisplay());
    }
}
