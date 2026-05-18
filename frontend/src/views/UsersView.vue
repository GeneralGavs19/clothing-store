<template>
  <div class="space-y-5">
    <section class="panel p-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <input v-model="search" class="input sm:max-w-sm" placeholder="Поиск пользователя" @input="debouncedFetch" />
        <button class="btn-primary w-full sm:w-auto" @click="openCreate"><UserPlus class="h-4 w-4" />Пользователь</button>
      </div>
    </section>

    <section class="panel overflow-hidden">
      <div v-if="users.loading" class="space-y-3 p-4">
        <SkeletonBlock v-for="i in 5" :key="i" custom-class="h-16" />
      </div>
      <EmptyState v-else-if="!users.users.length" class="m-4" title="Пользователи не найдены" />
      <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
        <div v-for="user in users.users" :key="user.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="font-medium">{{ user.name }}</h3>
              <span class="badge" :class="roleClass(user.role)">{{ roleLabel(user.role) }}</span>
              <span v-if="!user.is_active" class="badge bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300">Отключен</span>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ user.email }}</p>
            <p v-if="user.password_plain" class="mt-2 text-sm">
              <span class="text-slate-500">Пароль:</span>
              <span class="ml-1 font-mono font-medium text-slate-800 dark:text-slate-200">{{ user.password_plain }}</span>
            </p>
            <p v-else class="mt-2 text-xs text-slate-400">Пароль не сохранён (создан до обновления системы)</p>
          </div>
          <div class="flex gap-2">
            <button class="btn-muted h-9 px-3" @click="openEdit(user)"><Pencil class="h-4 w-4" /></button>
            <button class="btn-danger h-9 px-3" @click="disable(user)"><Ban class="h-4 w-4" /></button>
          </div>
        </div>
      </div>
      <PaginationBar :meta="users.meta" @page="fetchUsers" />
    </section>

    <ModalPanel :open="modalOpen" :title="editing?.id ? 'Редактировать пользователя' : 'Новый пользователь'" @close="modalOpen = false">
      <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="save">
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Имя</span>
          <input v-model="form.name" class="input" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Email</span>
          <input v-model="form.email" class="input" type="email" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Роль</span>
          <select v-model="form.role" class="select">
            <option value="cashier">Кассир</option>
            <option value="admin">Администратор</option>
            <option value="admin_programmer">Админ программист</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Пароль</span>
          <input v-model="form.password" class="input" type="text" :required="!editing" minlength="8" />
        </label>
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          Активен
        </label>
        <div class="flex flex-col-reverse gap-2 sm:col-span-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-muted w-full sm:w-auto" @click="modalOpen = false">Отмена</button>
          <button class="btn-primary w-full sm:w-auto" :disabled="saving">
            <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />Сохранить
          </button>
        </div>
      </form>
    </ModalPanel>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { Ban, LoaderCircle, Pencil, UserPlus } from 'lucide-vue-next'
import { apiError } from '../api/client'
import { roleLabel } from '../utils/permissions'
import { useToastStore } from '../stores/toasts'
import { useUsersStore } from '../stores/users'
import EmptyState from '../components/ui/EmptyState.vue'
import ModalPanel from '../components/ui/ModalPanel.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const users = useUsersStore()
const toast = useToastStore()
const search = ref('')
const modalOpen = ref(false)
const editing = ref(null)
const saving = ref(false)
const form = reactive({ name: '', email: '', role: 'cashier', password: '', is_active: true })
let debounce

function roleClass(role) {
  return {
    admin_programmer: 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    admin: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    cashier: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
  }[role]
}

function reset(user = null) {
  Object.assign(form, { name: user?.name || '', email: user?.email || '', role: user?.role || 'cashier', password: '', is_active: user?.is_active ?? true })
}

function openCreate() {
  editing.value = null
  reset()
  modalOpen.value = true
}

function openEdit(user) {
  editing.value = user
  reset(user)
  modalOpen.value = true
}

async function save() {
  saving.value = true
  try {
    const payload = { ...form }
    if (editing.value && !payload.password) delete payload.password
    await users.save(payload, editing.value?.id)
    toast.push('Пользователь сохранен')
    modalOpen.value = false
    await fetchUsers(users.meta.current_page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    saving.value = false
  }
}

async function disable(user) {
  if (!confirm(`Отключить пользователя "${user.name}"?`)) return
  try {
    await users.disable(user.id)
    toast.push('Пользователь отключен')
    await fetchUsers(users.meta.current_page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

async function fetchUsers(page = 1) {
  await users.fetch({ page, search: search.value || undefined })
}

function debouncedFetch() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => fetchUsers(1), 300)
}

onMounted(fetchUsers)
</script>
