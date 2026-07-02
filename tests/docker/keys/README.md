# Throwaway SSH test keys

These ed25519 key pairs are **intentionally committed** to the repository.
They are throwaway credentials used exclusively by the integration/e2e test
sshd container (`tests/docker/compose.yaml`), which only ever listens on
`127.0.0.1:2299` of the machine running the test suite.

- `wts_test_key` / `wts_test_key.pub` — no passphrase.
- `wts_test_key_pw` / `wts_test_key_pw.pub` — passphrase `wts-passphrase`.

They grant access to nothing outside the disposable `wts` user inside that
container (password `wts-secret`). Never reuse them anywhere else.
