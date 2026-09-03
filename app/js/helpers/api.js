// Previous: 0.1.1
// Current: 0.1.5

const settings = window.mwflow || {};

async function request(path, options = {}) {
  const url = `${settings.restUrl}${path}`;
  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': settings.restNonce,
    ...(options.headers || {})
  };
  const response = await fetch(url, { ...options, headers });
  if (!response.ok) {
    let message = `Request failed (${response.status})`;
    let kind = 'http_error';
    let data = null;
    try {
      const body = await response.json();
      if (body?.message) message = body.message;
      // WP_Error data field — our REST endpoints stash error_kind there.
      if (body?.data?.error_kind) kind = body.data.error_kind;
      if (body?.data) data = body.data;
    } catch (_) { /* ignore */ }
    const err = new Error(message);
    err.kind = kind;
    err.data = data;
    err.status = response.status;
    throw err;
  }
  if (response.status === 204) return null;
  return response.json();
}

export const api = {
  // Reads
  integrations: () => request('/integrations'),
  flows: () => request('/flows'),
  flow: (id) => request(`/flows/${id}`),
  runs: (flowId) => request(`/runs${flowId ? `?flow_id=${flowId}` : ''}`),
  run: (id) => request(`/runs/${id}`),
  logs: () => request('/logs'),
  templates: () => request('/templates'),

  // Writes
  createFlow: (data) => request('/flows', { method: 'POST', body: JSON.stringify(data) }),
  updateFlow: (id, data) => request(`/flows/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  deleteFlow: (id) => request(`/flows/${id}`, { method: 'DELETE' }),
  publishFlow: (id) => request(`/flows/${id}/publish`, { method: 'POST' }),
  toggleCaptureSample: (id, enabled) => request(`/flows/${id}/capture-sample`, {
    method: 'POST',
    body: JSON.stringify({ enabled })
  }),
  testFlow: (id, payload = {}, { stepwise = false } = {}) => request(`/flows/${id}/run`, {
    method: 'POST',
    body: JSON.stringify(stepwise ? { stepwise: true, payload } : payload)
  }),
  advanceRun: (id) => request(`/runs/${id}/advance`, { method: 'POST' }),
  clearLogs: () => request('/logs', { method: 'DELETE' }),

  // AI authoring
  author: ({ prompt, mode = 'new', context = null, persist }) =>
    request('/author', {
      method: 'POST',
      body: JSON.stringify({ prompt, mode, context, persist })
    }),

  // Templates
  templates: () => request('/templates'),
  installTemplate: (id) => request(`/templates/${encodeURIComponent(id)}/install`, { method: 'POST' }),

  // Maintenance
  settings: () => request('/settings'),
  updateSettings: (data) => request('/settings', { method: 'POST', body: JSON.stringify(data) }),
  exportSettings: () => request('/maintenance/export'),
  importSettings: (data) => request('/maintenance/import', {
    method: 'POST',
    body: JSON.stringify(data)
  }),
  resetSettings: () => request('/maintenance/reset', { method: 'POST' })
};

export const restUrl = settings.restUrl;
export const pluginUrl = settings.pluginUrl;
