import { defineStore } from 'pinia'
import api from '../api/client'

export const useCatalogStore = defineStore('catalog', {
  state: () => ({
    products: [],
    productMeta: { current_page: 1, last_page: 1, total: 0, per_page: 12 },
    categories: [],
    categoryMeta: { current_page: 1, last_page: 1, total: 0, per_page: 100 },
    loadingProducts: false,
    loadingCategories: false,
  }),
  actions: {
    async fetchProducts(params = {}) {
      this.loadingProducts = true
      try {
        const { data } = await api.get('/products', { params })
        this.products = data.data
        this.productMeta = data.meta
      } finally {
        this.loadingProducts = false
      }
    },
    async saveProduct(payload, id = null) {
      const form = new FormData()
      Object.entries(payload).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') return
        if (key === 'variants' && Array.isArray(value)) {
          value.forEach((variant, index) => {
            form.append(`variants[${index}][size]`, variant.size ?? '')
            form.append(`variants[${index}][stock_quantity]`, String(variant.stock_quantity ?? variant.quantity ?? 0))
            form.append(`variants[${index}][display_quantity]`, String(variant.display_quantity ?? 0))
          })
          return
        }
        form.append(key, value)
      })
      const config = { headers: { 'Content-Type': 'multipart/form-data' } }
      if (id) {
        form.append('_method', 'PUT')
        return api.post(`/products/${id}`, form, config)
      }
      return api.post('/products', form, config)
    },
    async deleteProduct(id) {
      return api.delete(`/products/${id}`)
    },
    async fetchCategories(params = { per_page: 100 }) {
      this.loadingCategories = true
      try {
        const { data } = await api.get('/categories', { params })
        this.categories = data.data
        this.categoryMeta = data.meta
      } finally {
        this.loadingCategories = false
      }
    },
    async saveCategory(payload, id = null) {
      return id ? api.put(`/categories/${id}`, payload) : api.post('/categories', payload)
    },
    async deleteCategory(id) {
      return api.delete(`/categories/${id}`)
    },
    async transferStock(payload) {
      return api.post('/stock/transfer', payload)
    },
    async importProducts(file, categoryId = '') {
      const form = new FormData()
      form.append('file', file)
      if (categoryId) form.append('category_id', categoryId)
      return api.post('/products/import', form, { headers: { 'Content-Type': 'multipart/form-data' } })
    },
    async adjustStock(payload) {
      return api.post('/stock/adjust', payload)
    },
  },
})
