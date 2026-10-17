<?php

declare(strict_types=1);

namespace TinyTank\Content;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;
use Tempest\Highlight\Highlighter;

/**
 * Code blocks with syntax highlighting, done when content compiles (tempest/highlight): no
 * JavaScript, nothing loaded from elsewhere. The language comes from the fence (```php, ```twig…),
 * and the code is coloured with `hl-*` classes the site styles:
 *
 *   <pre data-lang="php"><code class="language-php"><span class="hl-keyword">echo</span> …</code></pre>
 *
 * A block without a language, or in a language the highlighter doesn't know, is plain escaped text
 * in the same markup. Languages: https://github.com/tempestphp/highlight (php, twig, html, css, js,
 * ts, json, yaml, bash/sh, sql, nginx, dotenv, dockerfile, diff, markdown, xml…).
 */
final class CodeBlockRenderer implements NodeRendererInterface
{
    public function __construct(private readonly Highlighter $highlighter = new Highlighter())
    {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (!$node instanceof FencedCode) {
            throw new \InvalidArgumentException('Incompatible node type: ' . $node::class);
        }

        $language = (string) preg_replace('/[^\w+#.-]/', '', $node->getInfoWords()[0] ?? '');
        if ($language === '') {
            return '<pre><code>' . Xml::escape($node->getLiteral()) . '</code></pre>';
        }
        $attribute = Xml::escape($language);

        return '<pre data-lang="' . $attribute . '"><code class="language-' . $attribute . '">'
            . $this->highlighter->parse($node->getLiteral(), strtolower($language))
            . '</code></pre>';
    }
}
