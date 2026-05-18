import { defineStore } from 'pinia'
import {
  canAccessDashboard,
  canAccessReports,
  canManageCatalog,
  canManageUsers,
  canViewLogs,
} from '../utils/permissions'
import api from '../api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({ user: null, token: null, loading: false }),
  getters: {
    isAdminProgrammer: (state) => state.user?.role === 'admin_programmer',
    isStoreAdmin: (state) => state.user?.role === 'admin',
    isAdmin: (state) => state.user?.role === 'admin' || state.user?.role === 'admin_programmer',
    isCashier: (state) => state.user?.role === 'cashier',
    canAccessDashboard: (state) => canAccessDashboard(state.user),
    canManageCatalog: (state) => canManageCatalog(state.user),
    canAccessReports: (state) => canAccessReports(state.user),
    canManageUsers: (state) => canManageUsers(state.user),
    canViewLogs: (state) => canViewLogs(state.user),
  },
  actions: {
    restore() {
      if (this.token) return
      this.token = localStorage.getItem('auth_token')
      const raw = localStorage.getItem('auth_user')
      this.user = raw ? JSON.parse(raw) : null
    },
    async login(payload) {
      this.loading = true
      try {
        const { data } = await api.post('/auth/login', payload)
        this.token = data.token
        this.user = data.user
        localStorage.setItem('auth_token', data.token)
        localStorage.setItem('auth_user', JSON.stringify(data.user))
      } finally {
        this.loading = false
      }
    },
    async fetchMe() {
      if (!this.token) return
      const { data } = await api.get('/auth/me')
      this.user = data.user
      localStorage.setItem('auth_user', JSON.stringify(data.user))
    },
    async logout() {
      try {
        if (this.token) await api.post('/auth/logout')
      } finally {
        this.token = null
        this.user = null
        localStorage.removeItem('auth_token')
        localStorage.removeItem('auth_user')
      }
    },
  },
})
