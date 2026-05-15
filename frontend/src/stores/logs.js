import { defineStore } from 'pinia'
import api from '../api/client'

export const useLogsStore = defineStore('logs', {
  state: () => ({ logs: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 }, loading: false }),
  actions: {
    async fetch(params = {}) {
      this.loading = true
      try {
        const { data } = await api.get('/logs', { params })
        this.logs = data.data
        this.meta = data.meta
      } finally {
        this.loading = false
      }
    },
  },
})
