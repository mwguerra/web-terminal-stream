# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Report vulnerabilities privately via GitHub's private vulnerability reporting:

1. Go to the [Security tab](https://github.com/mwguerra/web-terminal-stream/security) of this repository.
2. Click **Report a vulnerability** and fill in the advisory form.

You can expect an acknowledgement within 72 hours and a status update within 7 days. Please include a description of the issue, steps to reproduce, affected versions, and any known mitigations.

## Scope

This package streams a raw interactive PTY (local shell or SSH) to the browser over WebSocket. By design there is no command whitelist — access control happens at the boundaries. Reports are especially welcome for issues in:

- WebSocket token issuance and validation (single-use, encrypted, expiring tokens)
- The `useStreamTerminal` gate and `ConnectionPolicy` enforcement paths
- SSH host key verification (`SshHostKeyVerifier`)
- Encryption of connection configs at rest in the cache
- Origin validation and the connection/session resource caps

Hardening guidance for deployments (allow-lists, host key pinning, rate limits, resource caps) is documented in the Security section of the [README](README.md).
