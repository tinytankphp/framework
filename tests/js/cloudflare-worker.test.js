// @vitest-environment node
import { describe, expect, it, vi } from 'vitest';
import worker, { ALERT_INTERVAL, alert, handle, message, validate } from '../../resources/static/cloudflare-worker.js';

// A form as the build describes it (TinyTank\Static\Builder::formData()).
const form = {
    site: 'Lab909',
    pages: { '/contact': 'en', '/it/contatti': 'it' },
    fields: {
        name: { type: 'text', required: true, min: null, max: 100, choices: [] },
        email: { type: 'email', required: true, min: null, max: 254, choices: [] },
        message: { type: 'textarea', required: true, min: 10, max: 5000, choices: [] },
        consent: { type: 'checkbox', required: false, min: null, max: 1, choices: [] },
    },
    to: ['me@lab909.dev'],
    from: 'contact@forms.lab909.dev',
    reply_to: 'email',
    honeypot: true,
    max_links: 2,
    subject: { en: 'Message from {name}', it: 'Messaggio da {name}' },
    messages: {
        en: { required: 'This field is required.', email: 'Enter a valid email address.', choice: 'Choose one.', min: 'Use at least {min} characters.', max: 'Use at most {max} characters.', links: 'Please include at most {max} links.', not_sent: 'Your message wasn\'t sent.', check: 'Please check these:', back: 'Back to the form', failed: 'Sending didn\'t work.' },
        it: { required: 'Campo obbligatorio.', email: 'Email non valida.', choice: 'Scegli.', min: 'Almeno {min} caratteri.', max: 'Al massimo {max} caratteri.', links: 'Al massimo {max} link.', not_sent: 'Messaggio non inviato.', check: 'Controlla:', back: 'Torna al modulo', failed: 'Invio non riuscito.' },
    },
};

const valid = { name: 'Ada Lovelace', email: 'ada@example.org', message: 'Hello, a question about TinyTank.', _page: '/contact' };

function post(fields, headers = {}) {
    return new Request('https://lab909.dev/forms/contact', { method: 'POST', body: new URLSearchParams(fields), headers: { 'Sec-Fetch-Site': 'same-origin', ...headers } });
}

function env(fail = false) {
    const sent = [];
    return { sent, FORMS_EMAIL: { send: async (message) => { if (fail) throw new Error('quota'); sent.push(message); } }, ASSETS: { fetch: async () => new Response('a file') } };
}

