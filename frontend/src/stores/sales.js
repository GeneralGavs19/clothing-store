import { defineStore } from 'pinia'
import api from '../api/client'

export const useSalesStore = defineStore('sales', {
  state: () => ({
    sales: [],
    meta: { current_page: 1, last_page: 1, total: 0, per_page: 40 },
    pending: [],
    pendingMeta: { current_page: 1, last_page: 1, total: 0, per_page: 15 },
    loading: false,
    loadingMore: false,
    loadingPending: false,
  }),
  actions: {
    async fetch(params = {}, append = false) {
      if (append) {
        this.loadingMore = true
      } else {
        this.loading = true
      }
      try {
        const { data } = await api.get('/sales', { params })
        if (append) {
          const existingIds = new Set(this.sales.map((s) => s.id))
          const newItems = (data.data || []).filter((s) => !existingIds.has(s.id))
          this.sales = [...this.sales, ...newItems]
        } else {
          this.sales = data.data || []
        }
        this.meta = data.meta || {
          current_page: 1,
          last_page: 1,
          total: this.sales.length,
          per_page: params.per_page || 40,
        }
      } finally {
        this.loading = false
        this.loadingMore = false
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
    async createQuick(payload) {
      return api.post('/sales/quick', payload)
    },
    async approve(id, admin_note = '') {
      return api.post(`/sales/${id}/approve`, { admin_note })
    },
    async reject(id, admin_note = '') {
      return api.post(`/sales/${id}/reject`, { admin_note })
    },
    async cancelSale(id, cancel_reason = '') {
      const res = await api.post(`/sales/${id}/cancel`, { cancel_reason })
      const updated = res.data
      const idx = this.sales.findIndex((s) => s.id === id)
      if (idx !== -1) {
        this.sales[idx] = updated
      }
      return res
    },
    async deleteSale(id) {
      const res = await api.delete(`/sales/${id}`)
      this.sales = this.sales.filter((s) => s.id !== id)
      if (this.meta && this.meta.total > 0) {
        this.meta.total--
      }
      return res
    },
    async updateSale(id, payload) {
      const res = await api.put(`/sales/${id}`, payload)
      const updated = res.data
      const idx = this.sales.findIndex((s) => s.id === id)
      if (idx !== -1) {
        this.sales[idx] = updated
      }
      return res
    },
  },
})
