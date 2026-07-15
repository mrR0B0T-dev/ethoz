<template>
  <HcLayout title="Input Nominal RKAP">
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[20rem_1fr]">
      <!-- daftar jenis biaya -->
      <div class="hc-card self-start">
        <div class="border-b border-gray-100 px-4 py-3">
          <h2 class="text-sm font-semibold text-gray-900">Jenis Biaya</h2>
          <p class="mt-0.5 text-xs text-gray-500">
            Pilih komponen untuk mengatur nominal RKAP {{ tahun.year }}.
            Komponen bertanda <LockClosedIcon class="inline h-3 w-3 text-gray-400" /> nilainya
            mengikuti aturan berbasis <b>Gaji Dasar / Tunj. Jabatan / Tunj. Transport</b>
            sehingga tidak diinput manual.
          </p>
        </div>
        <div class="max-h-[34rem] overflow-y-auto py-2">
          <div v-for="cat in tree" :key="cat.id" class="mb-1">
            <p class="px-4 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ cat.name }}</p>
            <button
              v-for="comp in cat.components" :key="comp.id"
              class="flex w-full items-center gap-2 px-4 py-1.5 text-left text-sm transition-colors"
              :class="selected?.id === comp.id
                ? 'bg-blue-50 font-medium text-[#1c5cab]'
                : comp.is_derived ? 'text-gray-400 hover:bg-gray-50' : 'text-gray-700 hover:bg-gray-50'"
              :title="comp.employee_source ? 'Otomatis dari total pegawai per unit' : comp.is_derived ? comp.derived_note : comp.name"
              @click="pick(comp)"
            >
              <UsersIcon v-if="comp.employee_source" class="h-3.5 w-3.5 shrink-0 text-emerald-600" />
              <LockClosedIcon v-else-if="comp.is_derived" class="h-3.5 w-3.5 shrink-0" />
              <span class="min-w-0 flex-1 truncate">{{ comp.name }}</span>
              <span
                v-for="s in statusList(comp.employee_status)" :key="s"
                class="rounded bg-gray-100 px-1 py-0.5 text-[9px] font-medium uppercase text-gray-500"
              >{{ s }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- panel input -->
      <div v-if="selected" class="hc-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900">
              {{ selected.name }}
              <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-mono font-normal text-gray-500">{{ selected.code }}</span>
            </h2>
            <p class="mt-0.5 text-xs text-gray-500">
              <template v-if="selected.employee_source">
                <UsersIcon class="inline h-3.5 w-3.5 text-emerald-600" />
                Nilai <b>otomatis</b> = grand total {{ selected.name }} seluruh pegawai per unit.
                Ubah melalui menu
                <Link :href="route('hc.pegawai', { tahun: tahun.year })" class="font-medium text-[#1c5cab] hover:underline">Pegawai &amp; Biaya</Link>.
              </template>
              <template v-else-if="selected.is_derived">
                <LockClosedIcon class="inline h-3.5 w-3.5 text-amber-500" />
                Nominal komponen ini <b>tidak diinput manual</b> — {{ selected.derived_note ?? 'mengikuti aturan berbasis Gaji Dasar / Tunj. Jabatan / Tunj. Transport' }}.
              </template>
              <template v-else>
                Nominal RKAP per unit kerja × 12 bulan. Kosongkan sel untuk menghapus nilainya.
              </template>
            </p>
          </div>
          <div v-if="canEdit" class="flex flex-wrap items-center gap-2">
            <a :href="templateUrl" class="hc-btn-secondary" title="Unduh template Excel berisi nilai komponen ini">
              <ArrowDownTrayIcon class="h-4 w-4" /> Unduh Template
            </a>
            <button type="button" class="hc-btn-secondary" :disabled="importing" @click="fileInput.click()">
              <ArrowUpTrayIcon class="h-4 w-4" /> {{ importing ? 'Mengimpor…' : 'Import Excel' }}
            </button>
            <input ref="fileInput" type="file" accept=".xlsx,.xls" class="hidden" @change="importExcel" />
            <button class="hc-btn" :disabled="saving || !rows.length" @click="save">
              <CheckIcon class="h-4 w-4" /> {{ saving ? 'Menyimpan…' : 'Simpan Nominal' }}
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-max divide-y divide-gray-100 text-sm">
            <thead class="bg-gray-50/60">
              <tr>
                <th class="hc-th sticky left-0 z-10 min-w-36 bg-gray-50">Unit</th>
                <th v-for="(b, i) in BULAN" :key="i" class="hc-th min-w-24 text-right">{{ b }}</th>
                <th class="hc-th min-w-28 border-l border-gray-200 text-right">Total</th>
                <th v-if="canEdit" class="hc-th w-20 text-center">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="row in rows" :key="row.work_unit_id" class="hover:bg-gray-50/40">
                <td class="hc-td sticky left-0 z-10 bg-white text-xs font-medium text-gray-700" :title="row.name">{{ row.code }}</td>
                <td v-for="(v, i) in row.months" :key="i" class="px-1 py-1 text-right">
                  <input
                    v-if="canEdit"
                    v-model="row.months[i]"
                    type="number" min="0" step="any"
                    class="hc-input w-24 py-1 text-right text-xs tabular-nums"
                    placeholder="–"
                  />
                  <span v-else class="px-2 text-xs tabular-nums text-gray-600">{{ fmtCell(v) }}</span>
                </td>
                <td class="hc-td border-l border-gray-200 text-right text-xs font-medium tabular-nums">{{ fmtCell(rowTotal(row)) }}</td>
                <td v-if="canEdit" class="px-2 py-1 text-center">
                  <button
                    class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600"
                    title="Salin nilai Januari ke seluruh bulan"
                    @click="fillRow(row)"
                  >
                    <ArrowsRightLeftIcon class="h-4 w-4" />
                  </button>
                  <button
                    class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                    title="Kosongkan seluruh bulan baris ini"
                    @click="clearRow(row)"
                  >
                    <TrashIcon class="h-4 w-4" />
                  </button>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="rows.length" class="border-t-2 border-gray-200 bg-gray-50/80">
              <tr>
                <td class="hc-td sticky left-0 z-10 bg-gray-50 text-xs font-bold text-gray-900">Total</td>
                <td v-for="(v, i) in monthTotals" :key="i" class="hc-td text-right text-xs font-semibold tabular-nums">{{ fmtCell(v) }}</td>
                <td class="hc-td border-l border-gray-200 text-right text-xs font-bold tabular-nums">{{ fmtCell(grandTotal) }}</td>
                <td v-if="canEdit" />
              </tr>
            </tfoot>
          </table>
          <p v-if="!rows.length" class="p-8 text-center text-sm text-gray-400">
            Belum ada nominal untuk komponen ini. Tambahkan unit kerja di bawah untuk mulai mengisi.
          </p>
        </div>

        <!-- tambah baris unit -->
        <div v-if="canEdit" class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-3">
          <PlusIcon class="h-4 w-4 text-gray-400" />
          <select v-model="newUnitId" class="hc-select max-w-72">
            <option :value="null">Tambah unit kerja…</option>
            <option v-for="u in addableUnits" :key="u.id" :value="u.id">{{ u.code }} — {{ u.name }}</option>
          </select>
          <button class="hc-btn-secondary !px-2.5 !py-1.5 text-xs" :disabled="!newUnitId" @click="addUnitRow">Tambah Baris</button>
          <p class="ml-auto text-xs text-gray-400">Perubahan tersimpan setelah tombol <b>Simpan Nominal</b> ditekan.</p>
        </div>
      </div>
    </div>
  </HcLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import {
  ArrowDownTrayIcon, ArrowsRightLeftIcon, ArrowUpTrayIcon, CheckIcon,
  LockClosedIcon, PlusIcon, TrashIcon, UsersIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import { BULAN, useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  tree: Array,
  selected: Object,
  rows: Array,
  options: Object,
  canEdit: Boolean,
})

const { fmtNum } = useHcFormat()
const fmtCell = (v) => (v === null || v === undefined || v === '' || Math.abs(v) < 0.005) ? '–' : fmtNum(v)
const statusList = (s) => s ? s.split(',').filter(Boolean) : []

const saving = ref(false)

// salinan lokal agar sel bisa diedit sebelum disimpan
const rows = reactive(props.rows.map(r => ({ ...r, months: [...r.months] })))

// selaraskan salinan lokal saat data server berubah (mis. setelah impor)
watch(() => props.rows, (next) => {
  rows.splice(0, rows.length, ...next.map(r => ({ ...r, months: [...r.months] })))
})

// ── unduh template & impor Excel ──────────────────────────────────────────
const importing = ref(false)
const fileInput = ref(null)
const templateUrl = computed(() =>
  route('hc.nominal.template', { tahun: props.tahun.year, komponen: props.selected?.id }),
)

function importExcel(event) {
  const file = event.target.files[0]
  if (!file) return
  importing.value = true
  router.post(route('hc.nominal.import'), { file }, {
    forceFormData: true,
    preserveScroll: true,
    onFinish: () => {
      importing.value = false
      event.target.value = ''
    },
  })
}

function pick(comp) {
  router.get(route('hc.nominal'), { tahun: props.tahun.year, komponen: comp.id }, { preserveScroll: true })
}

const num = (v) => {
  const n = parseFloat(v)
  return Number.isNaN(n) ? 0 : n
}
const rowTotal = (row) => row.months.reduce((sum, v) => sum + num(v), 0)
const monthTotals = computed(() => BULAN.map((_, i) => rows.reduce((sum, r) => sum + num(r.months[i]), 0)))
const grandTotal = computed(() => monthTotals.value.reduce((a, b) => a + b, 0))

function fillRow(row) {
  row.months = row.months.map(() => row.months[0])
}

function clearRow(row) {
  row.months = row.months.map(() => null)
}

// ── tambah unit ───────────────────────────────────────────────────────────
const newUnitId = ref(null)
const addableUnits = computed(() =>
  props.options.units.filter(u => !rows.some(r => r.work_unit_id === u.id)))

function addUnitRow() {
  const unit = props.options.units.find(u => u.id === newUnitId.value)
  if (!unit) return
  rows.push({
    work_unit_id: unit.id,
    code: unit.code,
    name: unit.name,
    months: Array(12).fill(null),
  })
  newUnitId.value = null
}

function save() {
  saving.value = true
  router.put(route('hc.nominal.upsert'), {
    fiscal_year_id: props.tahun.id,
    cost_type_id: props.selected.id,
    tahun: props.tahun.year,
    komponen: props.selected.id,
    rows: rows.map(r => ({
      work_unit_id: r.work_unit_id,
      months: r.months.map(v => (v === '' || v === null || v === undefined) ? null : num(v)),
    })),
  }, {
    preserveScroll: true,
    onFinish: () => { saving.value = false },
  })
}
</script>
