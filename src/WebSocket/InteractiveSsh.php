<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\WebSocket;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Net\SSH2;

/**
 * SSH2 with live PTY resizing.
 *
 * phpseclib's setWindowSize() only records dimensions used by the pty-req
 * when the shell channel opens — it never informs an already-open shell.
 * Interactive terminals resize constantly, so this subclass adds the
 * RFC 4254 §6.7 "window-change" channel request for the shell channel.
 */
class InteractiveSsh extends SSH2
{
    /**
     * Resize the remote PTY of the open shell channel.
     */
    public function sendWindowChange(int $columns, int $rows): void
    {
        // Keep the stored size in sync for any future pty-req.
        $this->setWindowSize($columns, $rows);

        if (! isset($this->server_channels[self::CHANNEL_SHELL])) {
            return;
        }

        $packet = Strings::packSSH2(
            'CNsbN4',
            98, // NET_SSH2_MSG_CHANNEL_REQUEST (runtime-defined constant in phpseclib)
            $this->server_channels[self::CHANNEL_SHELL],
            'window-change',
            false, // want_reply
            $columns,
            $rows,
            0, // width in pixels — unknown
            0, // height in pixels — unknown
        );

        $this->send_binary_packet($packet);
    }
}
