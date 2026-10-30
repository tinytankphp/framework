<?php

declare(strict_types=1);

namespace TinyTank\Content;

use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * `- [x] Write the docs`: a disabled checkbox named after its item's text, so screen readers announce
 * "Write the docs, checkbox, checked" instead of an unnamed checkbox (WCAG: form controls have a name).
 */
final class TaskListMarkerRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        if (!$node instanceof TaskListItemMarker) {
            throw new \InvalidArgumentException('Expected a task list item marker, got ' . $node::class . '.');
        }
        $text = '';
        for ($sibling = $node->next(); $sibling !== null; $sibling = $sibling->next()) {
            $text .= self::text($sibling);
        }
        $attributes = (array) $node->data->get('attributes') + ['type' => 'checkbox', 'disabled' => ''];
        if ($node->isChecked()) {
            $attributes['checked'] = '';
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($name !== '') {
            $attributes['aria-label'] = $name;
        }

        return new HtmlElement('input', $attributes, '', true);
    }

    /** The plain text of a node: its literal, or its children's (emphasis, links, code). */
    private static function text(Node $node): string
    {
        if ($node instanceof Newline) {
            return ' ';
        }
        if ($node instanceof AbstractStringContainer) {
            return $node->getLiteral();
        }
        $text = '';
        foreach ($node->children() as $child) {
            $text .= self::text($child);
        }

        return $text;
    }
}
