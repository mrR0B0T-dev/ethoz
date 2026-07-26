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
          <!-- submenu model perhitungan (referensi workbook) -->
          <div class="mb-1">
            <p class="px-4 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Model Perhitungan</p>
            <button
              v-for="m in models" :key="m.key"
              class="flex w-full items-center gap-2 px-4 py-1.5 text-left text-sm transition-colors"
              :class="modelKey === m.key ? 'bg-blue-50 font-medium text-[#1c5cab]' : 'text-gray-700 hover:bg-gray-50'"
              @click="pickModel(m)"
            >
              <CalculatorIcon class="h-3.5 w-3.5 shrink-0 text-violet-500" />
              <span class="min-w-0 flex-1 truncate">{{ m.name }}</span>
            </button>
          </div>

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

      <!-- panel model perhitungan (read-only) -->
      <div v-if="modelData" class="hc-card overflow-hidden">
        <div class="border-b border-gray-100 px-4 py-3">
          <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900">
            <CalculatorIcon class="h-4 w-4 text-violet-500" /> {{ activeModel?.name }} — {{ tahun.year }}
          </h2>
          <p class="mt-0.5 text-xs leading-relaxed text-gray-500">
            <template v-if="modelKey === 'purnabakti'">
              Model pesangon + UPMK: estimasi gaji terakhir = Jumlah Gaji × (1 + {{ modelData.meta.growth }}% per tahun)^sisa masa kerja,
              usia pensiun {{ modelData.meta.usia_pensiun }} th, pesangon {{ modelData.meta.pesangon_bulan }}×, UPMK sesuai masa kerja;
              Biaya /thn = Grand Total ÷ sisa masa kerja. Grand total per unit menjadi nilai <b>Biaya Purnabakti</b> (terkunci).
              Tanggal lahir pegawai diatur di menu <Link :href="route('hc.pegawai', { tahun: tahun.year })" class="font-medium text-[#1c5cab] hover:underline">Pegawai &amp; Biaya</Link>.
            </template>
            <template v-else-if="modelKey === 'cuti'">
              Nominal = Jumlah Gaji × hak cuti (<b>3 THN</b> = ×2, <b>THN</b> = ×1), dibukukan pada bulan cuti masing-masing pegawai.
              Grand total per unit menjadi nilai <b>Biaya Tunjangan Cuti</b> (terkunci).
              Kriteria Bulan &amp; Hak Cuti diatur per pegawai di menu <Link :href="route('hc.pegawai', { tahun: tahun.year })" class="font-medium text-[#1c5cab] hover:underline">Pegawai &amp; Biaya</Link>.
            </template>
            <template v-else>
              PPh 21 = Tarif Efektif Rata-rata (TER, PP 58/2023) × penghasilan bruto bulanan
              (gaji/Jumlah Gaji + premi JKK+JKM+BPJS Kes + THR/12 + Bonus/12 + Kompensasi/12 + tunj. cuti pada bulannya);
              kategori TER mengikuti status PTKP pegawai. Grand total per unit menjadi nilai <b>Biaya Tunjangan Pajak PPh 21</b> (terkunci).
            </template>
          </p>
        </div>

        <!-- filter status utk PPh -->
        <div v-if="modelKey === 'pph'" class="flex flex-wrap gap-1 border-b border-gray-100 px-4 py-2">
          <button
            v-for="(label, s) in PPH_TABS" :key="s"
            class="rounded-lg px-3 py-1 text-xs font-medium transition-colors"
            :class="pphTab === s ? 'bg-[#2a78d6] text-white' : 'text-gray-600 hover:bg-gray-100'"
            @click="pphTab = s"
          >{{ label }}</button>
        </div>

        <div class="max-h-[26rem] overflow-auto">
          <!-- Purnabakti -->
          <table v-if="modelKey === 'purnabakti'" class="min-w-max divide-y divide-gray-100 text-sm">
            <thead class="sticky top-0 z-10 bg-gray-50">
              <tr>
                <th class="hc-th">Nama</th><th class="hc-th">Unit</th><th class="hc-th">Tgl Lahir</th>
                <th class="hc-th">TMT / Join</th>
                <th class="hc-th text-right">Jumlah Gaji /bln</th><th class="hc-th text-right">Thn Pensiun</th>
                <th class="hc-th text-right">Sisa (thn)</th><th class="hc-th text-right">Est. Gaji Terakhir</th>
                <th class="hc-th text-right">Masa Kerja</th><th class="hc-th text-right">Pesangon</th>
                <th class="hc-th text-right">UPMK</th><th class="hc-th text-right">Grand Total</th>
                <th class="hc-th border-l border-gray-200 text-right">Biaya /thn</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="r in modelData.rows" :key="r.id" class="hover:bg-gray-50/50">
                <td class="hc-td text-gray-800">{{ r.name }}</td>
                <td class="hc-td text-xs text-gray-500">{{ r.unit ?? '–' }}</td>
                <td class="hc-td text-xs tabular-nums text-gray-500">{{ r.birth_date }}</td>
                <td class="hc-td text-xs tabular-nums text-gray-500">{{ r.join_date ?? '–' }}</td>
                <td class="hc-td text-right tabular-nums">{{ fmtNum(r.thp) }}</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ r.pensiun_year }}</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ r.sisa }}</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ fmtNum(r.est_gaji_terakhir) }}</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ r.masa_kerja }} ({{ r.upmk_bulan }} bln)</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ fmtNum(r.pesangon) }}</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ fmtNum(r.upmk) }}</td>
                <td class="hc-td text-right tabular-nums">{{ fmtNum(r.grand_total) }}</td>
                <td class="hc-td border-l border-gray-200 text-right font-medium tabular-nums">{{ fmtNum(r.biaya_tahunan) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t-2 border-gray-200 bg-gray-50/80">
              <tr>
                <td class="hc-td font-bold" colspan="9">Grand Total ({{ modelData.rows.length }} pegawai)</td>
                <td class="hc-td text-right font-semibold tabular-nums">{{ fmtNum(sumBy(modelData.rows, 'pesangon')) }}</td>
                <td class="hc-td text-right font-semibold tabular-nums">{{ fmtNum(sumBy(modelData.rows, 'upmk')) }}</td>
                <td class="hc-td text-right font-semibold tabular-nums">{{ fmtNum(sumBy(modelData.rows, 'grand_total')) }}</td>
                <td class="hc-td border-l border-gray-200 text-right font-bold tabular-nums">{{ fmtNum(sumBy(modelData.rows, 'biaya_tahunan')) }}</td>
              </tr>
            </tfoot>
          </table>

          <!-- Tunjangan Cuti -->
          <table v-else-if="modelKey === 'cuti'" class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="sticky top-0 z-10 bg-gray-50">
              <tr>
                <th class="hc-th">Nama</th><th class="hc-th">Unit</th>
                <th class="hc-th">Bulan</th><th class="hc-th">Hak Cuti</th>
                <th class="hc-th text-right">Jumlah Gaji /bln</th>
                <th class="hc-th border-l border-gray-200 text-right">Nominal</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="r in modelData.rows" :key="r.id" class="hover:bg-gray-50/50">
                <td class="hc-td text-gray-800">{{ r.name }}</td>
                <td class="hc-td text-xs text-gray-500">{{ r.unit ?? '–' }}</td>
                <td class="hc-td text-xs text-gray-600">{{ BULAN[r.bulan - 1] }}</td>
                <td class="hc-td">
                  <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold" :class="r.hak === '3thn' ? 'bg-violet-50 text-violet-700' : 'bg-blue-50 text-blue-700'">
                    {{ r.hak === '3thn' ? '3 THN (×2)' : 'THN (×1)' }}
                  </span>
                </td>
                <td class="hc-td text-right tabular-nums">{{ fmtNum(r.thp) }}</td>
                <td class="hc-td border-l border-gray-200 text-right font-medium tabular-nums">{{ fmtNum(r.nominal) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t-2 border-gray-200 bg-gray-50/80">
              <tr>
                <td class="hc-td font-bold" colspan="5">Grand Total ({{ modelData.rows.length }} pegawai)</td>
                <td class="hc-td border-l border-gray-200 text-right font-bold tabular-nums">{{ fmtNum(sumBy(modelData.rows, 'nominal')) }}</td>
              </tr>
            </tfoot>
          </table>

          <!-- PPh 21 TER -->
          <table v-else class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="sticky top-0 z-10 bg-gray-50">
              <tr>
                <th class="hc-th">Nama</th><th class="hc-th">Unit</th><th class="hc-th">Status</th>
                <th class="hc-th">PTKP</th><th class="hc-th">Kategori TER</th>
                <th class="hc-th text-right">PPh /bln (rata²)</th>
                <th class="hc-th border-l border-gray-200 text-right">PPh /thn</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="r in pphRows" :key="r.id" class="hover:bg-gray-50/50">
                <td class="hc-td text-gray-800">{{ r.name }}</td>
                <td class="hc-td text-xs text-gray-500">{{ r.unit ?? '–' }}</td>
                <td class="hc-td text-xs capitalize text-gray-600">{{ r.status }}</td>
                <td class="px-3 py-1">
                  <select
                    :value="r.ptkp" :disabled="savingPtkp === r.id"
                    class="hc-select w-24 py-1 font-mono text-xs disabled:opacity-50"
                    title="Ubah status PTKP — PPh 21 dihitung ulang otomatis"
                    @change="updatePtkp(r, $event.target.value)"
                  >
                    <option v-for="p in PTKP_OPTIONS" :key="p" :value="p">{{ p }}</option>
                  </select>
                </td>
                <td class="hc-td"><span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-gray-600">TER {{ r.kategori }}</span></td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ fmtNum(r.total / 12) }}</td>
                <td class="hc-td border-l border-gray-200 text-right font-medium tabular-nums">{{ fmtNum(r.total) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t-2 border-gray-200 bg-gray-50/80">
              <tr>
                <td class="hc-td font-bold" colspan="5">Grand Total ({{ pphRows.length }} pegawai)</td>
                <td class="hc-td text-right font-semibold tabular-nums">{{ fmtNum(sumBy(pphRows, 'total') / 12) }}</td>
                <td class="hc-td border-l border-gray-200 text-right font-bold tabular-nums">{{ fmtNum(sumBy(pphRows, 'total')) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- rekap per unit → nilai Input Nominal -->
        <div class="border-t border-gray-100 px-4 py-3">
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
            Grand Total per Unit — nilai komponen terkait di Input Nominal
          </h3>
          <div class="overflow-x-auto">
            <table class="min-w-max divide-y divide-gray-100 text-xs">
              <thead class="bg-gray-50/60">
                <tr>
                  <th class="hc-th">Unit</th>
                  <th v-for="b in BULAN" :key="b" class="hc-th text-right">{{ b }}</th>
                  <th class="hc-th border-l border-gray-200 text-right">Total /thn</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr v-for="u in modelData.units" :key="u.code">
                  <td class="hc-td font-medium text-gray-700">{{ u.code }}</td>
                  <td v-for="(v, i) in u.months" :key="i" class="hc-td text-right tabular-nums text-gray-600">{{ v ? fmtNum(v) : '–' }}</td>
                  <td class="hc-td border-l border-gray-200 text-right font-semibold tabular-nums">{{ fmtNum(u.total) }}</td>
                </tr>
              </tbody>
              <tfoot class="border-t-2 border-gray-200 bg-gray-50/80">
                <tr>
                  <td class="hc-td font-bold">Grand Total</td>
                  <td v-for="(b, i) in BULAN" :key="i" class="hc-td text-right font-semibold tabular-nums">
                    {{ fmtNum(modelData.units.reduce((s, u) => s + (u.months[i] || 0), 0)) }}
                  </td>
                  <td class="hc-td border-l border-gray-200 text-right font-bold tabular-nums">{{ fmtNum(sumBy(modelData.units, 'total')) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <!-- panel input -->
      <div v-else-if="selected" class="hc-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900">
              {{ selected.name }}
              <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-mono font-normal text-gray-500">{{ selected.code }}</span>
            </h2>
            <p class="mt-0.5 text-xs text-gray-500">
              <template v-if="selected.employee_source">
                <UsersIcon class="inline h-3.5 w-3.5 text-emerald-600" />
                Nilai <b>otomatis</b> — {{ selected.derived_note ?? `grand total ${selected.name} seluruh pegawai per unit` }}.
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
                  <HcNumberInput
                    v-if="canEdit"
                    v-model="row.months[i]"
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
  ArrowDownTrayIcon, ArrowsRightLeftIcon, ArrowUpTrayIcon, CalculatorIcon,
  CheckIcon, LockClosedIcon, PlusIcon, TrashIcon, UsersIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import HcNumberInput from '@/Components/HcRkap/HcNumberInput.vue'
import { BULAN, useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  tree: Array,
  models: Array,
  modelKey: String,
  modelData: Object,
  selected: Object,
  rows: Array,
  options: Object,
  canEdit: Boolean,
})

const { fmtNum } = useHcFormat()

// ── submenu model perhitungan ─────────────────────────────────────────────
const activeModel = computed(() => (props.models ?? []).find(m => m.key === props.modelKey))

function pickModel(m) {
  router.get(route('hc.nominal'), { tahun: props.tahun.year, model: m.key }, { preserveScroll: true })
}

const sumBy = (list, key) => (list ?? []).reduce((sum, item) => sum + (item[key] || 0), 0)

const PPH_TABS = { semua: 'Semua', tetap: 'Tetap', kontrak: 'Kontrak', honor: 'Honor / DKA' }
const pphTab = ref('semua')
const pphRows = computed(() => {
  const rows = props.modelData?.rows ?? []
  return pphTab.value === 'semua' ? rows : rows.filter(r => r.status === pphTab.value)
})

// PTKP bisa diubah langsung dari tabel PPh — PPh dihitung ulang di server
const PTKP_OPTIONS = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3']
const savingPtkp = ref(null)
function updatePtkp(row, value) {
  if (value === row.ptkp) return
  savingPtkp.value = row.id
  router.put(route('hc.nominal.ptkp', row.id), { ptkp_status: value }, {
    preserveScroll: true,
    onFinish: () => { savingPtkp.value = null },
  })
}
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
