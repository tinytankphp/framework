<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use TinyTank\Console\Examples;
use TinyTank\Testing\KernelTestCase;

/** The starter examples site:clean removes: files listed in .examples, and example:start/end blocks. */
final class ExamplesTest extends KernelTestCase
{
    private function site(): string
    {
        $root = $this->tempDir('site');
        self::write($root, [
            '.examples' => "# The examples\ntemplates/_examples.twig\ncontent/faq\ncontent/pages/*/index.it.md\nmissing.txt\n",
            'templates/_examples.twig' => 'demo',
            'templates/index.twig' => "<h1>Home</h1>\n{# example:start #}\n{% include '_examples.twig' %}\n{# example:end #}\n<p>End</p>\n",
            'config/routes.php' => "<?php\n\$a = 1;\n    // example:start: the clock demo\n    \$clock = 2;\n    // example:end\n\$b = 3;\n",
            'resources/css/app.css' => "a {}\n/* example:start */\n.demo {}\n/* example:end */\n",
            'config/forms.yaml' => "a: 1\n# example:start\nb: 2\n# example:end\n",
            'content/faq/one.md' => 'x',
            'content/pages/about/index.md' => 'about',
            'content/pages/about/index.it.md' => 'chi siamo',
            'public/build/app.js' => "// example:start\n", // built files aren't scanned
            'AGENTS.md' => "Rules.\n<!-- example:start -->\nRun site:clean.\n<!-- example:end -->\n",
        ]);

        return $root;
    }

    public function testTheBlocksGoWithTheirMarkerLines(): void
    {
        self::assertSame(["a\nc\n", 1], Examples::strip("a\n// example:start\nb\n// example:end\nc\n"));
        self::assertSame(["a\n", 2], Examples::strip("a\n{# example:start #}\nb\n{# example:end #}\n# example:start x\n# example:end\n"));
        self::assertSame(['no markers', 0], Examples::strip('no markers'));
        self::assertSame(["a counter_example:start stays\n", 0], Examples::strip("a counter_example:start stays\n"), 'a whole word only');
    }

    public function testUnbalancedMarkersAreErrorsNamingTheLine(): void
    {
        foreach ([
            ["a\n// example:start\nb\n", 'routes.php: the example:start on line 2 has no example:end.'],
            ["a\n// example:end\n", 'routes.php: line 2: example:end without an example:start.'],
            ["// example:start\n// example:start\n// example:end\n", 'routes.php: line 2: example:start inside the block opened on line 1.'],
        ] as [$text, $message]) {
            try {
                Examples::strip($text, 'routes.php');
                self::fail("Must fail: {$message}");
            } catch (\RuntimeException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testThePlanThenTheCleanSite(): void
    {
        $root = $this->site();
        $examples = new Examples($root);

        self::assertTrue($examples->present());
        $plan = $examples->plan();
        self::assertSame(['content/faq', 'content/pages/about/index.it.md', 'templates/_examples.twig'], $plan['delete'], 'what exists, globs expanded');
        self::assertSame(['AGENTS.md' => 1, 'config/forms.yaml' => 1, 'config/routes.php' => 1, 'resources/css/app.css' => 1, 'templates/index.twig' => 1], $plan['edit']);
        self::assertFileExists("{$root}/content/faq/one.md", 'planning changes nothing');

        $examples->clean();
        self::assertSame("<h1>Home</h1>\n<p>End</p>\n", file_get_contents("{$root}/templates/index.twig"));
        self::assertSame("<?php\n\$a = 1;\n\$b = 3;\n", file_get_contents("{$root}/config/routes.php"));
        self::assertSame("Rules.\n", file_get_contents("{$root}/AGENTS.md"));
        self::assertDirectoryDoesNotExist("{$root}/content/faq");
        self::assertFileDoesNotExist("{$root}/content/pages/about/index.it.md");
        self::assertFileExists("{$root}/content/pages/about/index.md");
        self::assertFileExists("{$root}/public/build/app.js");
        self::assertFalse($examples->present(), '.examples goes last');
        self::assertSame(['delete' => [], 'edit' => []], $examples->plan(), 'a clean site has nothing to clean');
    }

    public function testABrokenMarkerChangesNothing(): void
    {
        $root = $this->site();
        self::write($root, ['src/Broken.php' => "<?php\n// example:start\n"]);

        try {
            (new Examples($root))->clean();
            self::fail('Must fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('src/Broken.php: the example:start on line 2 has no example:end.', $e->getMessage());
        }
        self::assertFileExists("{$root}/content/faq/one.md");
        self::assertStringContainsString('example:start', (string) file_get_contents("{$root}/templates/index.twig"));
        self::assertFileExists("{$root}/.examples");
    }

    public function testPathsStayInsideTheSite(): void
    {
        $root = $this->site();
        self::write($root, ['.examples' => "../outside\n"]);

        $this->expectExceptionMessage('.examples: "../outside" must be a path inside the site.');
        (new Examples($root))->plan();
    }
}
