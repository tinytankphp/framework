// TinyTank's Worker for a static build on Cloudflare Workers (bin/console build:static, host
// "cloudflare"). Every page is a static file; this code runs only for what isn't one: the forms,
// posted to /forms/<name>. It checks them like the PHP site does (config/forms.php: required fields,
// lengths, email format, choices, the honeypot, the links limit) and emails them, then sends the
// visitor to the form page's "sent" state. Posted by Datastar (a Datastar-Request header), it answers
// in place instead, so nothing typed is lost: the form's "sent" HTML (from the build), or its errors as
// the `_forms.<name>` signals (`errors.<field>`, `errors._form`). Two ways to send, per form (`static.send` in
// config/forms.php): "cloudflare", the send_email binding (Cloudflare Email Service, Workers Paid), or
// "smtp", your own mail server (the SMTP_HOST, SMTP_PORT, SMTP_USER and SMTP_PASSWORD secrets,
// uploaded by `bin/console publish:static`). The forms' settings are added below by the build.
import { EmailMessage } from 'cloudflare:email';
import { connect } from 'cloudflare:sockets';

const FORMS = {}; // filled in by the build

const LIMIT = 100_000; // bytes: far more than any form needs

export default {
    async fetch(request, env) {
        const match = new URL(request.url).pathname.match(/^\/forms\/([a-z][a-z0-9_-]*)$/);
        const form = match ? FORMS[match[1]] : undefined;
        if (!form) {
            return env.ASSETS.fetch(request);
        }

        return handle(request, env, form, match[1]);
    },
};

/** One form submission: checked, emailed, answered. Exported for the tests. */
export async function handle(request, env, form, name) {
    if (request.method !== 'POST') {
        return new Response('Method Not Allowed', { status: 405, headers: { Allow: 'POST' } });
    }
    // Only from this site's own pages (the PHP site checks the same).
    const site = request.headers.get('Sec-Fetch-Site');
    const origin = request.headers.get('Origin');
    if ((site && !['same-origin', 'none'].includes(site)) || (origin && origin !== new URL(request.url).origin)) {
        return new Response('Forbidden', { status: 403 });
    }
    if (Number(request.headers.get('Content-Length') ?? 0) > LIMIT) {
        return new Response('Payload Too Large', { status: 413 });
    }

    const data = await request.formData();
    const page = Object.hasOwn(form.pages, data.get('_page')) ? data.get('_page') : Object.keys(form.pages)[0];
    const language = form.pages[page];
    const texts = form.messages[language] ?? Object.values(form.messages)[0];
    const datastar = request.headers.has('Datastar-Request');
    const sent = datastar
        ? answer(name, form, page, {}, true)
        : Response.redirect(new URL(page.replace(/\/$/, '') + '/sent', request.url).toString(), 303);

    // A bot filled in the hidden field: it's told it worked, and nothing is sent.
    if (form.honeypot && String(data.get('website') ?? '') !== '') {
        return sent;
    }

    const [values, errors] = validate(form, data, texts);
    if (errors.length > 0) {
        return datastar
            ? answer(name, form, page, Object.fromEntries(errors.map(([field, error]) => [field || '_form', error])))
            : page_(texts.not_sent, texts.check, errors, page, texts.back, language, 422);
    }

    try {
        for (const to of form.to) {
            const raw = message(form, name, values, language, to);
            if (form.send === 'smtp') {
                await smtp(env, form.from, to, raw);
            } else {
                await env.FORMS_EMAIL.send(new EmailMessage(form.from, to, raw));
            }
        }
    } catch (error) {
        console.error(`Form "${name}" not sent: ${error}`);
        return datastar
            ? answer(name, form, page, { _form: `${texts.not_sent} ${texts.failed}` })
            : page_(texts.not_sent, texts.failed, [], page, texts.back, language, 502);
    }

    return sent;
}

