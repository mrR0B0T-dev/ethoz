<template>
  <HcLayout title="Pegawai & Biaya per Status">
    <!-- Tabs status -->
    <div class="mb-5 flex flex-wrap items-center gap-1 rounded-xl border border-gray-200 bg-white p-1.5">
      <button
        class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === 'semua' ? 'bg-[#2a78d6] text-white' : 'text-gray-600 hover:bg-gray-100'"
        @click="tab = 'semua'"
      >
        Semua Pegawai
        <span class="ml-1.5 rounded-full px-1.5 text-xs" :class="tab === 'semua' ? 'bg-white/20' : 'bg-gray-100 text-gray-500'">
          {{ totalHeadcount }}
        </span>
      </button>
      <button
        v-for="(label, status) in STATUS_LABELS" :key="status"
        v-show="byStatus[status]"
        class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === status ? 'bg-[#2a78d6] text-white' : 'text-gray-600 hover:bg-gray-100'"
        @click="tab = status"
      >
        {{ label }}
        <span class="ml-1.5 rounded-full px-1.5 text-xs" :class="tab === status ? 'bg-white/20' : 'bg-gray-100 text-gray-500'">
          {{ byStatus[status]?.headcount ?? 0 }}
        </span>
      </button>
      <div class="ml-auto flex flex-wrap items-center gap-2">
        <a :href="templateUrl" class="hc-btn-secondary" title="Unduh template Excel untuk menambah pegawai">
          <ArrowDownTrayIcon class="h-4 w-4" /> Unduh Template
        </a>
        <button type="button" class="hc-btn-secondary" :disabled="importing" @click="fileInput.click()">
          <ArrowUpTrayIcon class="h-4 w-4" /> {{ importing ? 'Mengimpor…' : 'Import Excel' }}
        </button>
        <input ref="fileInput" type="file" accept=".xlsx,.xls" class="hidden" @change="importExcel" />
        <button class="hc-btn" @click="openCreate">
          <PlusIcon class="h-4 w-4" /> Tambah Pegawai
        </button>
      </div>
    </div>

    <div v-if="current">
      <!-- ringkasan -->
      <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Jumlah Pegawai" :value="String(current.headcount)" :hint="isAll ? 'Semua status' : STATUS_LABELS[tab]" />
        <StatCard label="Estimasi Biaya Setahun" :value="fmtShort(current.grand_total)" :hint="fmtIDR(current.grand_total)" />
        <StatCard
          label="Rata-rata per Pegawai"
          :value="current.headcount ? fmtShort(current.grand_total / current.headcount) : '–'"
          hint="per tahun, seluruh komponen"
        />
      </div>

      <!-- catatan asumsi -->
      <div v-if="!isAll" class="mb-5 rounded-xl border border-blue-100 bg-blue-50/50 px-4 py-3">
        <p class="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[#1c5cab]">
          <InformationCircleIcon class="h-4 w-4" /> Dasar perhitungan (Asumsi {{ tahun.year }})
        </p>
        <ul class="list-inside list-disc space-y-0.5 text-xs text-gray-600">
          <li v-for="(note, i) in current.assumption_notes" :key="i">{{ note }}</li>
        </ul>
      </div>

      <!-- tabel pegawai -->
      <div class="hc-card">
        <!-- pencarian, filter & urutan -->
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3">
          <div class="relative">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              v-model="search" type="search"
              class="hc-input w-56 py-1.5 pl-8"
              placeholder="Cari nama / catatan…"
            />
          </div>
          <select v-model="filterUnit" class="hc-select max-w-52">
            <option :value="null">Semua unit kerja</option>
            <option v-for="u in unitOptions" :key="u.id" :value="u.id">{{ u.label }}</option>
          </select>
          <button
            v-if="search || filterUnit" class="hc-btn-secondary !px-2.5 !py-1.5 text-xs"
            @click="search = ''; filterUnit = null"
          >
            <XMarkIcon class="h-3.5 w-3.5" /> Reset
          </button>
          <p class="ml-auto text-xs text-gray-400">
            {{ filteredEmployees.length }} dari {{ current.employees.length }} pegawai · klik judul kolom untuk mengurutkan
          </p>
        </div>

        <div class="overflow-x-auto">
        <table class="min-w-max divide-y divide-gray-100 text-sm">
          <thead class="bg-gray-50/60">
            <tr>
              <th class="hc-th sticky left-0 z-10 cursor-pointer select-none bg-gray-50" @click="sortBy('name')">
                Nama <SortMark col="name" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none" @click="sortBy('unit')">
                Unit <SortMark col="unit" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th v-if="isAll" class="hc-th cursor-pointer select-none" @click="sortBy('status')">
                Status <SortMark col="status" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none text-right" @click="sortBy('base_salary')">
                Gaji /bln <SortMark col="base_salary" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th v-for="key in componentKeys" :key="key" class="hc-th text-right">{{ COMPONENT_LABELS[key] ?? key }}</th>
              <th class="hc-th cursor-pointer select-none border-l border-gray-200 text-right" @click="sortBy('total')">
                Total /tahun <SortMark col="total" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="emp in filteredEmployees" :key="emp.id" class="hover:bg-gray-50/50">
              <td class="hc-td sticky left-0 z-10 bg-white font-medium text-gray-900">
                <span class="flex items-center gap-1.5">
                  {{ emp.name }}
                  <ChatBubbleBottomCenterTextIcon
                    v-if="emp.notes" class="h-3.5 w-3.5 shrink-0 text-amber-500"
                    :title="emp.notes"
                  />
                </span>
                <span v-if="emp.notes" class="mt-0.5 block max-w-52 truncate text-[11px] font-normal text-gray-400" :title="emp.notes">{{ emp.notes }}</span>
              </td>
              <td class="hc-td text-xs text-gray-500" :title="emp.unit_name">{{ emp.unit ?? '–' }}</td>
              <td v-if="isAll" class="hc-td">
                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusChip(emp.status)">
                  {{ STATUS_LABELS[emp.status] ?? emp.status }}
                </span>
              </td>
              <td class="hc-td text-right tabular-nums">{{ fmtNum(emp.base_salary) }}</td>
              <td v-for="key in componentKeys" :key="key" class="hc-td text-right tabular-nums text-gray-600">
                {{ fmtNum(emp.components[key]) }}
              </td>
              <td class="hc-td border-l border-gray-200 text-right font-medium tabular-nums">{{ fmtNum(emp.total) }}</td>
              <td class="hc-td text-right">
                <button class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600" title="Ubah" @click="openEdit(emp)">
                  <PencilSquareIcon class="h-4 w-4" />
                </button>
                <button class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Hapus" @click="confirmDelete(emp)">
                  <TrashIcon class="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t border-gray-200 bg-gray-50/60">
            <tr>
              <td class="hc-td sticky left-0 z-10 bg-gray-50 font-semibold" :colspan="isAll ? 4 : 3">
                Total {{ isAll ? 'Semua Pegawai' : STATUS_LABELS[tab] }}{{ isFiltered ? ` (${filteredEmployees.length} pegawai tersaring)` : '' }}
              </td>
              <td v-for="key in componentKeys" :key="key" class="hc-td text-right font-medium tabular-nums">{{ fmtNum(viewTotals.components[key]) }}</td>
              <td class="hc-td border-l border-gray-200 text-right font-bold tabular-nums">{{ fmtNum(viewTotals.grand) }}</td>
              <td />
            </tr>
          </tfoot>
        </table>
        <p v-if="!filteredEmployees.length" class="p-8 text-center text-sm text-gray-400">
          {{ current.employees.length ? 'Tidak ada pegawai yang cocok dengan pencarian/filter.' : 'Belum ada pegawai berstatus ini.' }}
        </p>
        </div>
      </div>
      <p class="mt-3 text-xs text-gray-400">
        Seluruh angka dalam Rupiah — estimasi setahun berdasarkan asumsi tahun {{ tahun.year }}.
        <template v-if="tab === 'honor'"> Komposisi biaya honor mengacu ke struktur workbook sheet “(2)”: gaji, BPJS, bonus, THR, kompensasi, fee &amp; PPN.</template>
      </p>
    </div>

    <!-- modal tambah/ubah -->
    <HcModal :show="modal" :title="editingEmp ? 'Ubah Pegawai' : 'Tambah Pegawai'" @close="modal = false">
      <form class="space-y-3" @submit.prevent="submit">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Nama</span>
          <input v-model="form.name" type="text" required class="hc-input w-full" />
        </label>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Status</span>
            <select v-model="form.status" class="hc-select w-full">
              <option v-for="(label, s) in STATUS_LABELS" :key="s" :value="s">{{ label }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Unit Kerja</span>
            <select v-model="form.work_unit_id" class="hc-select w-full">
              <option :value="null">–</option>
              <option v-for="u in unitOptions" :key="u.id" :value="u.id">{{ u.label }}</option>
            </select>
          </label>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Gaji Dasar /bln</span>
            <input v-model.number="form.base_salary" type="number" min="0" required class="hc-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Jabatan /bln</span>
            <input v-model.number="form.position_allowance" type="number" min="0" class="hc-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Transport /bln</span>
            <input v-model.number="form.transport_allowance" type="number" min="0" class="hc-input w-full" />
          </label>
        </div>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">TMT / Awal PKWT</span>
          <input v-model="form.join_date" type="date" class="hc-input w-full" />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Catatan / Keterangan</span>
          <textarea
            v-model="form.notes" rows="2" maxlength="1000"
            class="hc-input w-full resize-y"
            placeholder="mis. promosi Juli, penyesuaian gaji menunggu SK, dsb."
          />
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="modal = false">Batal</button>
          <button type="submit" class="hc-btn">{{ editingEmp ? 'Simpan Perubahan' : 'Tambah' }}</button>
        </div>
      </form>
    </HcModal>
  </HcLayout>
</template>

<script setup>
import { computed, h, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  ArrowDownTrayIcon, ArrowUpTrayIcon, ChatBubbleBottomCenterTextIcon,
  InformationCircleIcon, MagnifyingGlassIcon,
  PencilSquareIcon, PlusIcon, TrashIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import StatCard from '@/Components/HcRkap/StatCard.vue'
import HcModal from '@/Components/HcRkap/HcModal.vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  byStatus: Object,
  options: Object,
})

