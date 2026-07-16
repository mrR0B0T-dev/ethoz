<template>
  <HcLayout title="Realisasi & Monitoring">
    <!-- pilih bulan -->
    <div class="hc-card mb-5 flex flex-wrap items-center gap-1 p-2">
      <button
        v-for="(b, i) in BULAN" :key="i"
        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
        :class="bulan === i + 1 ? 'bg-[#2a78d6] text-white' : 'text-gray-600 hover:bg-gray-100'"
        @click="pickMonth(i + 1)"
      >{{ b }}</button>
    </div>

    <!-- ringkasan YTD -->
    <div class="hc-card mb-5 overflow-x-auto">
      <div class="border-b border-gray-100 px-4 py-3">
        <h2 class="text-sm font-semibold text-gray-900">
          Monitoring Kumulatif Januari – {{ BULAN_PANJANG[bulan - 1] }} {{ tahun.year }}
        </h2>
      </div>
      <table class="min-w-full divide-y divide-gray-100">
        <thead class="bg-gray-50/60">
          <tr>
            <th class="hc-th">Jenis Biaya</th>
            <th class="hc-th text-right">RKAP (YTD)</th>
            <th class="hc-th text-right">Realisasi (YTD)</th>
            <th class="hc-th text-right">Sisa Anggaran</th>
            <th class="hc-th text-right">Serapan</th>
            <th class="hc-th">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <tr v-for="row in ytd" :key="row.name" class="hover:bg-gray-50/50">
            <td class="hc-td font-medium text-gray-900">{{ row.name }}</td>
            <td class="hc-td text-right tabular-nums">{{ fmtIDR(row.rkap) }}</td>
            <td class="hc-td text-right tabular-nums">{{ row.realisasi ? fmtIDR(row.realisasi) : '–' }}</td>
            <td class="hc-td text-right tabular-nums" :class="row.selisih < 0 ? 'text-red-600 font-medium' : 'text-gray-500'">{{ fmtIDR(row.selisih) }}</td>
            <td class="hc-td text-right tabular-nums">{{ row.serapan !== null && row.realisasi ? fmtPct(row.serapan) : '–' }}</td>
            <td class="hc-td">
              <span v-if="row.realisasi" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusBadge(row.serapan).class">
                <component :is="statusBadge(row.serapan).icon" class="h-3 w-3" />
                {{ statusBadge(row.serapan).label }}
              </span>
              <span v-else class="text-xs text-gray-400">belum ada data</span>
            </td>
          </tr>
        </tbody>
        <tfoot v-if="ytd.length" class="border-t-2 border-gray-200 bg-gray-50/80">
          <tr>
            <td class="hc-td font-bold text-gray-900">Total</td>
            <td class="hc-td text-right font-bold tabular-nums text-gray-900">{{ fmtIDR(ytdTotal.rkap) }}</td>
            <td class="hc-td text-right font-bold tabular-nums text-gray-900">{{ ytdTotal.realisasi ? fmtIDR(ytdTotal.realisasi) : '–' }}</td>
            <td class="hc-td text-right font-bold tabular-nums" :class="ytdTotal.selisih < 0 ? 'text-red-600' : 'text-gray-900'">{{ fmtIDR(ytdTotal.selisih) }}</td>
            <td class="hc-td text-right font-bold tabular-nums text-gray-900">{{ ytdTotal.serapan !== null && ytdTotal.realisasi ? fmtPct(ytdTotal.serapan) : '–' }}</td>
            <td class="hc-td">
              <span v-if="ytdTotal.realisasi" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusBadge(ytdTotal.serapan).class">
                <component :is="statusBadge(ytdTotal.serapan).icon" class="h-3 w-3" />
                {{ statusBadge(ytdTotal.serapan).label }}
              </span>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- input realisasi -->
    <form class="hc-card overflow-hidden" @submit.prevent="save">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
        <div>
          <h2 class="text-sm font-semibold text-gray-900">Input Realisasi — {{ BULAN_PANJANG[bulan - 1] }} {{ tahun.year }}</h2>
          <p class="mt-0.5 text-xs text-gray-500">
            Isi nilai realisasi per komponen biaya dan unit kerja.
            Nilai bisa diubah kapan saja; mengosongkan input lalu menyimpan akan menghapus angka tersebut.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a :href="templateUrl" class="hc-btn-secondary" title="Unduh template Excel berisi baris bulan ini">
            <ArrowDownTrayIcon class="h-4 w-4" /> Unduh Template
          </a>
          <button v-if="canEdit" type="button" class="hc-btn-secondary" :disabled="importing" @click="fileInput.click()">
            <ArrowUpTrayIcon class="h-4 w-4" /> {{ importing ? 'Mengimpor…' : 'Import Excel' }}
          </button>
          <input ref="fileInput" type="file" accept=".xlsx,.xls" class="hidden" @change="importExcel" />
          <button
            v-if="canEdit" type="button" class="hc-btn-danger"
            :disabled="!selectedCount" :title="selectedCount ? '' : 'Centang komponen yang ingin dihapus dulu'"
            @click="deleteSelected"
          >
            <TrashIcon class="h-4 w-4" /> Hapus Terpilih{{ selectedCount ? ` (${selectedCount})` : '' }}
          </button>
          <button v-if="canEdit" type="button" class="hc-btn-danger" @click="deleteAll">
            <TrashIcon class="h-4 w-4" /> Hapus Semua
          </button>
          <button
            v-if="canEdit" type="submit" class="hc-btn"
            :disabled="saving || !hasChanges"
            :title="hasChanges ? '' : 'Belum ada perubahan realisasi untuk disimpan'"
          >
            <CheckIcon class="h-4 w-4" /> {{ saving ? 'Menyimpan…' : 'Simpan Realisasi' }}
          </button>
        </div>
      </div>

      <div class="max-h-[32rem] overflow-y-auto">
        <table class="min-w-full divide-y divide-gray-100">
          <thead class="sticky top-0 z-10 bg-gray-50">
            <tr>
              <th v-if="canEdit" class="w-10 px-3 py-2">
                <input
                  type="checkbox" class="rounded border-gray-300 text-[#2a78d6] focus:ring-[#2a78d6]"
                  :checked="allSelected" title="Pilih semua baris"
                  @change="toggleAll"
                />
              </th>
              <th class="hc-th">Komponen Biaya</th>
              <th class="hc-th">Unit</th>
              <th class="hc-th text-right">RKAP {{ BULAN[bulan - 1] }}</th>
              <th class="hc-th w-44 text-right">Realisasi</th>
              <th class="hc-th w-24 text-right">%</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <template v-for="(group, category) in groupedRows" :key="category">
              <tr class="bg-blue-50/40">
                <td :colspan="canEdit ? 6 : 5" class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-[#1c5cab]">{{ category }}</td>
              </tr>
              <tr v-for="row in group" :key="rowKey(row)" class="hover:bg-gray-50/50" :class="selected.has(rowKey(row)) ? 'bg-red-50/40' : ''">
                <td v-if="canEdit" class="px-3 py-1 text-center">
                  <input
                    type="checkbox" class="rounded border-gray-300 text-[#2a78d6] focus:ring-[#2a78d6]"
                    :checked="selected.has(rowKey(row))"
                    @change="toggleRow(row)"
                  />
                </td>
                <td class="hc-td text-gray-700">{{ row.component }}</td>
                <td class="hc-td text-xs text-gray-500">{{ row.unit }}</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ fmtNum(row.rkap) }}</td>
                <td class="px-3 py-1 text-right">
                  <HcNumberInput
                    v-model="inputs[rowKey(row)]"
                    :disabled="!canEdit"
                    class="hc-input w-40 py-1 text-right text-xs tabular-nums disabled:bg-gray-50"
                    placeholder="0"
                  />
                </td>
                <td class="hc-td text-right text-xs tabular-nums" :class="pctClass(row)">{{ pctOf(row) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
        <p v-if="!rows.length" class="p-8 text-center text-sm text-gray-400">Tidak ada anggaran RKAP pada bulan ini.</p>
      </div>
    </form>
  </HcLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  ArrowDownTrayIcon, ArrowUpTrayIcon, CheckIcon, CheckCircleIcon,
  ExclamationTriangleIcon, FireIcon, TrashIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import HcNumberInput from '@/Components/HcRkap/HcNumberInput.vue'
import { BULAN, BULAN_PANJANG, useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  bulan: Number,
  rows: Array,
  ytd: Array,
  canEdit: Boolean,
})

const { fmtIDR, fmtNum, fmtPct } = useHcFormat()
const saving = ref(false)
const importing = ref(false)
const fileInput = ref(null)

const templateUrl = computed(() =>
  route('hc.realisasi.template', { tahun: props.tahun.year, bulan: props.bulan })
)

function importExcel(event) {
  const file = event.target.files[0]
  if (!file) return
  importing.value = true
  router.post(route('hc.realisasi.import'), { file }, {
    forceFormData: true,
    preserveScroll: true,
    onFinish: () => {
      importing.value = false
      event.target.value = ''
    },
  })
}

const rowKey = (r) => `${r.cost_type_id}-${r.work_unit_id}`

// nilai input + potret awalnya; tombol Simpan hanya aktif bila ada perubahan
const snapshot = () => Object.fromEntries(props.rows.map(r => [rowKey(r), r.realisasi ?? '']))
const inputs = ref(snapshot())
const initial = ref(snapshot())

// props segar (setelah simpan/hapus/ganti bulan) → mulai dari potret baru
watch(() => props.rows, () => {
  inputs.value = snapshot()
  initial.value = snapshot()
})

const norm = (v) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v))) ? '' : String(Number(v))
const hasChanges = computed(() =>
  props.rows.some(r => norm(inputs.value[rowKey(r)]) !== norm(initial.value[rowKey(r)])))

