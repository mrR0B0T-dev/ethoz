<template>
  <ModuleLayout title="Log Aktivitas" :brand="brand" :accent="accent" :menus="menus">
    <div class="mb-4 flex flex-wrap items-start gap-3">
      <div>
        <p class="text-sm text-gray-500">
          Jejak audit seluruh aktivitas (tambah, ubah, hapus) pengguna di ekosistem Ethoz.
        </p>
        <p class="mt-0.5 inline-flex items-center gap-1 text-xs text-gray-400">
          <LockClosedIcon class="h-3.5 w-3.5" /> Hanya-lihat — catatan tidak dapat diubah atau dihapus oleh siapa pun.
        </p>
      </div>
      <span class="ml-auto rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600">
        {{ logs.total }} aktivitas tercatat
      </span>
    </div>

    <!-- filter -->
    <div class="hc-card mb-4 flex flex-wrap items-center gap-2 p-3">
      <div class="relative">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
        <input
          v-model="form.search" type="search" placeholder="Cari entitas, deskripsi, atau pengguna…"
          class="hc-input w-64 py-1.5 pl-8" @input="onSearchInput"
        />
      </div>
      <select v-model="form.action" class="hc-select" @change="reload">
        <option value="">Semua aksi</option>
        <option v-for="a in filterOptions.actions" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
      <select v-model="form.module" class="hc-select" @change="reload">
        <option value="">Semua modul</option>
        <option v-for="m in filterOptions.modules" :key="m.value" :value="m.value">{{ m.label }}</option>
      </select>
      <select v-model="form.user" class="hc-select" @change="reload">
        <option value="">Semua pengguna</option>
        <option v-for="u in filterOptions.users" :key="u.value" :value="u.value">{{ u.label }}</option>
      </select>
      <button v-if="hasFilters" class="hc-btn-secondary" @click="resetFilters">
        <XMarkIcon class="h-4 w-4" /> Reset
      </button>
    </div>

    <!-- tabel audit -->
    <div class="hc-card overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead class="bg-gray-50/60">
          <tr>
            <th class="hc-th">Waktu</th>
            <th class="hc-th">Pengguna</th>
            <th class="hc-th">Aksi</th>
            <th class="hc-th">Data Terdampak</th>
            <th class="hc-th">Modul</th>
            <th class="hc-th">Detail</th>
            <th class="hc-th">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <template v-for="log in logs.data" :key="log.id">
            <tr class="hover:bg-gray-50/50 align-top">
              <td class="hc-td whitespace-nowrap tabular-nums text-gray-500">{{ fmtDateTime(log.created_at) }}</td>
              <td class="hc-td font-medium text-gray-900">{{ log.user_name ?? '—' }}</td>
              <td class="hc-td">
                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="ACTION_CHIP[log.action]">
                  {{ ACTION_LABELS[log.action] ?? log.action }}
                </span>
              </td>
              <td class="hc-td">
                <span class="font-medium text-gray-800">{{ log.subject_label ?? '—' }}</span>
                <span class="mt-0.5 inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500">
                  {{ log.subject_type_label ?? log.subject_type }}
                </span>
              </td>
              <td class="hc-td text-xs text-gray-500">{{ log.module_name ?? '—' }}</td>
              <td class="hc-td max-w-xs">
                <p class="text-xs capitalize text-gray-600">{{ log.description }}</p>
                <button
                  v-if="hasDetail(log)"
                  class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium text-[#7c3aed] hover:underline"
                  @click="toggle(log.id)"
                >
                  <ChevronRightIcon class="h-3 w-3 transition-transform" :class="{ 'rotate-90': expanded.has(log.id) }" />
                  {{ expanded.has(log.id) ? 'Sembunyikan' : 'Lihat perubahan' }}
                </button>
              </td>
              <td class="hc-td text-[11px] tabular-nums text-gray-400">{{ log.ip_address ?? '—' }}</td>
            </tr>
            <tr v-if="expanded.has(log.id) && hasDetail(log)" class="bg-gray-50/40">
              <td class="px-3 pb-3" colspan="7">
                <div class="rounded-lg border border-gray-200 bg-white p-3">
                  <!-- perubahan (updated) -->
                  <table v-if="log.properties?.changes" class="w-full text-xs">
                    <thead>
                      <tr class="text-left text-gray-400">
                        <th class="py-1 pr-4 font-medium">Kolom</th>
                        <th class="py-1 pr-4 font-medium">Sebelum</th>
                        <th class="py-1 font-medium">Sesudah</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                      <tr v-for="(val, key) in log.properties.changes" :key="key">
                        <td class="py-1 pr-4 font-mono text-gray-600">{{ key }}</td>
                        <td class="py-1 pr-4 text-red-600">{{ fmtVal(val.old) }}</td>
                        <td class="py-1 text-emerald-700">{{ fmtVal(val.new) }}</td>
                      </tr>
                    </tbody>
                  </table>
                  <!-- snapshot (created) -->
                  <dl v-else-if="log.properties?.attributes" class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-3">
                    <div v-for="(val, key) in log.properties.attributes" :key="key" class="flex flex-col">
                      <dt class="font-mono text-gray-400">{{ key }}</dt>
                      <dd class="text-gray-700">{{ fmtVal(val) }}</dd>
                    </div>
                  </dl>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p v-if="!logs.data.length" class="p-10 text-center text-sm text-gray-400">
        {{ hasFilters ? 'Tidak ada aktivitas yang cocok dengan filter.' : 'Belum ada aktivitas tercatat.' }}
      </p>
    </div>

    <!-- paginasi -->
    <div v-if="logs.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-gray-500">
      <span>Menampilkan {{ logs.from ?? 0 }}–{{ logs.to ?? 0 }} dari {{ logs.total }}</span>
      <div class="flex items-center gap-2">
        <button class="hc-btn-secondary disabled:opacity-40" :disabled="!logs.prev_page_url" @click="go(logs.prev_page_url)">
          <ChevronLeftIcon class="h-4 w-4" /> Sebelumnya
        </button>
        <span class="px-1 tabular-nums">Halaman {{ logs.current_page }} / {{ logs.last_page }}</span>
        <button class="hc-btn-secondary disabled:opacity-40" :disabled="!logs.next_page_url" @click="go(logs.next_page_url)">
          Berikutnya <ChevronRightIcon class="h-4 w-4" />
        </button>
      </div>
    </div>
  </ModuleLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import {
  MagnifyingGlassIcon, LockClosedIcon, XMarkIcon,
  ChevronLeftIcon, ChevronRightIcon,
} from '@heroicons/vue/24/outline'
import ModuleLayout from '@/Layouts/ModuleLayout.vue'
import { useAdminNav } from '@/composables/useAdminNav'

const props = defineProps({
  logs: Object,        // paginator: { data, links, current_page, last_page, total, from, to, prev_page_url, next_page_url }
  filters: Object,
  filterOptions: Object,
})

const { brand, accent, menus } = useAdminNav()

const ACTION_LABELS = { created: 'Tambah', updated: 'Ubah', deleted: 'Hapus' }
const ACTION_CHIP = {
  created: 'bg-emerald-50 text-emerald-700',
  updated: 'bg-amber-50 text-amber-700',
  deleted: 'bg-red-50 text-red-700',
}

const form = reactive({
  search: props.filters.search ?? '',
  action: props.filters.action ?? '',
  module: props.filters.module ?? '',
  user: props.filters.user ?? '',
})

const hasFilters = computed(() => !!(form.search || form.action || form.module || form.user !== ''))

function reload() {
  router.get(route('ethoz.admin.activity'), { ...form }, {
    preserveState: true, preserveScroll: true, replace: true,
  })
}

// debounce pencarian teks agar tidak memanggil server tiap ketukan
let searchTimer = null
function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(reload, 350)
}

function resetFilters() {
  Object.assign(form, { search: '', action: '', module: '', user: '' })
  reload()
}

function go(url) {
  if (url) router.get(url, {}, { preserveState: true, preserveScroll: true })
}

// ── ekspansi detail perubahan ───────────────────────────────────────────────
const expanded = ref(new Set())
function toggle(id) {
  const next = new Set(expanded.value)
  next.has(id) ? next.delete(id) : next.add(id)
  expanded.value = next
}
const hasDetail = (log) => !!(
  (log.properties?.changes && Object.keys(log.properties.changes).length)
  || (log.properties?.attributes && Object.keys(log.properties.attributes).length)
)

// ── format ──────────────────────────────────────────────────────────────────
const fmtDateTime = (iso) => iso
  ? new Date(iso).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
  : '—'
const fmtVal = (v) => {
  if (v === null || v === undefined || v === '') return '∅'
  if (typeof v === 'boolean') return v ? 'ya' : 'tidak'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
</script>
