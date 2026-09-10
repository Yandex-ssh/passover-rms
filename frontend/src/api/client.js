const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')

export class ApiError extends Error {
  constructor(message, status = 0, validationErrors = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.validationErrors = validationErrors
  }
}

export async function api(path, options = {}) {
  const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || '')
  const response = await fetch(`${API_BASE_URL}${path}`, {
    credentials: 'include',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}), ...(options.headers || {}) },
    ...options,
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    const message = body.message || body.error || 'The request could not be completed.'
    throw new ApiError(message, response.status, body.errors || {})
  }
  return body
}

export const get = (path) => api(path)
export const post = (path, data) => api(path, { method: 'POST', body: JSON.stringify(data) })
export const put = (path, data) => api(path, { method: 'PUT', body: JSON.stringify(data) })
export const remove = (path) => api(path, { method: 'DELETE' })