const { fmtIDR, fmtShort, fmtNum } = useHcFormat()

const STATUS_LABELS = {
  tetap: 'Pegawai Tetap',
  kontrak: 'Pegawai Kontrak',
  honor: 'Honor / Outsource',
  direksi: 'Direksi',
}

const STATUS_CHIP = {
  tetap: 'bg-emerald-50 text-emerald-700',
  kontrak: 'bg-blue-50 text-blue-700',
  honor: 'bg-amber-50 text-amber-700',
  direksi: 'bg-purple-50 text-purple-700',
}
const statusChip = (s) => STATUS_CHIP[s] ?? 'bg-gray-100 text-gray-600'

const COMPONENT_LABELS = {
  gaji: 'Gaji /thn',
  tunj_jabatan: 'Tunj. Jabatan /thn',
  tunj_transport: 'Tunj. Transport /thn',
  thr: 'THR',
  bonus: 'Bonus',
  kompensasi: 'Kompensasi',
  bpjs_kes: 'BPJS Kes',
  bpjs_tk: 'BPJS TK',
  fee: 'Mgmt Fee',
  ppn: 'PPN',
}

const tab = ref(Object.keys(props.byStatus)[0] ?? 'tetap')
const isAll = computed(() => tab.value === 'semua')
const allEmployees = computed(() => Object.values(props.byStatus).flatMap(s => s.employees ?? []))
const totalHeadcount = computed(() => allEmployees.value.length)

