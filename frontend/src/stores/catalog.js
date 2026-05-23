import { defineStore } from 'pinia'
import api from '../api/client'

function appendVariantRow(form, variant, index) {
  if (variant.id != null && variant.id !== '') {
    form.append(`variants[${index}][id]`, String(variant.id))
  }
  form.append(`variants[${index}][size]`, variant.size ?? '')
  const codes = (variant.items || variant.codes || []).map((c) => String(c || '').trim()).filter(Boolean)
  codes.forEach((code, i) => {
    form.append(`variants[${index}][items][${i}]`, code)
  })
  form.append(`variants[${index}][position]`, String(index))
}

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
    async fetchProduct(id) {
      const { data } = await api.get(`/products/${id}`)
      return data
    },
    async lookupItemByCode(code) {
      const { data } = await api.get(`/product-items/lookup/${encodeURIComponent(code)}`)
      return data
    },
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
      const skipRoot = new Set(['variants', 'photo', 'stock_quantity', 'display_quantity', 'sku', 'barcode', 'size'])

      Object.entries(payload).forEach(([key, value]) => {
        if (skipRoot.has(key)) return
        if (value === null || value === undefined || value === '') return
        form.append(key, value)
      })

      if (Array.isArray(payload.variants)) {
        payload.variants.forEach((variant, index) => {
          appendVariantRow(form, variant, index)
        })
      }

      if (payload.photo instanceof File) {
        form.append('photo', payload.photo)
      }

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
