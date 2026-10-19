import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Tests for resources/js (the 'tinytank' helpers) against the real Datastar client, in a simulated DOM.
// Each test file runs in its own environment: Datastar keeps its signals in module-level state.
export default defineConfig({
    resolve: {
        alias: {
            datastar: fileURLToPath(new URL('./resources/js/datastar.js', import.meta.url)),
            // The static build's Worker imports Cloudflare's runtime module: a stand-in for the tests.
            'cloudflare:email': fileURLToPath(new URL('./tests/js/cloudflare-email.js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
    },
});
