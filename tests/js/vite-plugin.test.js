// @vitest-environment node
// The TinyTank Vite plugin's build settings (resources/vite/tinytank.js).
import { expect, test } from 'vitest';
import tinytank from '../../resources/vite/tinytank.js';

const buildConfig = (user = {}) => {
    const plugin = tinytank({ input: ['resources/js/app.js'] }).find((p) => p.name === 'tinytank:config');

    return plugin.config(user, { command: 'build' }).build;
};

test('fonts are never inlined as data: URLs, which the CSP would block', () => {
    const inline = buildConfig().assetsInlineLimit;

    for (const font of ['orbitron-latin-ext-wght-normal.woff2', 'a.woff', 'b.ttf', 'c.otf', 'd.eot', 'E.WOFF2']) {
        expect(inline(font)).toBe(false);
    }
    expect(inline('icon.svg')).toBeUndefined(); // other small assets: Vite's own rule
});

test('a site can still choose its own limit', () => {
    expect(buildConfig({ build: { assetsInlineLimit: 0 } }).assetsInlineLimit).toBe(0);
});
