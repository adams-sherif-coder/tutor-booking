/** Tutor Booking API helper. Dependency-free; tokens are kept in memory. */
export class ApiError extends Error {
  constructor(status, message, errors = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

export function createApiClient(baseUrl = 'http://127.0.0.1:8000/api') {
  let token = null;
  const base = baseUrl.replace(/\/+$/, '');

  async function request(path, { method = 'GET', query = {}, body, signal } = {}) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
      }
    }
    const suffix = params.size ? `?${params}` : '';
    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    // Fetch network errors and AbortError propagate to the caller.
    const response = await fetch(`${base}/${path.replace(/^\/+/, '')}${suffix}`, {
      method, headers, credentials: 'omit', signal,
      ...(body !== undefined ? { body: JSON.stringify(body) } : {})
    });
    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new ApiError(response.status, 'The server returned a non-JSON response.');
    }
    if (!response.ok || !payload || payload.ok !== true) {
      throw new ApiError(response.status, payload?.message || 'API request failed.', payload?.errors || {});
    }
    return payload.data;
  }

  return {
    request,
    setToken(value) { token = value || null; },
    clearToken() { token = null; },
    async login(email, password) {
      const data = await request('/login', { method: 'POST', body: { email, password } });
      token = data.token;
      return data;
    },
    async register(fields) {
      const data = await request('/register', { method: 'POST', body: fields });
      token = data.token;
      return data;
    },
    async logout() {
      const data = await request('/logout', { method: 'POST' });
      token = null;
      return data;
    }
  };
}

/** Convert two Date instants to the API's minute-precision, same-UTC-day body. */
export function instantsToUtcBody(start, end) {
  if (!(start instanceof Date) || !(end instanceof Date) ||
      !Number.isFinite(start.getTime()) || !Number.isFinite(end.getTime())) {
    throw new Error('Select valid start and end dates.');
  }
  if (end <= start) throw new Error('End must be later than start.');
  if (start.getUTCSeconds() || end.getUTCSeconds() ||
      start.getUTCMilliseconds() || end.getUTCMilliseconds()) {
    throw new Error('Select whole-minute times.');
  }
  const startIso = start.toISOString();
  const endIso = end.toISOString();
  if (startIso.slice(0, 10) !== endIso.slice(0, 10)) {
    throw new Error('The API requires start and end on the same UTC date.');
  }
  return { date: startIso.slice(0, 10), start_time: startIso.slice(11, 16), end_time: endIso.slice(11, 16) };
}

/** datetime-local values use the browser's timezone, not an arbitrary selected timezone. */
export function localDateTimesToUtcBody(startValue, endValue) {
  const pattern = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
  if (!pattern.test(startValue) || !pattern.test(endValue)) {
    throw new Error('Use datetime-local values in YYYY-MM-DDTHH:mm format.');
  }
  function parse(value) {
    const result = new Date(value);
    const pad = n => String(n).padStart(2, '0');
    const roundTrip = `${result.getFullYear()}-${pad(result.getMonth() + 1)}-${pad(result.getDate())}T${pad(result.getHours())}:${pad(result.getMinutes())}`;
    if (roundTrip !== value) throw new Error('Invalid local date/time or a daylight-saving clock gap.');
    return result;
  }
  return instantsToUtcBody(parse(startValue), parse(endValue));
}
