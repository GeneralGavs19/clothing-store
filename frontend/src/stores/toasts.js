import { defineStore } from 'pinia'

export const useToastStore = defineStore('toasts', {
  state: () => ({ items: [] }),
  actions: {
    push(message, type = 'success') {
      const id = crypto.randomUUID()
      this.items.push({ id, message, type })
      window.setTimeout(() => this.remove(id), 4200)
    },
    remove(id) {
      this.items = this.items.filter((item) => item.id !== id)
    },
  },
})