/** The submitted values, normalised as the PHP site does, and what's wrong with them. */
export function validate(form, data, texts) {
    const values = {};
    const errors = [];
    let links = 0;
    for (const [field, rule] of Object.entries(form.fields)) {
        const raw = data.get(field);
        if (rule.type === 'checkbox') {
            values[field] = ['1', 'on', 'true', 'yes'].includes(raw);
            if (rule.required && !values[field]) {
                errors.push([field, texts.required]);
            }
            continue;
        }
        // One string, without control characters (newlines and tabs only in a textarea).
        let value = typeof raw === 'string' ? raw.replace(/\r\n?/g, '\n') : '';
        value = value.replace(rule.type === 'textarea' ? /(?![\n\t])\p{C}/gu : /\p{C}/gu, '').trim();
        values[field] = value;
        links += (value.match(/https?:\/\/|www\./gi) ?? []).length;

        const length = [...value].length;
        const error =
            value === '' ? (rule.required ? texts.required : null)
            : rule.type === 'email' && !email(value) ? texts.email
            : rule.type === 'choice' && !rule.choices.includes(value) ? texts.choice
            : rule.min !== null && length < rule.min ? texts.min.replace('{min}', rule.min)
            : length > rule.max ? texts.max.replace('{max}', rule.max)
            : null;
        if (error) {
            errors.push([field, error]);
        }
    }
    if (form.max_links !== null && links > form.max_links) {
        errors.push(['', texts.links.replace('{max}', form.max_links)]);
    }

    return [values, errors];
}