// "current" = data status terpilih, atau gabungan semua status untuk tab "Semua"
const current = computed(() => {
  if (isAll.value) {
    const employees = allEmployees.value
    return {
      headcount: employees.length,
      employees,
      grand_total: employees.reduce((sum, e) => sum + (e.total ?? 0), 0),
      totals: {},
      assumption_notes: [],
    }
  }
  return props.byStatus[tab.value]
})
const componentKeys = computed(() => Object.keys(current.value?.totals ?? {}))

// ── unduh template & impor Excel ──────────────────────────────────────────
const importing = ref(false)
const fileInput = ref(null)
const templateUrl = computed(() => route('hc.pegawai.template'))

function importExcel(event) {
  const file = event.target.files[0]
  if (!file) return
  importing.value = true
  router.post(route('hc.pegawai.import'), { file }, {
    forceFormData: true,
    preserveScroll: true,
    onFinish: () => {
      importing.value = false
      event.target.value = ''
    },
  })
}

// ── pencarian, filter & urutan ────────────────────────────────────────────
const search = ref('')
const filterUnit = ref(null)
const sortKey = ref('name')
const sortDir = ref('asc')
const isFiltered = computed(() => !!(search.value.trim() || filterUnit.value))

// penanda kolom yang sedang diurutkan (▲/▼)
const SortMark = (p) => p.sortKey === p.col
  ? h('span', { class: 'ml-0.5 text-[9px] text-[#2a78d6]' }, p.sortDir === 'asc' ? '▲' : '▼')
  : null
