// TinyTank's Worker for a static build on Cloudflare Workers (bin/console build:static, host
// "cloudflare"). Every page is a static file; this code runs only for what isn't one: the forms,
// posted to /forms/<name>. It checks them like the PHP site does (config/forms.php: required fields,
// lengths, email format, choices, the honeypot, the links limit) and emails them through the
// send_email binding (Cloudflare Email Routing, to your own verified address), then sends the visitor
// to the form page's "sent" state. The forms' settings are added below by the build.
import { EmailMessage } from 'cloudflare:email';

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
    const sent = Response.redirect(new URL(page.replace(/\/$/, '') + '/sent', request.url).toString(), 303);

    // A bot filled in the hidden field: it's told it worked, and nothing is sent.
    if (form.honeypot && String(data.get('website') ?? '') !== '') {
        return sent;
    }

    const [values, errors] = validate(form, data, texts);
    if (errors.length > 0) {
        return page_(texts.not_sent, texts.check, errors, page, texts.back, language, 422);
    }

    try {
        for (const to of form.to) {
            await env.FORMS_EMAIL.send(new EmailMessage(form.from, to, message(form, name, values, language, to)));
        }
    } catch (error) {
        console.error(`Form "${name}" not sent: ${error}`);
        return page_(texts.not_sent, texts.failed, [], page, texts.back, language, 502);
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
