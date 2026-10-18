// jsdom has no MathML support, but Datastar checks `el instanceof MathMLElement` when the DOM changes.
// No element is ever one here, so an empty class is enough.
// (Files that run in Node, like the Vite plugin's, have no DOM at all.)
if (typeof Element !== 'undefined') {
    globalThis.MathMLElement ??= class MathMLElement extends Element {};
}