/** An address as most mail servers take it: local@domain.tld, no spaces or line breaks. */
function email(value) {
    return value.length <= 254 && /^[^\s@"(),:;<>[\\\]]+@[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i.test(value);
}

/** The email, as TinyTank's PHP site writes it (templates/_emails/form.txt.twig): plain text, every field. */
export function message(form, name, values, language, to) {
    const domain = form.from.split('@')[1];
    const placeholders = Object.fromEntries(Object.entries(values).map(([field, value]) => [field, typeof value === 'boolean' ? (value ? '✓' : '–') : value.replace(/\s+/gu, ' ')]));
    const subject = (form.subject[language] ?? Object.values(form.subject)[0]).replace(/\{([a-z][a-z0-9_]*)\}/g, (whole, field) => placeholders[field] ?? whole);
    let body = `${form.site}: ${name}\n\n`;
    for (const [field, value] of Object.entries(values)) {
        body += typeof value === 'boolean' ? `${field}: ${value ? 'yes' : 'no'}\n`
            : value.includes('\n') ? `${field}:\n\n${value}\n\n`
            : `${field}: ${value}\n`;
    }
    const replyTo = form.reply_to && email(String(values[form.reply_to] ?? '')) ? values[form.reply_to] : null;
    const headers = [
        `From: ${encodeWord(form.site, true)} <${form.from}>`,
        `To: <${to}>`,
        ...(replyTo ? [`Reply-To: <${replyTo}>`] : []),
        `Subject: ${encodeWord(subject)}`,
        `Date: ${new Date().toUTCString().replace('GMT', '+0000')}`,
        `Message-ID: <${crypto.randomUUID()}@${domain}>`,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: base64',
    ];

    return headers.join('\r\n') + '\r\n\r\n' + base64(body).replace(/.{76}/g, '$&\r\n') + '\r\n';
}

/**
 * Sends one message through your mail server: port 465 (TLS from the start), 587 (STARTTLS), or any
 * other without TLS (a local test server). Logs in with AUTH PLAIN, or LOGIN, when SMTP_USER is set.
 * Exported for the tests.
 */
export async function smtp(env, from, to, raw, open = connect) {
    if (!env.SMTP_HOST) {
        throw new Error('SMTP_HOST is not set: upload the SMTP secrets (bin/console publish:static).');
    }
    const port = Number(env.SMTP_PORT || 465);
    const tls = port === 465 ? 'on' : port === 587 ? 'starttls' : 'off';
    let socket = open({ hostname: env.SMTP_HOST, port }, { secureTransport: tls, allowHalfOpen: false });
    let session = conversation(socket);
    try {
        await session.expect(220);
        const helo = from.split('@')[1] ?? 'localhost';
        let features = await session.command(`EHLO ${helo}`, 250);
        if (tls === 'starttls') {
            await session.command('STARTTLS', 220);
            session.release();
            socket = socket.startTls();
            session = conversation(socket);
            features = await session.command(`EHLO ${helo}`, 250);
        }
        if (env.SMTP_USER) {
            if (/AUTH[ =][^\n]*\bPLAIN\b/i.test(features)) {
                await session.command(`AUTH PLAIN ${base64(`\0${env.SMTP_USER}\0${env.SMTP_PASSWORD ?? ''}`)}`, 235);
            } else {
                await session.command('AUTH LOGIN', 334);
                await session.command(base64(env.SMTP_USER), 334);
                await session.command(base64(env.SMTP_PASSWORD ?? ''), 235);
            }
        }
        await session.command(`MAIL FROM:<${from}>`, 250);
        await session.command(`RCPT TO:<${to}>`, [250, 251]);
        await session.command('DATA', 354);
        // Lines starting with a dot get another one (RFC 5321); the message ends with a lone dot.
        await session.command(raw.replace(/\r\n\./g, '\r\n..').replace(/\r\n$/, '') + '\r\n.', 250);
        await session.command('QUIT', 221).catch(() => {});
    } finally {
        session.release();
        await socket.close().catch(() => {});
    }
}

/** Reading the server's replies and writing commands, line by line. */
function conversation(socket) {
    const reader = socket.readable.getReader();
    const writer = socket.writable.getWriter();
    const decoder = new TextDecoder();
    let buffer = '';

    // One reply: its lines up to the one with a space after the code ("250 OK", after "250-…" lines).
    async function reply() {
        for (;;) {
            const match = buffer.match(/^(\d{3}) .*\r?\n/m);
            if (match) {
                const stop = match.index + match[0].length;
                const text = buffer.slice(0, stop);
                buffer = buffer.slice(stop);
                return [Number(match[1]), text];
            }
            const { value, done } = await reader.read();
            if (done) {
                throw new Error('The mail server closed the connection.');
            }
            buffer += decoder.decode(value, { stream: true });
        }
    }

    async function expect(codes) {
        const [code, text] = await reply();
        if (![codes].flat().includes(code)) {
            // The server's last line only: nothing typed comes back (a password never does).
            throw new Error(`The mail server answered ${code}: ${text.trim().split('\n').pop()}`);
        }
        return text;
    }

    return {
        expect,
        async command(line, codes) {
            await writer.write(new TextEncoder().encode(line + '\r\n'));
            return expect(codes);
        },
        release() {
            reader.releaseLock();
            writer.releaseLock();
        },
    };
}

/**
 * A header text any mail program reads, whatever it contains (RFC 2047): plain ASCII as it is (a
 * display name in quotes), anything else encoded.
 */
function encodeWord(text, displayName = false) {
    if (/^[\x20-\x7e]{0,60}$/.test(text) && !/[=?"\\]/.test(text)) {
        return displayName ? `"${text}"` : text;
    }

    return `=?UTF-8?B?${base64(text)}?=`;
}

function base64(text) {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }

    return btoa(binary);
}

/** A small page in the form's language: what went wrong, and the way back. */
/**
 * The answer to a Datastar post, as server-sent events: the `_forms.<name>` signals (every field's
 * error, '' when none, so the last ones are cleared; `sent`), and when sent, the form's "sent" HTML
 * from the page, which replaces the form by its id.
 */
export function answer(name, form, page, errors, sent = false) {
    const all = Object.fromEntries([...Object.keys(form.fields), '_form'].map((field) => [field, errors[field] ?? '']));
    const events = [['datastar-patch-signals', [`signals ${JSON.stringify({ _forms: { [name]: { sent, errors: all } } })}`]]];
    const html = sent ? form.sent?.[page] : null;
    if (html) {
        events.push(['datastar-patch-elements', html.split('\n').map((line) => `elements ${line}`)]);
    }
    const body = events.map(([event, lines]) => `event: ${event}\n${lines.map((line) => `data: ${line}\n`).join('')}\n`).join('');

    return new Response(body, { headers: { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-store' } });
}

function page_(title, intro, errors, back, backText, language, status) {
    const escape = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const items = errors.map(([field, error]) => `<li>${field ? `<strong>${escape(field)}</strong>: ` : ''}${escape(error)}</li>`).join('');
    const html = `<!doctype html><html lang="${escape(language)}"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>${escape(title)}</title>`
        + `<main style="max-width:40rem;margin:4rem auto;padding:0 1rem;font:1rem/1.6 system-ui,sans-serif"><h1>${escape(title)}</h1><p>${escape(intro)}</p>`
        + (items ? `<ul>${items}</ul>` : '') + `<p><a href="${escape(back)}">${escape(backText)}</a></p></main></html>`;

    return new Response(html, {
        status,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Content-Security-Policy': "default-src 'none'; style-src-attr 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options': 'nosniff',
            'Cache-Control': 'no-store',
        },
    });
}
