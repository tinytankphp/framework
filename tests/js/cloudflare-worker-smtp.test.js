// @vitest-environment node
import net from 'node:net';
import { afterEach, describe, expect, it } from 'vitest';
import { smtp } from '../../resources/static/cloudflare-worker.js';
import { connect } from './cloudflare-sockets.js';

// DDEV's Mailpit listens on 1025 in the container: the last test uses it when it's there.
const mailpit = await new Promise((ok) => {
    const probe = net.connect(1025, '127.0.0.1');
    probe.on('connect', () => {
        probe.destroy();
        ok(true);
    });
    probe.on('error', () => ok(false));
});

const raw = 'From: "Lab909" <forms@lab909.dev>\r\nTo: <me@lab909.dev>\r\nSubject: Test\r\n\r\nLine one\r\n.starts with a dot\r\n';

/** A scripted SMTP server: what it was told, and AUTH as configured. */
function fakeServer({ auth = 'LOGIN PLAIN', password = 'secret' } = {}) {
    const transcript = [];
    const server = net.createServer((socket) => {
        let buffer = '';
        let data = false;
        let login = null;
        socket.write('220 fake ESMTP\r\n');
        socket.on('data', (chunk) => {
            buffer += chunk.toString();
            let end;
            while ((end = buffer.indexOf(data ? '\r\n.\r\n' : '\r\n')) !== -1) {
                const line = buffer.slice(0, end);
                buffer = buffer.slice(end + (data ? 5 : 2));
                transcript.push(line);
                const reply = (text) => socket.write(text + '\r\n');
                if (data) {
                    data = false;
                    reply('250 queued');
                } else if (login === 'user') {
                    login = 'password';
                    reply('334 UGFzc3dvcmQ6');
                } else if (login === 'password') {
                    login = null;
                    reply(Buffer.from(line, 'base64').toString() === password ? '235 ok' : '535 bad credentials');
                } else if (line.startsWith('EHLO')) {
                    reply(`250-fake\r\n250-SIZE 1000000${auth ? `\r\n250-AUTH ${auth}` : ''}\r\n250 HELP`);
                } else if (line.startsWith('AUTH PLAIN ')) {
                    const [, , pass] = Buffer.from(line.slice(11), 'base64').toString().split('\0');
                    reply(pass === password ? '235 ok' : '535 bad credentials');
                } else if (line === 'AUTH LOGIN') {
                    login = 'user';
                    reply('334 VXNlcm5hbWU6');
                } else if (line === 'DATA') {
                    data = true;
                    reply('354 go on');
                } else if (line === 'QUIT') {
                    reply('221 bye');
                    socket.end();
                } else {
                    reply('250 ok');
                }
            }
        });
    });

    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, transcript, port: server.address().port })));
}

let servers = [];
afterEach(() => {
    servers.forEach((s) => s.close());
    servers = [];
});

describe('sending through your mail server', () => {
    it('logs in with AUTH PLAIN and sends the message, dots escaped', async () => {
        const { server, transcript, port } = await fakeServer();
        servers.push(server);

        await smtp({ SMTP_HOST: '127.0.0.1', SMTP_PORT: String(port), SMTP_USER: 'forms@lab909.dev', SMTP_PASSWORD: 'secret' }, 'forms@lab909.dev', 'me@lab909.dev', raw, connect);

        expect(transcript[0]).toBe('EHLO lab909.dev');
        expect(transcript[1]).toBe(`AUTH PLAIN ${Buffer.from('\0forms@lab909.dev\0secret').toString('base64')}`);
        expect(transcript.slice(2, 5)).toEqual(['MAIL FROM:<forms@lab909.dev>', 'RCPT TO:<me@lab909.dev>', 'DATA']);
        expect(transcript[5]).toContain('\r\n..starts with a dot');
        expect(transcript.at(-1)).toBe('QUIT');
    });

    it('falls back to AUTH LOGIN when the server only offers that', async () => {
        const { server, transcript, port } = await fakeServer({ auth: 'LOGIN' });
        servers.push(server);

        await smtp({ SMTP_HOST: '127.0.0.1', SMTP_PORT: String(port), SMTP_USER: 'forms@lab909.dev', SMTP_PASSWORD: 'secret' }, 'forms@lab909.dev', 'me@lab909.dev', raw, connect);

        expect(transcript.slice(1, 4)).toEqual(['AUTH LOGIN', Buffer.from('forms@lab909.dev').toString('base64'), Buffer.from('secret').toString('base64')]);
    });

    it('says what the server refused, never the password', async () => {
        const { server, port } = await fakeServer();
        servers.push(server);

        const sending = smtp({ SMTP_HOST: '127.0.0.1', SMTP_PORT: String(port), SMTP_USER: 'forms@lab909.dev', SMTP_PASSWORD: 'wrong-password' }, 'forms@lab909.dev', 'me@lab909.dev', raw, connect);

        await expect(sending).rejects.toThrow('The mail server answered 535: 535 bad credentials');
        await expect(sending).rejects.not.toThrow(/wrong-password/);
    });

    it('needs its secrets', async () => {
        await expect(smtp({}, 'a@b.test', 'c@d.test', raw, connect)).rejects.toThrow('SMTP_HOST is not set');
    });

    // DDEV's Mailpit, a real SMTP server, when the tests run where it is.
    it.runIf(mailpit)(
        'delivers to a real server (DDEV\'s Mailpit)',
        async () => {
            const subject = `TinyTank SMTP test ${Date.now()}`;
            await smtp({ SMTP_HOST: '127.0.0.1', SMTP_PORT: '1025' }, 'forms@lab909.dev', 'me@lab909.dev', raw.replace('Subject: Test', `Subject: ${subject}`), connect);

            const found = await (await fetch(`http://127.0.0.1:8025/api/v1/search?query=${encodeURIComponent(`subject:"${subject}"`)}`)).json();
            expect(found.messages_count).toBe(1);
        },
    );
});
