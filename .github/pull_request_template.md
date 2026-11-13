## What changed?

## Chapa API v2 documentation / backward compatibility

## Security and money movement checklist

- [ ] No private keys, PII or webhook signatures committed/logged
- [ ] Server-side verification remains required before fulfillment
- [ ] Creation methods preserve explicit idempotency keys
- [ ] Existing PHP array payloads remain compatible
- [ ] Tests and docs added or rationale provided

## Verification (run before release)

- [ ] PHP lint
- [ ] `composer validate --strict`
- [ ] PHPUnit and Laravel tests
- [ ] Chapa test-mode integration, if applicable
