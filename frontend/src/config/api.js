/** Production Railway API (used when Vercel env still has a placeholder). */
export const PRODUCTION_API_URL =
  'https://clothing-store-production-9717.up.railway.app/api'

const PLACEHOLDER_PATTERNS = [
  'your-backend-api-url',
  'REPLACE_WITH',
  'example.com/api',
]

function isPlaceholder(url) {
  if (!url || typeof url !== 'string') return true
  const lower = url.toLowerCase()
  return PLACEHOLDER_PATTERNS.some((p) => lower.includes(p))
}

export function resolveApiUrl() {
  const fromEnv = import.meta.env.VITE_API_URL
  if (!isPlaceholder(fromEnv)) return fromEnv.replace(/\/$/, '')
  if (import.meta.env.PROD) return PRODUCTION_API_URL
  return 'http://localhost:8000/api'
}

export function resolveApiOrigin() {
  return resolveApiUrl().replace(/\/api\/?$/, '')
}
