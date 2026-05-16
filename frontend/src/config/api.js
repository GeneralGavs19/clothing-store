/** Production Railway API — always used for production builds. */
export const PRODUCTION_API_URL =
  'https://clothing-store-production-9717.up.railway.app/api'

const LOCAL_API_URL = 'http://localhost:8000/api'

/** Invalid API URLs from Vercel/dashboard placeholders — never use these. */
function isInvalidApiUrl(url) {
  if (!url || typeof url !== 'string') return true
  const lower = url.toLowerCase().trim()
  if (!lower.startsWith('http')) return true
  if (!lower.includes('clothing-store-production-9717.up.railway.app')) {
    if (
      /replace|placeholder|your-backend|example\.com|localhost|127\.0\.0\.1/i.test(
        lower,
      )
    ) {
      return true
    }
    if (!/^https:\/\/[a-z0-9.-]+\.up\.railway\.app\/api\/?$/i.test(lower)) {
      return true
    }
  }
  return false
}

export function resolveApiUrl() {
  if (import.meta.env.PROD) {
    return PRODUCTION_API_URL
  }
  const fromEnv = import.meta.env.VITE_API_URL
  if (!isInvalidApiUrl(fromEnv)) {
    return fromEnv.replace(/\/$/, '')
  }
  return LOCAL_API_URL
}

export function resolveApiOrigin() {
  return resolveApiUrl().replace(/\/api\/?$/, '')
}
