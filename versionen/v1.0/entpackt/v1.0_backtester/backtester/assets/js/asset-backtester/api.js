export class ApiError extends Error {
  constructor(message, code = 'REQUEST_FAILED', status = 0) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

export class BacktesterApi {
  constructor(baseUrl = 'api/backtester') {
    this.baseUrl = baseUrl.replace(/\/$/, '');
  }

  assets(signal) {
    return this.#get('assets.php', {}, signal);
  }

  summary({ amount = 10000, end = null } = {}, signal) {
    return this.#get('summary.php', { amount, end }, signal);
  }

  series({ assets, start, end, amount }, signal) {
    return this.#get('series.php', {
      assets: Array.isArray(assets) ? assets.join(',') : assets,
      start,
      end,
      amount,
    }, signal);
  }

  status(signal) {
    return this.#get('status.php', {}, signal);
  }

  async syncIfStale(signal) {
    return this.#request('sync-if-stale.php', {
      method: 'POST',
      signal,
      headers: { 'X-Backtester-Request': 'page-visit' },
    });
  }

  #get(path, params, signal) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') query.set(key, String(value));
    });
    const suffix = query.size ? `?${query.toString()}` : '';
    return this.#request(`${path}${suffix}`, { method: 'GET', signal });
  }

  async #request(path, options) {
    let response;
    try {
      response = await fetch(`${this.baseUrl}/${path}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        ...options,
        headers: {
          Accept: 'application/json',
          ...(options.headers || {}),
        },
      });
    } catch (error) {
      if (error.name === 'AbortError') throw error;
      throw new ApiError('Die Verbindung zur Backtester-API konnte nicht hergestellt werden.', 'NETWORK_ERROR');
    }

    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new ApiError('Die Backtester-API hat keine gültige Antwort geliefert.', 'INVALID_RESPONSE', response.status);
    }

    if (!response.ok || payload.success !== true) {
      throw new ApiError(
        payload?.error?.message || 'Die Anfrage konnte nicht verarbeitet werden.',
        payload?.error?.code || 'REQUEST_FAILED',
        response.status,
      );
    }
    return payload;
  }
}

