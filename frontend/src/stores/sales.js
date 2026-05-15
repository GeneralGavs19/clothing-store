import { defineStore } from 'pinia'
import api from '../api/client'

export const useSalesStore = defineStore('sales', {
  state: () => ({
    sales: [],
    meta: { current_page: 1, last_page: 1, total: 0, per_page: 15 },
    pending: [],
    pendingMeta: { current_page: 1, last_page: 1, total: 0, per_page: 15 },
    loading: false,
    loadingPending: false,
  }),
  actions: {
    async fetch(params = {}) {
      this.loading = true
      try {
        const { data } = await api.get('/sales', { params })
        this.sales = data.data
        this.meta = data.meta
      } finally {
        this.loading = false
      }
    },
    async fetchPending(params = {}) {
      this.loadingPending = true
      try {
        const { data } = await api.get('/sales-pending', { params })
        this.pending = data.data
        this.pendingMeta = data.meta
      } finally {
        this.loadingPending = false
      }
    },
    async create(payload) {
      return api.post('/sales', payload)
    },
    async approve(id, admin_note = '') {
      return api.post(`/sales/${id}/approve`, { admin_note })
    },
    async reject(id, admin_note = '') {
      return api.post(`/sales/${id}/reject`, { admin_note })
    },
    async deleteSale(id) {
      return api.delete(`/sales/${id}`)
    },
  },
})
