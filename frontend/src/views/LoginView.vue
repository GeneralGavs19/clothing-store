<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-10 dark:bg-slate-950">
    <section class="grid w-full max-w-5xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-soft dark:border-slate-800 dark:bg-slate-950 md:grid-cols-[1fr_0.9fr]">
      <div class="hidden bg-slate-900 p-10 text-white md:flex md:flex-col md:justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-emerald-500">
            <Boxes class="h-6 w-6" />
          </div>
          <div>
            <div class="text-lg font-semibold">Durability Store</div>
            <div class="text-sm text-slate-300">Учет товаров и продаж</div>
          </div>
        </div>
        <div>
          <h1 class="max-w-md text-3xl font-semibold leading-tight">Остатки, продажи и прибыль в одном рабочем окне.</h1>
          <div class="mt-8 grid grid-cols-3 gap-3 text-sm text-slate-300">
            <div class="rounded-lg border border-white/10 p-3">Склад</div>
            <div class="rounded-lg border border-white/10 p-3">Витрина</div>
            <div class="rounded-lg border border-white/10 p-3">Аналитика</div>
          </div>
        </div>
      </div>

      <form class="p-6 sm:p-8" @submit.prevent="submit">
        <div class="mb-8 md:hidden">
          <div class="text-xl font-semibold text-slate-950 dark:text-white">Durability Store</div>
          <div class="text-sm text-slate-500 dark:text-slate-400">Учет товаров и продаж</div>
        </div>
        <div>
          <h2 class="text-2xl font-semibold text-slate-950 dark:text-white">Вход</h2>
          <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Используйте учетную запись администратора или кассира.</p>
        </div>

        <div class="mt-8 space-y-4">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Email</span>
            <input v-model="form.email" class="input" type="email" autocomplete="email" required />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Пароль</span>
            <input v-model="form.password" class="input" type="password" autocomplete="current-password" required />
          </label>
        </div>

        <button class="btn-primary mt-6 w-full" :disabled="auth.loading">
          <LoaderCircle v-if="auth.loading" class="h-4 w-4 animate-spin" />
          Войти
        </button>
      </form>
    </section>
  </main>
</template>

<script setup>
import { computed, h, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { icons } from '../plugins/icons'
import { apiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'

// Функция для создания Vue компонента из SVG строки
function createIcon(svgString) {
  return {
    setup(_, { attrs }) {
      return () => h('span', {
        class: attrs.class,
        innerHTML: svgString
      })
    }
  }
}

// Создаем иконки
const Boxes = createIcon(icons.Activity)
const LoaderCircle = createIcon(icons.RefreshCw)

const auth = useAuthStore()
const toast = useToastStore()
const route = useRoute()
const router = useRouter()
const form = reactive({ email: '', password: '' })

async function submit() {
  try {
    await auth.login(form)
    toast.push('Добро пожаловать')
    router.push(route.query.redirect || '/dashboard')
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}
</script>
