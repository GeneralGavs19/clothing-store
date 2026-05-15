import { defineStore } from 'pinia'
import api from '../api/client'

export const useDashboardStore = defineStore('dashboard', {
  state: () => ({ data: null, loading: false, error: null }),
  actions: {
    async fetch() {
      this.loading = !this.data
      this.error = null
      try {
        const response = await api.get('/dashboard')
        this.data = response.data
      } catch (error) {
        this.error = error
        throw error
      } finally {
        this.loading = false
      }
    },
  },
})
