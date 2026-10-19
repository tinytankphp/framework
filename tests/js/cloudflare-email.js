// Stand-in for Cloudflare's `cloudflare:email` module in the Worker tests: the message as built.
export class EmailMessage {
    constructor(from, to, raw) {
        Object.assign(this, { from, to, raw });
    }
}
