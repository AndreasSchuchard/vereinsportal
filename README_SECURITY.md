Setup notes — secret handling

- Copy `.env.example` to `.env` and fill `IMAP_PASSWORD` and `ANLEITUNG_PASSWORD` for local testing.
- For production, set the environment variables in your PHP-FPM / webserver service environment instead of using `.env`.
- To verify locally run:

```bash
# run smoke test (built-in PHP server or CLI)
php SMOKE_TEST.php
```

If `IMAP_PASS_SET` and `ANLEITUNG_PASS_SET` show `yes`, loader found the secrets.
