<?php

declare(strict_types=1);

namespace TinyTank\Images;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Renderer\Inline\ImageRenderer as CommonMarkImageRenderer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;

/**
 * `![Our team](team.jpg)` in a post or page: a <picture> with responsive versions (see Images) when the
 * image is a file of the post's folder; anything else (a URL, an SVG) as CommonMark renders it.
 */
final class ImageRenderer implements \League\CommonMark\Renderer\NodeRendererInterface, ConfigurationAwareInterface
{
    /** Node data key MarkdownParser sets to the image's file, for the images of the post's own folder. */
    public const SOURCE = 'tinytank_source';

    private readonly CommonMarkImageRenderer $fallback;

    public function __construct(private readonly Images $images)
    {
        $this->fallback = new CommonMarkImageRenderer();
    }

    /**
     * CommonMark configures the renderers it knows about; the fallback is ours, so it gets the
     * configuration through here (it reads allow_unsafe_links). Without it, every image that isn't a
     * raster file of the folder (a URL, /favicon.svg, an SVG in the folder) failed to render.
     */
    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->fallback->setConfiguration($configuration);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string
    {
        if (!$node instanceof Image) {
            throw new \InvalidArgumentException('Expected an image.');
        }
        $source = $node->data->get(self::SOURCE, null);
        if (!is_string($source) || !Images::isRaster($source)) {
            return $this->fallback->render($node, $childRenderer);
        }
        $title = $node->getTitle();

        return $this->images->picture($source, $node->getUrl(), self::altText($node), attributes: ['title' => $title !== null && $title !== '' ? $title : null]);
    }

    private static function altText(Image $node): string
    {
        $alt = '';
        foreach (new NodeIterator($node) as $child) {
            if ($child instanceof StringContainerInterface) {
                $alt .= $child->getLiteral();
            } elseif ($child instanceof Newline) {
                $alt .= "\n";
            }
        }

        return $alt;
    }
}
