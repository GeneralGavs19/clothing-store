/**
 * Shared formatting utilities for money, dates, numbers and percentages.
 */

/**
 * Formats a numeric value into Kazakhstan Tenge currency (e.g. "20 000 ₸").
 * @param {number|string|null} value
 * @param {number} [maxFractionDigits=0]
 * @returns {string}
 */
export function formatMoney(value, maxFractionDigits = 0) {
  const num = Number(value || 0)
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'KZT',
    maximumFractionDigits: maxFractionDigits,
  }).format(num)
}

// Alias for convenience across existing views
export const money = formatMoney

/**
 * Formats a plain number with thousand separators (e.g. "1 250").
 * @param {number|string|null} value
 * @returns {string}
 */
export function formatNumber(value) {
  const num = Number(value || 0)
  return new Intl.NumberFormat('ru-RU').format(num)
}

/**
 * Formats a percentage string (e.g. "35.5%").
 * @param {number|string|null} value
 * @param {number|string|null} total
 * @param {number} [decimals=1]
 * @returns {string}
 */
export function formatPercent(value, total, decimals = 1) {
  const val = Number(value || 0)
  const tot = Number(total || 0)
  if (!tot || tot <= 0) return '0%'
  const pct = (val / tot) * 100
  return `${pct.toFixed(decimals).replace(/\.0$/, '')}%`
}

export const percent = formatPercent

/**
 * Formats a date value using standard Russian locale string (e.g. "25.10.2026, 14:30:00").
 * @param {string|Date|null} value
 * @returns {string}
 */
export function date(value) {
  if (!value) return '—'
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('ru-RU')
}

/**
 * Formats an ISO or DB date string to local Russian date (e.g. "25.10.2026").
 * @param {string|Date|null} value
 * @param {boolean} [includeTime=false]
 * @returns {string}
 */
export function formatDate(value, includeTime = false) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'

  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const year = date.getFullYear()

  if (!includeTime) {
    return `${day}.${month}.${year}`
  }

  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${day}.${month}.${year} ${hours}:${minutes}`
}

/**
 * Formats a diff indicator with +/- sign (e.g. "+15.2%" or "-5 000 ₸").
 * @param {number|null} diff
 * @param {boolean} [isCurrency=false]
 * @returns {{ text: string, isPositive: boolean, isNegative: boolean, isZero: boolean }}
 */
export function formatDiff(diff, isCurrency = false) {
  if (diff === null || diff === undefined) {
    return { text: '—', isPositive: false, isNegative: false, isZero: true }
  }
  const val = Number(diff)
  if (val === 0) {
    return { text: isCurrency ? '0 ₸' : '0%', isPositive: false, isNegative: false, isZero: true }
  }
  const sign = val > 0 ? '+' : ''
  const formatted = isCurrency
    ? `${sign}${formatMoney(val)}`
    : `${sign}${val.toFixed(1).replace(/\.0$/, '')}%`

  return {
    text: formatted,
    isPositive: val > 0,
    isNegative: val < 0,
    isZero: false,
  }
}