const ytdTotal = computed(() => {
  const rkap = props.ytd.reduce((sum, r) => sum + (r.rkap || 0), 0)
  const realisasi = props.ytd.reduce((sum, r) => sum + (r.realisasi || 0), 0)
  return {
    rkap,
    realisasi,
    selisih: rkap - realisasi,
    serapan: rkap > 0 ? realisasi / rkap * 100 : null,
  }
})

const groupedRows = computed(() => {
  const out = {}
  props.rows.forEach(r => { (out[r.category] ??= []).push(r) })
  return out
})

function pickMonth(m) {
  router.get(route('hc.realisasi'), { tahun: props.tahun.year, bulan: m }, { preserveScroll: true })
}

// ── pilih & hapus massal ──────────────────────────────────────────────────
const selected = ref(new Set())
const selectedCount = computed(() => selected.value.size)
const allSelected = computed(() =>
  props.rows.length > 0 && props.rows.every(r => selected.value.has(rowKey(r))))

function toggleRow(row) {
  const key = rowKey(row)
  selected.value.has(key) ? selected.value.delete(key) : selected.value.add(key)
}

function toggleAll() {
  if (allSelected.value) {
    selected.value.clear()
  } else {
    props.rows.forEach(r => selected.value.add(rowKey(r)))
  }
}

