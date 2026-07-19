<template>
  <Head>
    <title>{{ title }} | RKAP HC · Ethoz</title>
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
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#2a78d6] text-sm font-bold text-white">HC</div>
          <div>
            <p class="text-sm font-bold leading-tight text-gray-900">RKAP HC</p>
            <p class="text-[11px] text-gray-500">Human Capital & Corporate Secretary</p>
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
          v-for="item in menus" :key="item.route"
          :href="route(item.route, { tahun: tahun?.year })"
          :class="[
            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
            isActive(item.route)
              ? 'bg-blue-50 text-[#1c5cab]'
              : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900',
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
        <p class="mt-2 px-2 text-[11px] leading-relaxed text-gray-400">
          Sistem Informasi Monitoring<br />Rencana Kerja & Anggaran Perusahaan
        </p>
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

        <!-- Pemilih tahun anggaran (global) -->
        <label class="flex items-center gap-2 text-sm">
          <CalendarIcon class="h-4 w-4 text-gray-400" />
          <select
            :value="tahun?.year"
            class="rounded-lg border border-gray-300 bg-white py-1.5 pl-2 pr-8 text-sm font-medium text-gray-800 focus:border-[#2a78d6] focus:ring-[#2a78d6]"
            @change="changeYear($event.target.value)"
          >
            <option v-for="y in years" :key="y.id" :value="y.year">
              {{ y.year }}{{ y.status === 'aktif' ? ' · aktif' : y.status === 'draft' ? ' · draft' : '' }}
            </option>
          </select>
        </label>

        <span
          v-if="tahun"
          class="hidden rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide sm:inline-block"
          :class="{
            'bg-emerald-50 text-emerald-700': tahun.status === 'aktif',
            'bg-amber-50 text-amber-700': tahun.status === 'draft',
            'bg-gray-100 text-gray-500': tahun.status === 'final',
          }"
        >{{ tahun.status }}</span>
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
  HomeIcon, TableCellsIcon, ClipboardDocumentCheckIcon, UsersIcon,
  AdjustmentsHorizontalIcon, CircleStackIcon, Bars3Icon, CalendarIcon,
  BanknotesIcon, ArrowRightStartOnRectangleIcon, ChartBarSquareIcon,
  ArrowUturnLeftIcon,
} from '@heroicons/vue/24/outline'

defineProps({ title: { type: String, default: 'Dashboard' } })

const page = usePage()
const sidebarOpen = ref(false)

const tahun = computed(() => page.props.tahun)
const years = computed(() => page.props.years ?? [])
const user = computed(() => page.props.auth?.user)

const menus = [
  { label: 'Dashboard', icon: HomeIcon, route: 'hc.dashboard' },
  { label: 'RKAP Detail', icon: TableCellsIcon, route: 'hc.detail' },
  { label: 'Input Nominal', icon: BanknotesIcon, route: 'hc.nominal' },
  { label: 'Realisasi & Monitoring', icon: ClipboardDocumentCheckIcon, route: 'hc.realisasi' },
  { label: 'Pegawai & Biaya', icon: UsersIcon, route: 'hc.pegawai' },
  { label: 'Grading & Struktur Upah', icon: ChartBarSquareIcon, route: 'hc.grading' },
  { label: 'Asumsi', icon: AdjustmentsHorizontalIcon, route: 'hc.asumsi' },
  { label: 'Master Data', icon: CircleStackIcon, route: 'hc.master' },
]

function logout() {
  router.post(route('logout'))
}

const isActive = (name) => {
  const target = new URL(route(name), window.location.origin).pathname
  const current = page.url.split('?')[0]
  return name === 'hc.dashboard' ? current === target : current.startsWith(target)
}

function changeYear(year) {
  const query = Object.fromEntries(new URLSearchParams(window.location.search))
  router.get(page.url.split('?')[0], { ...query, tahun: year }, { preserveScroll: true })
}

// Toast flash message.
// SweetAlert2 merender opsi `title` sebagai HTML, sedangkan pesan flash bisa
// memuat teks dari sumber tak tepercaya (mis. isi sel Excel saat impor).
// Escape dulu agar markup ditampilkan apa adanya, bukan dieksekusi (cegah XSS).
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
