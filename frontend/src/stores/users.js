import { defineStore } from 'pinia'
import api from '../api/client'

export const useUsersStore = defineStore('users', {
  state: () => ({ users: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 15 }, loading: false }),
  actions: {
    async fetch(params = {}) {
      this.loading = true
      try {
        const { data } = await api.get('/users', { params })
        this.users = data.data
        this.meta = data.meta
      } finally {
        this.loading = false
      }
    },
    async save(payload, id = null) {
      return id ? api.put(`/users/${id}`, payload) : api.post('/users', payload)
    },
    async disable(id) {
      return api.delete(`/users/${id}`)
    },
  },
})