describe('the Worker', () => {
    it('emails a valid form and sends the visitor to the page\'s "sent" state', async () => {
        const e = env();
        const response = await handle(post(valid), e, form, 'contact');

        expect(response.status).toBe(303);
        expect(response.headers.get('Location')).toBe('https://lab909.dev/contact/sent');
        expect(e.sent).toHaveLength(1);
        expect([e.sent[0].from, e.sent[0].to]).toEqual(['contact@forms.lab909.dev', 'me@lab909.dev']);
    });

    it('answers in the page\'s language, back to that page', async () => {
        const e = env();
        const response = await handle(post({ ...valid, _page: '/it/contatti', email: 'nope' }), e, form, 'contact');

        expect(response.status).toBe(422);
        const html = await response.text();
        expect(html).toContain('<html lang="it">');
        expect(html).toContain('<strong>email</strong>: Email non valida.');
        expect(html).toContain('<a href="/it/contatti">Torna al modulo</a>');
        expect(e.sent).toHaveLength(0);
    });

    it('checks like the PHP site: required, lengths, email, links', () => {
        const check = (fields) => validate(form, new URLSearchParams(fields), form.messages.en)[1];

        expect(check({})).toEqual([['name', 'This field is required.'], ['email', 'This field is required.'], ['message', 'This field is required.']]);
        expect(check({ ...valid, message: 'Too short' })).toEqual([['message', 'Use at least 10 characters.']]);
        expect(check({ ...valid, name: 'é'.repeat(101) })).toEqual([['name', 'Use at most 100 characters.']], 'characters, not bytes');
        expect(check({ ...valid, email: 'ada@example' })).toEqual([['email', 'Enter a valid email address.']]);
        expect(check({ ...valid, message: 'See https://a.test, http://b.test and www.c.test' })).toEqual([['', 'Please include at most 2 links.']]);
        expect(check({ ...valid, name: '  Ada\u0000 ' })).toEqual([]);
        expect(validate(form, new URLSearchParams({ ...valid, name: '  Ada\u0007 ', message: 'Line one\r\nline two\t!' }), form.messages.en)[0]).toMatchObject({ name: 'Ada', message: 'Line one\nline two\t!', consent: false });
    });

    it('tells a bot that filled the hidden field it worked, and sends nothing', async () => {
        const e = env();
        const response = await handle(post({ ...valid, website: 'https://spam.test' }), e, form, 'contact');

        expect(response.status).toBe(303);
        expect(e.sent).toHaveLength(0);
    });

    it('refuses posts from other sites, other methods and huge bodies', async () => {
        expect((await handle(post(valid, { 'Sec-Fetch-Site': 'cross-site' }), env(), form, 'contact')).status).toBe(403);
        expect((await handle(post(valid, { 'Sec-Fetch-Site': '', Origin: 'https://evil.test' }), env(), form, 'contact')).status).toBe(403);
        expect((await handle(new Request('https://lab909.dev/forms/contact'), env(), form, 'contact')).status).toBe(405);
        expect((await handle(post(valid, { 'Content-Length': '200000' }), env(), form, 'contact')).status).toBe(413);
    });

    it('says when sending failed, without losing the way back', async () => {
        const response = await handle(post(valid), env(true), form, 'contact');

        expect(response.status).toBe(502);
        expect(await response.text()).toContain('Sending didn&#39;t work.');
    });

    it('serves everything else as files', async () => {
        expect(await (await worker.fetch(new Request('https://lab909.dev/about'), env())).text()).toBe('a file');
        expect(await (await worker.fetch(new Request('https://lab909.dev/forms/unknown', { method: 'POST' }), env())).text()).toBe('a file');
    });

    describe('posted by Datastar', () => {
        const datastar = { 'Datastar-Request': 'true' };
        const withSent = { ...form, sent: { '/contact': '<form id="contact-form">\n  <p role="status">Thanks!</p>\n</form>' } };
        // The server-sent events, as [event, {key: [lines]}].
        const events = async (response) => (await response.text()).trim().split('\n\n').map((block) => {
            const [first, ...data] = block.split('\n');
            const fields = {};
            for (const line of data) {
                const [key, ...rest] = line.replace(/^data: /, '').split(' ');
                (fields[key] ??= []).push(rest.join(' '));
            }
            return [first.replace('event: ', ''), fields];
        });

        it('replaces the form with its "sent" state, in place', async () => {
            const e = env();
            const response = await handle(post(valid, datastar), e, withSent, 'contact');

            expect(response.status).toBe(200);
            expect(response.headers.get('Content-Type')).toBe('text/event-stream');
            expect(e.sent).toHaveLength(1);
            const [[signals, patch], [elements, html]] = await events(response);
            expect(signals).toBe('datastar-patch-signals');
            expect(JSON.parse(patch.signals[0])._forms.contact.sent).toBe(true);
            expect(elements).toBe('datastar-patch-elements');
            expect(html.elements).toEqual(['<form id="contact-form">', '  <p role="status">Thanks!</p>', '</form>']);
        });

        it('shows the mistakes next to their fields, and keeps what was typed', async () => {
            const e = env();
            const response = await handle(post({ ...valid, email: 'nope', message: 'http://a.test http://b.test http://c.test' }, datastar), e, withSent, 'contact');

            expect(response.status).toBe(200, 'Datastar only applies a 2xx answer');
            expect(e.sent).toHaveLength(0);
            const all = await events(response);
            expect(all).toHaveLength(1, 'no HTML: the form stays as it is');
            const { contact } = JSON.parse(all[0][1].signals[0])._forms;
            expect(contact.sent).toBe(false);
            expect(contact.errors).toEqual({ name: '', email: 'Enter a valid email address.', message: '', consent: '', _form: 'Please include at most 2 links.' });
        });

        it('says when sending failed, above the form', async () => {
            const [[, patch]] = await events(await handle(post(valid, datastar), env(true), withSent, 'contact'));

            expect(JSON.parse(patch.signals[0])._forms.contact.errors._form).toBe('Your message wasn\'t sent. Sending didn\'t work.');
        });

        it('tells a bot it worked', async () => {
            const e = env();
            const all = await events(await handle(post({ ...valid, website: 'x' }, datastar), e, withSent, 'contact'));

            expect(e.sent).toHaveLength(0);
            expect(JSON.parse(all[0][1].signals[0])._forms.contact.sent).toBe(true);
        });
    });
});

