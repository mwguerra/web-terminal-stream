// @ts-check

/**
 * Records every WebSocket a page opens, with per-socket sent/received frame
 * logs. ghostty-web paints to a canvas (no DOM text), so WS frames are the
 * only reliable observation point for terminal I/O — and per-pane sockets
 * give exact isolation assertions.
 *
 * IMPORTANT: construct the recorder BEFORE page.goto() so no socket is missed.
 */
class WsRecorder {
    /**
     * @param {import('@playwright/test').Page} page
     */
    constructor(page) {
        /** @type {Array<{url: string, sent: string[], received: string[], closed: boolean}>} */
        this.sockets = [];

        page.on('websocket', (ws) => {
            const entry = {
                url: ws.url(),
                sent: [],
                received: [],
                closed: false,
            };
            this.sockets.push(entry);

            ws.on('framesent', (frame) => {
                entry.sent.push(frame.payload.toString());
            });
            ws.on('framereceived', (frame) => {
                entry.received.push(frame.payload.toString());
            });
            ws.on('close', () => {
                entry.closed = true;
            });
        });
    }

    /**
     * Wait until the page has opened exactly `count` sockets (or more).
     */
    async waitForSocketCount(count, timeoutMs = 15000) {
        const deadline = Date.now() + timeoutMs;
        while (Date.now() < deadline) {
            if (this.sockets.length >= count) {
                return this.sockets;
            }
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        throw new Error(`Expected ${count} WebSocket(s), saw ${this.sockets.length} after ${timeoutMs}ms`);
    }

    /**
     * Wait until socket `index`'s RECEIVED frames (joined) match `regex`.
     * Returns the joined received text.
     */
    async waitForText(index, regex, timeoutMs = 15000) {
        return this.#waitOnFrames(index, 'received', regex, timeoutMs);
    }

    /**
     * Wait until socket `index`'s SENT frames (joined) match `regex`.
     */
    async waitForSentText(index, regex, timeoutMs = 15000) {
        return this.#waitOnFrames(index, 'sent', regex, timeoutMs);
    }

    async #waitOnFrames(index, direction, regex, timeoutMs) {
        const deadline = Date.now() + timeoutMs;
        while (Date.now() < deadline) {
            const socket = this.sockets[index];
            if (socket) {
                const text = socket[direction].join('');
                if (regex.test(text)) {
                    return text;
                }
            }
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        const seen = this.sockets[index] ? this.sockets[index][direction].join('').slice(-500) : '<no socket>';
        throw new Error(`Socket ${index} ${direction} frames never matched ${regex} within ${timeoutMs}ms. Tail: ${seen}`);
    }

    /**
     * Joined received text of socket `index` right now (no waiting).
     */
    receivedText(index) {
        return this.sockets[index] ? this.sockets[index].received.join('') : '';
    }
}

module.exports = { WsRecorder };
