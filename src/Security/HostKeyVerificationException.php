<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Security;

use RuntimeException;

/**
 * Thrown when an SSH server's host key cannot be trusted under the configured
 * verification mode. The SSH connection must be aborted before authentication.
 */
class HostKeyVerificationException extends RuntimeException {}
