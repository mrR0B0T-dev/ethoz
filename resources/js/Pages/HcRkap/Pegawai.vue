<template>
  <HcLayout title="Pegawai & Biaya per Status">
    <!-- Tabs status -->
    <div class="mb-5 flex flex-wrap gap-1 rounded-xl border border-gray-200 bg-white p-1.5">
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
      <button class="hc-btn ml-auto" @click="openCreate">
        <PlusIcon class="h-4 w-4" /> Tambah Pegawai
      </button>
    </div>

    <div v-if="current">
      <!-- ringkasan -->
      <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Jumlah Pegawai" :value="String(current.headcount)" :hint="STATUS_LABELS[tab]" />
        <StatCard label="Estimasi Biaya Setahun" :value="fmtShort(current.grand_total)" :hint="fmtIDR(current.grand_total)" />
        <StatCard
          label="Rata-rata per Pegawai"
          :value="current.headcount ? fmtShort(current.grand_total / current.headcount) : '–'"
          hint="per tahun, seluruh komponen"
        />
      </div>

      <!-- catatan asumsi -->
      <div class="mb-5 rounded-xl border border-blue-100 bg-blue-50/50 px-4 py-3">
        <p class="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[#1c5cab]">
          <InformationCircleIcon class="h-4 w-4" /> Dasar perhitungan (Asumsi {{ tahun.year }})
        </p>
        <ul class="list-inside list-disc space-y-0.5 text-xs text-gray-600">
          <li v-for="(note, i) in current.assumption_notes" :key="i">{{ note }}</li>
        </ul>
      </div>

      <!-- tabel pegawai -->
      <div class="hc-card overflow-x-auto">
        <table class="min-w-max divide-y divide-gray-100 text-sm">
          <thead class="bg-gray-50/60">
            <tr>
              <th class="hc-th sticky left-0 z-10 bg-gray-50">Nama</th>
              <th class="hc-th">Unit</th>
              <th class="hc-th text-right">Gaji /bln</th>
              <th v-for="key in componentKeys" :key="key" class="hc-th text-right">{{ COMPONENT_LABELS[key] ?? key }}</th>
              <th class="hc-th border-l border-gray-200 text-right">Total /tahun</th>
              <th class="hc-th text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="emp in current.employees" :key="emp.id" class="hover:bg-gray-50/50">
              <td class="hc-td sticky left-0 z-10 bg-white font-medium text-gray-900">{{ emp.name }}</td>
              <td class="hc-td text-xs text-gray-500" :title="emp.unit_name">{{ emp.unit ?? '–' }}</td>
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
              <td class="hc-td sticky left-0 z-10 bg-gray-50 font-semibold" colspan="3">Total {{ STATUS_LABELS[tab] }}</td>
              <td v-for="key in componentKeys" :key="key" class="hc-td text-right font-medium tabular-nums">{{ fmtNum(current.totals[key]) }}</td>
              <td class="hc-td border-l border-gray-200 text-right font-bold tabular-nums">{{ fmtNum(current.grand_total) }}</td>
              <td />
            </tr>
          </tfoot>
        </table>
        <p v-if="!current.employees.length" class="p-8 text-center text-sm text-gray-400">Belum ada pegawai berstatus ini.</p>
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
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="modal = false">Batal</button>
          <button type="submit" class="hc-btn">{{ editingEmp ? 'Simpan Perubahan' : 'Tambah' }}</button>
        </div>
      </form>
    </HcModal>
  </HcLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import { InformationCircleIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
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
const current = computed(() => props.byStatus[tab.value])
const componentKeys = computed(() => Object.keys(current.value?.totals ?? {}))

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
  join_date: null,
})

function openCreate() {
  editingEmp.value = null
  Object.assign(form, {
    name: '', status: tab.value, work_unit_id: null,
    base_salary: 0, position_allowance: 0, transport_allowance: 0, join_date: null,
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
      router.delete(route('hc.pegawai.destroy', emp.id), { preserveScroll: true })
    }
  })
}
</script>
