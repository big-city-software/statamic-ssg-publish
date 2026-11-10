export async function cpJson(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(options.headers || {}),
    };

    const cookie = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    if (cookie) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.split('=').slice(1).join('='));
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });

    const data = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, data };
}
