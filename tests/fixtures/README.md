# Test Fixtures

**These keys are TEST-ONLY. They are committed to source. Never use them in
production or sandbox.**

## Files

- `merchant-private-test.pem` / `merchant-public-test.pem` — stand-in for the
  merchant key pair. The SDK signs access-token requests with the private key;
  fixture tests verify with the matching public key.
- `shopeepay-private-test.pem` / `shopeepay-public-test.pem` — stand-in for
  ShopeePay's webhook-signing key pair. Tests sign sample notify payloads
  with the private key (simulating ShopeePay) and verify with the public key
  (what the SDK does at runtime).

## Regenerate

```bash
openssl genrsa -out merchant-private-test.pem 2048
openssl pkcs8 -topk8 -nocrypt -in merchant-private-test.pem \
    -out merchant-private-test.pkcs8.pem
mv merchant-private-test.pkcs8.pem merchant-private-test.pem
openssl rsa -in merchant-private-test.pem -pubout -out merchant-public-test.pem
# Repeat for shopeepay-*-test.pem
```

PKCS#8 PEM format (`-----BEGIN PRIVATE KEY-----`) matches the SNAP BI docs
convention. PHP's `openssl_sign` accepts both PKCS#1 and PKCS#8, so either
works at runtime — pinning PKCS#8 makes fixtures match real-world key files.
