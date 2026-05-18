<template>
  <div class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 border-r border-slate-200 bg-white px-4 py-5 dark:border-slate-800 dark:bg-slate-950 lg:block">
      <div class="flex items-center gap-3 px-2">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600 text-white">
          <Boxes class="h-5 w-5" />
        </div>
        <div>
          <div class="font-semibold leading-tight">Durability Store</div>
          <div class="text-xs text-slate-500 dark:text-slate-400">{{ roleLabelText }}</div>
        </div>
      </div>
      <nav class="mt-8 space-y-1">
        <RouterLink
          v-for="item in visibleNav"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white"
          active-class="bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
        >
          <component :is="item.icon" class="h-4 w-4" />
          {{ item.label }}
        </RouterLink>
      </nav>
    </aside>

    <div class="w-full min-w-0 lg:pl-64">
      <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-950/90">
        <div class="flex items-center justify-between gap-3">
          <button class="btn-icon h-10 w-10 lg:hidden" title="Меню" @click="mobileOpen = true">
            <Menu class="h-4 w-4" />
          </button>
          <div class="min-w-0">
            <h1 class="truncate text-base font-semibold sm:text-lg">{{ routeTitle }}</h1>
            <p class="hidden text-xs text-slate-500 dark:text-slate-400 sm:block">{{ auth.user?.email }}</p>
          </div>
          <div class="flex items-center gap-2">
            <button class="btn-icon h-10 w-10" :title="dark ? 'Светлая тема' : 'Тёмная тема'" @click="toggleTheme">
              <Sun v-if="dark" class="h-4 w-4" />
              <Moon v-else class="h-4 w-4" />
            </button>
            <button class="btn-icon h-10 w-10" title="Выйти" @click="logout">
              <LogOut class="h-4 w-4" />
            </button>
          </div>
        </div>
      </header>

      <main class="w-full max-w-full overflow-x-hidden px-4 py-5 sm:px-6 lg:px-8">
        <RouterView />
      </main>
    </div>

    <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-slate-950/40 lg:hidden" @click="mobileOpen = false">
      <div class="h-full w-72 bg-white p-4 dark:bg-slate-950" @click.stop>
        <div class="mb-6 flex items-center justify-between">
          <div class="font-semibold">Durability Store</div>
          <button class="btn-icon" title="Закрыть" @click="mobileOpen = false"><X class="h-4 w-4" /></button>
        </div>
        <nav class="space-y-1">
          <RouterLink
            v-for="item in visibleNav"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-200"
            active-class="bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            @click="mobileOpen = false"
          >
            <component :is="item.icon" class="h-4 w-4" />
            {{ item.label }}
          </RouterLink>
        </nav>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { roleLabel } from '../utils/permissions'
import { createIcon, icons } from '../plugins/icons'

const ChartNoAxesCombined = createIcon(icons.LayoutDashboard)
const PackageSearch = createIcon(icons.Package)
const ClipboardList = createIcon(icons.FileText)
const Folders = createIcon(icons.Folder)
const Users = createIcon(icons.Users)
const ScrollText = createIcon(icons.FileText)
const Boxes = createIcon(icons.Activity)
const LogOut = createIcon(icons.LogOut)
const Menu = createIcon(icons.Menu)
const Sun = createIcon(icons.Sun)
const Moon = createIcon(icons.Moon)
const X = createIcon(icons.X)

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const mobileOpen = ref(false)
const dark = ref(localStorage.getItem('theme') === 'dark')

const nav = [
  { to: '/dashboard', label: 'Обзор', icon: ChartNoAxesCombined, show: () => auth.canAccessDashboard },
  { to: '/products', label: 'Товары', icon: PackageSearch, show: () => true },
  { to: '/sales', label: 'Продажи', icon: ClipboardList, show: () => true },
  { to: '/categories', label: 'Категории', icon: Folders, show: () => auth.canManageCatalog },
  { to: '/reports', label: 'Отчеты', icon: ScrollText, show: () => auth.canAccessReports },
  { to: '/users', label: 'Пользователи', icon: Users, show: () => auth.canManageUsers },
  { to: '/logs', label: 'Журнал', icon: Boxes, show: () => auth.canViewLogs },
]

const visibleNav = computed(() => nav.filter((item) => item.show()))
const roleLabelText = computed(() => roleLabel(auth.user?.role))
const routeTitle = computed(() => visibleNav.value.find((item) => route.path.startsWith(item.to))?.label || 'Система')

function applyTheme() {
  document.documentElement.classList.toggle('dark', dark.value)
}

function toggleTheme() {
  dark.value = !dark.value
  localStorage.setItem('theme', dark.value ? 'dark' : 'light')
  applyTheme()
}

async function logout() {
  await auth.logout()
  router.push('/login')
}

onMounted(() => {
  auth.fetchMe().catch(() => logout())
  applyTheme()
})
</script>
