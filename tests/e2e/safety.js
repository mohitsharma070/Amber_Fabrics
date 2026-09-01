const TARGET_ERROR = 'E2E_BASE_URL must be an explicit loopback HTTP origin.';

function requireLocalE2EBaseURL(rawValue) {
  const value = String(rawValue || '').trim();
  let url;

  try {
    url = new URL(value);
  } catch (_error) {
    throw new Error(TARGET_ERROR);
  }

  const allowedHosts = new Set(['localhost', '127.0.0.1', '[::1]']);
  if (
    url.protocol !== 'http:'
    || !allowedHosts.has(url.hostname.toLowerCase())
    || url.username !== ''
    || url.password !== ''
    || url.pathname !== '/'
    || url.search !== ''
    || url.hash !== ''
  ) {
    throw new Error(TARGET_ERROR);
  }

  return url.origin;
}

module.exports = { requireLocalE2EBaseURL };
