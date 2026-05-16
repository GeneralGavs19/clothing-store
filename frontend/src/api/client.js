import axios from 'axios'
import { resolveApiUrl } from '../config/api'

const api = axios.create({
  baseURL: resolveApiUrl(),
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
    }
    return Promise.reject(error)
  },
)

export function apiError(error) {
  const data = error.response?.data
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  return data?.message || error.message || 'Ошибка запроса'
}

export default api
