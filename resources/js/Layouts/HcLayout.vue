<template>
  <ModuleLayout :title="title" :brand="brand" accent="#2a78d6" :menus="menus">
    <!-- Pemilih tahun anggaran (global) — kontrol khusus modul RKAP HC -->
    <template #header>
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
    </template>

    <slot />
  </ModuleLayout>
</template>

<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import {
  HomeIcon, TableCellsIcon, ClipboardDocumentCheckIcon, UsersIcon,
  AdjustmentsHorizontalIcon, CircleStackIcon, CalendarIcon,
  BanknotesIcon, ChartBarSquareIcon,
} from '@heroicons/vue/24/outline'
import ModuleLayout from '@/Layouts/ModuleLayout.vue'

defineProps({ title: { type: String, default: 'Dashboard' } })

const page = usePage()
const tahun = computed(() => page.props.tahun)
const years = computed(() => page.props.years ?? [])

const brand = {
  badge: 'RK',
  name: 'RKAP HC',
  subtitle: 'Human Capital & Corporate Secretary',
  note: 'Sistem Informasi Monitoring\nRencana Kerja & Anggaran Perusahaan',
}

// tahun anggaran aktif diteruskan ke setiap tautan agar konteks tahun tetap
const menus = computed(() => [
  { label: 'Dashboard', icon: HomeIcon, route: 'hc.dashboard', params: { tahun: tahun.value?.year }, exact: true },
  { label: 'RKAP Detail', icon: TableCellsIcon, route: 'hc.detail', params: { tahun: tahun.value?.year } },
  { label: 'Input Nominal', icon: BanknotesIcon, route: 'hc.nominal', params: { tahun: tahun.value?.year } },
  { label: 'Realisasi & Monitoring', icon: ClipboardDocumentCheckIcon, route: 'hc.realisasi', params: { tahun: tahun.value?.year } },
  { label: 'Pegawai & Biaya', icon: UsersIcon, route: 'hc.pegawai', params: { tahun: tahun.value?.year } },
  { label: 'Grading & Struktur Upah', icon: ChartBarSquareIcon, route: 'hc.grading', params: { tahun: tahun.value?.year } },
  { label: 'Asumsi', icon: AdjustmentsHorizontalIcon, route: 'hc.asumsi', params: { tahun: tahun.value?.year } },
  { label: 'Master Data', icon: CircleStackIcon, route: 'hc.master', params: { tahun: tahun.value?.year } },
])

function changeYear(year) {
  const query = Object.fromEntries(new URLSearchParams(window.location.search))
  router.get(page.url.split('?')[0], { ...query, tahun: year }, { preserveScroll: true })
}
</script>