function deleteSelected() {
  const items = props.rows
    .filter(r => selected.value.has(rowKey(r)))
    .map(r => ({ cost_type_id: r.cost_type_id, work_unit_id: r.work_unit_id }))

  Swal.fire({
    title: `Hapus realisasi ${items.length} baris terpilih?`,
    text: `Angka realisasi ${BULAN_PANJANG[props.bulan - 1]} ${props.tahun.year} pada baris yang dicentang akan dihapus.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d03b3b',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  }).then((res) => {
    if (!res.isConfirmed) return
    router.delete(route('hc.realisasi.destroy'), {
      data: { fiscal_year_id: props.tahun.id, mode: 'selected', month: props.bulan, items },
      preserveScroll: true,
      onSuccess: () => selected.value.clear(),
    })
  })
}

function deleteAll() {
  Swal.fire({
    title: 'Hapus semua data realisasi?',
    html: `Pilih cakupan penghapusan untuk tahun <b>${props.tahun.year}</b>. Data RKAP tidak ikut terhapus.`,
    icon: 'warning',
    showCancelButton: true,
    showDenyButton: true,
    confirmButtonColor: '#d03b3b',
    denyButtonColor: '#8a3ab5',
    confirmButtonText: `Bulan ${BULAN_PANJANG[props.bulan - 1]} saja`,
    denyButtonText: `Seluruh tahun ${props.tahun.year}`,
    cancelButtonText: 'Batal',
  }).then((res) => {
    if (!res.isConfirmed && !res.isDenied) return
    router.delete(route('hc.realisasi.destroy'), {
      data: {
        fiscal_year_id: props.tahun.id,
        mode: res.isDenied ? 'year' : 'month',
        month: props.bulan,
      },
      preserveScroll: true,
      onSuccess: () => selected.value.clear(),
    })
  })
}

const pctOf = (row) => {
  const v = parseFloat(inputs.value[rowKey(row)])
  if (!v || !row.rkap) return '–'
  return fmtPct(v / row.rkap * 100)
}
const pctClass = (row) => {
  const v = parseFloat(inputs.value[rowKey(row)])
  if (!v || !row.rkap) return 'text-gray-400'
  return v / row.rkap > 1 ? 'text-red-600 font-medium' : 'text-gray-600'
}

const statusBadge = (serapan) => {
  if (serapan === null) return { label: 'n/a', class: 'bg-gray-100 text-gray-500', icon: CheckCircleIcon }
  if (serapan > 100) return { label: 'Over Budget', class: 'bg-red-50 text-red-700', icon: FireIcon }
  if (serapan > 90) return { label: 'Mendekati Pagu', class: 'bg-amber-50 text-amber-700', icon: ExclamationTriangleIcon }
  return { label: 'Terkendali', class: 'bg-emerald-50 text-emerald-700', icon: CheckCircleIcon }
}

function save() {
  saving.value = true
  // nilai terisi → simpan; input yang dikosongkan padahal sebelumnya
  // tercatat → kirim null agar entri realisasinya dihapus
  const items = props.rows
    .map(r => ({ ...r, val: inputs.value[rowKey(r)] }))
    .filter(r => (r.val !== '' && r.val !== null && !Number.isNaN(parseFloat(r.val)))
      || (r.realisasi !== null && (r.val === '' || r.val === null)))
    .map(r => ({
      cost_type_id: r.cost_type_id,
      work_unit_id: r.work_unit_id,
      amount: (r.val === '' || r.val === null) ? null : parseFloat(r.val),
    }))

  router.post(route('hc.realisasi.store'), {
    fiscal_year_id: props.tahun.id,
    month: props.bulan,
    items,
  }, {
    preserveScroll: true,
    onFinish: () => { saving.value = false },
  })
}
</script>
