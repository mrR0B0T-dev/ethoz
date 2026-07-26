<template>
  <Head>
    <title>{{ title }} | {{ brand.name }} · Ethoz</title>
  </Head>

  <div class="flex min-h-screen bg-[#f9f9f7]">
    <!-- Sidebar -->
    <aside :class="[
      'fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-gray-200 bg-white transition-transform duration-200',
      sidebarOpen ? 'translate-x-0' : '-translate-x-full',
      'lg:static lg:translate-x-0',
    ]">
      <div class="border-b border-gray-100 px-5 py-4">
        <div class="flex items-center gap-3">
          <div
            class="flex h-9 w-9 items-center justify-center rounded-lg text-sm font-bold text-white"
            :style="{ backgroundColor: accent }"
          >{{ brand.badge }}</div>
          <div>
            <p class="text-sm font-bold leading-tight text-gray-900">{{ brand.name }}</p>
            <p class="text-[11px] text-gray-500">{{ brand.subtitle }}</p>
          </div>
        </div>
        <Link
          :href="route('ethoz.home')"
          class="mt-3 flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-blue-200 hover:bg-blue-50 hover:text-[#1c5cab]"
          title="Kembali ke portal Ethoz — daftar semua modul"
        >
          <ArrowUturnLeftIcon class="h-3.5 w-3.5" /> Kembali ke Portal
        </Link>
      </div>

      <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
        <Link
          v-for="item in visibleMenus" :key="item.route"
          :href="hrefOf(item)"
          :style="isActive(item) ? { backgroundColor: `${accent}1a`, color: accent } : null"
          :class="[
            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
            isActive(item) ? '' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900',
          ]"
        >
          <component :is="item.icon" class="h-4 w-4 shrink-0" />
          {{ item.label }}
        </Link>
      </nav>

      <div class="border-t border-gray-100 px-3 py-3">
        <div v-if="user" class="mb-2 px-2">
          <p class="truncate text-xs font-semibold text-gray-700">{{ user.name }}</p>
          <p class="truncate text-[11px] text-gray-400">{{ user.email }}</p>
        </div>
        <button
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-red-50 hover:text-red-600"
          @click="logout"
        >
          <ArrowRightStartOnRectangleIcon class="h-4 w-4 shrink-0" /> Keluar
        </button>
        <p v-if="brand.note" class="mt-2 whitespace-pre-line px-2 text-[11px] leading-relaxed text-gray-400">{{ brand.note }}</p>
      </div>
    </aside>

    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/30 lg:hidden" @click="sidebarOpen = false" />

    <!-- Main -->
    <div class="flex min-w-0 flex-1 flex-col">
      <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-gray-200 bg-white/90 px-4 py-2.5 backdrop-blur sm:px-6">
        <button class="rounded-lg p-2 hover:bg-gray-100 lg:hidden" aria-label="Buka menu" @click="sidebarOpen = !sidebarOpen">
          <Bars3Icon class="h-5 w-5 text-gray-600" />
        </button>
        <h1 class="flex-1 truncate text-base font-semibold text-gray-900">{{ title }}</h1>

        <!-- kontrol khusus modul (mis. pemilih tahun, tombol aksi) -->
        <slot name="header" />
      </header>

      <main class="flex-1 p-4 sm:p-6">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  Bars3Icon, ArrowRightStartOnRectangleIcon, ArrowUturnLeftIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  // judul halaman aktif (tampil di header sticky)
  title: { type: String, default: 'Dashboard' },
  // identitas modul: { badge, name, subtitle, note? }
  brand: { type: Object, required: true },
  // warna aksen modul (badge + item menu aktif)
  accent: { type: String, default: '#2a78d6' },
  // daftar menu sidebar: [{ label, icon, route, params?, exact?, show? }]
  menus: { type: Array, default: () => [] },
})

const page = usePage()
const sidebarOpen = ref(false)
const user = computed(() => page.props.auth?.user)

// item dengan show:false disembunyikan (mis. menu khusus Super Admin)
const visibleMenus = computed(() => props.menus.filter((m) => m.show !== false))

const hrefOf = (item) => route(item.route, item.params ?? {})

const isActive = (item) => {
  const target = new URL(hrefOf(item), window.location.origin).pathname
  const current = page.url.split('?')[0]
  return item.exact ? current === target : current.startsWith(target)
}

function logout() {
  router.post(route('logout'))
}

// Toast flash message — title di-escape agar teks tak tepercaya tidak
// dieksekusi sebagai HTML (cegah XSS).
const toast = Swal.mixin({
  toast: true, position: 'top-end', showConfirmButton: false,
  timer: 2500, timerProgressBar: true,
})
const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
))
watch(() => page.props.flash, (flash) => {
  if (flash?.success) toast.fire({ icon: 'success', title: escapeHtml(flash.success) })
  if (flash?.error) toast.fire({ icon: 'error', title: escapeHtml(flash.error) })
}, { deep: true, immediate: true })
</script>
