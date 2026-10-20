// Stand-in for Cloudflare's `cloudflare:sockets` in the Worker tests: connect() on Node's own sockets,
// with the same shape (readable, writable, startTls(), close()).
import net from 'node:net';
import tls from 'node:tls';
import { Readable, Writable } from 'node:stream';

export function connect({ hostname, port }, { secureTransport = 'off' } = {}) {
    const socket = secureTransport === 'on' ? tls.connect({ host: hostname, port, servername: hostname }) : net.connect({ host: hostname, port });
    return wrap(socket, hostname);
}

function wrap(socket, hostname) {
    return {
        readable: Readable.toWeb(socket),
        writable: Writable.toWeb(socket),
        startTls: () => wrap(tls.connect({ socket, servername: hostname }), hostname),
        close: async () => socket.destroy(),
    };
}