describe('the email', () => {
    const decode = (raw) => {
        const [head, body] = raw.split('\r\n\r\n');
        return [head, new TextDecoder().decode(Uint8Array.from(atob(body.replace(/\r\n/g, '')), (c) => c.charCodeAt(0)))];
    };

    it('comes from the site, with the visitor in Reply-To and every field in the text', () => {
        const [head, body] = decode(message(form, 'contact', { name: 'Zoë', email: 'zoe@example.org', message: 'Line one\nline two', consent: true }, 'it', 'me@lab909.dev'));

        expect(head).toContain('From: "Lab909" <contact@forms.lab909.dev>');
        expect(head).toContain('To: <me@lab909.dev>');
        expect(head).toContain('Reply-To: <zoe@example.org>');
        expect(head).toContain(`Subject: =?UTF-8?B?${btoa(String.fromCharCode(...new TextEncoder().encode('Messaggio da Zoë')))}?=`);
        expect(head).toMatch(/^Message-ID: <[0-9a-f-]+@forms\.lab909\.dev>$/m);
        expect(body).toBe('Lab909: contact\n\nname: Zoë\nemail: zoe@example.org\nmessage:\n\nLine one\nline two\n\nconsent: yes\n');
    });

    it('writes a plain subject as it is, and quotes only the sender\'s name', () => {
        const [head] = decode(message(form, 'contact', { name: 'Ada', email: 'ada@example.org', message: 'x'.repeat(20) }, 'en', 'me@lab909.dev'));

        expect(head).toContain('\r\nSubject: Message from Ada\r\n');
        expect(head).toContain('From: "Lab909" <contact@forms.lab909.dev>');
    });

    it('keeps line breaks typed in a field out of the headers', () => {
        const [head] = decode(message(form, 'contact', { name: 'Eve\r\nBcc: everyone@example.org', email: 'eve@example.org', message: 'x'.repeat(20) }, 'en', 'me@lab909.dev'));

        expect(head).not.toMatch(/^Bcc:/m);
        expect(head.split('\r\n').every((line) => line.length < 200)).toBe(true);
    });
});

describe('alerts through Pushover', () => {
    const calls = [];
    const send = async (url, init) => {
        calls.push([url, Object.fromEntries(init.body)]);
        return new Response('{"status":1}');
    };
    const keys = { PUSHOVER_TOKEN: 'app-token', PUSHOVER_USER: 'user-key' };

    it('pushes the form and the error, never what was written, at most once an hour', async () => {
        calls.length = 0;
        await alert(keys, 'Lab909: the "contact" form', 'A message wasn\'t sent: 535 Authentication Failed', 'https://lab909.dev/contact', 1_000, send);
        await alert(keys, 'Lab909: the "contact" form', 'again', 'https://lab909.dev/contact', 2_000, send);

        expect(calls).toHaveLength(1);
        expect(calls[0][0]).toBe('https://api.pushover.net/1/messages.json');
        expect(calls[0][1]).toEqual({ token: 'app-token', user: 'user-key', title: 'Lab909: the "contact" form', message: 'A message wasn\'t sent: 535 Authentication Failed', url: 'https://lab909.dev/contact' });

        await alert(keys, 'Lab909: the "contact" form', 'an hour later', 'https://lab909.dev/contact', 1_000 + ALERT_INTERVAL, send);
        expect(calls).toHaveLength(2);
    });

    it('does nothing without both secrets, and never fails the answer', async () => {
        calls.length = 0;
        await alert({ PUSHOVER_TOKEN: 'only-one' }, 'T', 'm', 'u', 0, send);
        expect(calls).toHaveLength(0);
        await expect(alert(keys, 'Other', 'm', 'u', 0, async () => { throw new Error('offline'); })).resolves.toBeUndefined();
    });

    it('is sent when a form couldn\'t be, after the answer', async () => {
        const pushed = [];
        vi.stubGlobal('fetch', async (url, init) => {
            pushed.push(Object.fromEntries(init.body));
            return new Response('{"status":1}');
        });
        try {
            const waited = [];
            const response = await handle(post(valid), { ...env(true), ...keys }, form, 'contact', { waitUntil: (promise) => waited.push(promise) });

            expect(response.status).toBe(502);
            expect(waited).toHaveLength(1, 'handed to waitUntil: the visitor doesn\'t wait for it');
            await Promise.all(waited);
            expect(pushed[0].message).toBe('A message wasn\'t sent: quota');
            expect(JSON.stringify(pushed)).not.toContain('Ada', 'nothing the visitor wrote');
        } finally {
            vi.unstubAllGlobals();
        }
    });
});

