<?php

declare(strict_types=1);

namespace TinyTank\Tests;

/** config/globals.php: the site's own values in every template, as `globals`. */
final class GlobalsTest extends FrameworkTestCase
{
    public function testEveryTemplateGetsTheSitesValues(): void
    {
        $root = $this->copyToTemp(self::PROJECT);
        self::write($root, ['config/globals.php' => <<<'PHP'
            <?php

            return [
                'socials' => ['github' => 'https://github.com/lab909', 'x' => 'https://x.com/lab909'],
                'phone' => '+39 06 0000',
            ];
            PHP]);
        $app = $this->kernel(root: $root);

        $template = $app->twig->createTemplate('{{ globals.socials.github }} {{ globals.phone }}{% for name, url in globals.socials %} {{ name }}{% endfor %}');
        self::assertSame('https://github.com/lab909 +39 06 0000 github x', $template->render());
        self::assertSame('https://x.com/lab909', $app->globals['socials']['x'] ?? null, 'and in PHP, for controllers and commands');
    }

    public function testTheFileIsOptional(): void
    {
        $app = $this->kernel();

        self::assertSame([], $app->globals);
        self::assertSame('none', $app->twig->createTemplate('{{ globals.socials.x ?? "none" }}')->render());
    }

    public function testTestsCanReplaceThem(): void
    {
        $app = $this->kernel(overrides: ['globals' => ['phone' => '123']]);

        self::assertSame('123', $app->twig->createTemplate('{{ globals.phone }}')->render());
    }

    public function testTheFileReturnsAnArray(): void
    {
        $root = $this->copyToTemp(self::PROJECT);
        self::write($root, ['config/globals.php' => "<?php\n\nreturn 'oops';\n"]);

        $this->expectExceptionMessage('config/globals.php returns an array');
        $this->kernel(root: $root);
    }
}
