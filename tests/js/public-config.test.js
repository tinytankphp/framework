// publicConfig(): the values allowlisted under `public` in config/app.php, printed by {{ public_config() }}.
import { expect, test } from 'vitest';
import { publicConfig } from '../../resources/js/tinytank.js';

test('reads the JSON data block', () => {
    document.body.innerHTML = '<script type="application/json" id="tinytank-config">{"media":"https://cdn.example.test","n":2}</script>';

    expect(publicConfig()).toEqual({ media: 'https://cdn.example.test', n: 2 });
});

test('no block, or a broken one: an empty object', () => {
    document.body.innerHTML = '';
    expect(publicConfig()).toEqual({});

    document.body.innerHTML = '<script type="application/json" id="tinytank-config">{oops</script>';
    expect(publicConfig()).toEqual({});
});
