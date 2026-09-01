const test = require('node:test');
const assert = require('node:assert/strict');

const { requireLocalE2EBaseURL } = require('./safety');

test('accepts only explicit loopback HTTP origins', () => {
  assert.equal(requireLocalE2EBaseURL('http://localhost:8000/'), 'http://localhost:8000');
  assert.equal(requireLocalE2EBaseURL('http://127.0.0.1:8080/'), 'http://127.0.0.1:8080');
  assert.equal(requireLocalE2EBaseURL('http://[::1]:9000/'), 'http://[::1]:9000');
});

test('rejects missing, remote, or decorated browser targets', () => {
  const rejected = [
    '',
    'https://localhost:8000/',
    'http://amberfabrics.example/',
    'http://user:password@localhost:8000/',
    'http://localhost:8000/catalog',
    'http://localhost:8000/?debug=1',
    'http://localhost:8000/#catalog',
    'not-a-url',
  ];

  for (const candidate of rejected) {
    assert.throws(
      () => requireLocalE2EBaseURL(candidate),
      /E2E_BASE_URL must be an explicit loopback HTTP origin/,
      `Expected the E2E target to be rejected: ${candidate || '(empty)'}`
    );
  }
});