SortMark.props = ['col', 'sortKey', 'sortDir']

function sortBy(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = (key === 'name' || key === 'unit' || key === 'status') ? 'asc' : 'desc'
  }
}

const filteredEmployees = computed(() => {
  let list = current.value?.employees ?? []
  const q = search.value.trim().toLowerCase()
  if (q) {
    list = list.filter(e => e.name.toLowerCase().includes(q)
      || (e.notes ?? '').toLowerCase().includes(q))
  }
  if (filterUnit.value) list = list.filter(e => e.work_unit_id === filterUnit.value)

  const dir = sortDir.value === 'asc' ? 1 : -1
  const key = sortKey.value
  return [...list].sort((a, b) => {
    const va = key === 'unit' ? (a.unit ?? '') : (a[key] ?? 0)
    const vb = key === 'unit' ? (b.unit ?? '') : (b[key] ?? 0)
    return (typeof va === 'string' ? va.localeCompare(vb, 'id') : va - vb) * dir
  })
})

// total mengikuti baris yang tampil agar tabel konsisten saat difilter
const viewTotals = computed(() => {
  const components = {}
  componentKeys.value.forEach((key) => {
    components[key] = filteredEmployees.value.reduce((sum, e) => sum + (e.components[key] ?? 0), 0)
  })
  return {
    components,
    grand: filteredEmployees.value.reduce((sum, e) => sum + e.total, 0),
  }
})

const unitOptions = computed(() => {
  const units = props.options.units
  const out = []
  const walk = (parentId, depth) => {
    units.filter(u => u.parent_id === parentId).forEach(u => {
      out.push({ id: u.id, label: `${'  '.repeat(depth)}${u.code} — ${u.name}` })
      walk(u.id, depth + 1)
    })
  }
  walk(null, 0)
  return out
})

// ── CRUD ──────────────────────────────────────────────────────────────────
const modal = ref(false)
const editingEmp = ref(null)
const form = reactive({
  name: '', status: 'tetap', work_unit_id: null,
  base_salary: 0, position_allowance: 0, transport_allowance: 0,
  join_date: null, notes: '',
})

function openCreate() {
  editingEmp.value = null
  Object.assign(form, {
    name: '', status: isAll.value ? 'tetap' : tab.value, work_unit_id: null,
    base_salary: 0, position_allowance: 0, transport_allowance: 0,
    join_date: null, notes: '',
  })
  modal.value = true
}

function openEdit(emp) {
  editingEmp.value = emp
  Object.assign(form, {
    name: emp.name,
    status: emp.status,
    work_unit_id: emp.work_unit_id,
    base_salary: emp.base_salary,
    position_allowance: emp.position_allowance,
    transport_allowance: emp.transport_allowance,
    join_date: emp.join_date,
    notes: emp.notes ?? '',
  })
  modal.value = true
}

function submit() {
  const opts = {
    preserveScroll: true,
    onSuccess: () => { modal.value = false; tab.value = form.status },
  }
  if (editingEmp.value) {
    router.put(route('hc.pegawai.update', editingEmp.value.id), { ...form, tahun: props.tahun.year }, opts)
  } else {
    router.post(route('hc.pegawai.store'), { ...form, tahun: props.tahun.year }, opts)
  }
}

function confirmDelete(emp) {
  Swal.fire({
    title: 'Hapus pegawai?',
    text: emp.name,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d03b3b',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  }).then((res) => {
    if (res.isConfirmed) {
      router.delete(route('hc.pegawai.destroy', { employee: emp.id, tahun: props.tahun.year }), { preserveScroll: true })
    }
  })
}
</script>
