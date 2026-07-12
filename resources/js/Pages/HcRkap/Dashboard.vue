<template>
  <HcLayout title="Dashboard RKAP HC">
    <!-- Filter -->
    <div class="hc-card mb-5 flex flex-wrap items-end gap-x-5 gap-y-3 p-4">
      <div class="flex items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Periode</span>
          <select v-model.number="form.bulan_awal" class="hc-select">
            <option v-for="(b, i) in BULAN_PANJANG" :key="i" :value="i + 1">{{ b }}</option>
          </select>
        </label>
        <span class="pb-2 text-xs text-gray-400">s.d.</span>
        <select v-model.number="form.bulan_akhir" class="hc-select">
          <option v-for="(b, i) in BULAN_PANJANG" :key="i" :value="i + 1">{{ b }}</option>
        </select>
      </div>

      <label class="block">
        <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Jenis Biaya</span>
        <select v-model="form.jenis_biaya" class="hc-select max-w-56">
          <option :value="null">Semua jenis biaya</option>
          <optgroup v-for="root in costTypeTree" :key="root.id" :label="root.name">
            <option :value="root.id">{{ root.name }} (semua)</option>
            <option v-for="child in root.children" :key="child.id" :value="child.id">— {{ child.name }}</option>
          </optgroup>
        </select>
      </label>

      <UnitCascade v-model="form.unit" :units="options.units" />

      <button v-if="isFiltered" class="hc-btn-secondary ml-auto" @click="resetFilters">
        <ArrowPathIcon class="h-4 w-4" /> Reset
      </button>
    </div>

    <!-- KPI -->
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard
        :label="`RKAP ${tahun.year} · ${periodeLabel}`"
        :value="fmtShort(data.kpi.rkap)"
        :hint="`${fmtIDR(data.kpi.rkap)}`"
      />
      <StatCard
        label="Realisasi"
        :value="fmtShort(data.kpi.realisasi)"
        :hint="data.kpi.serapan !== null && data.kpi.realisasi > 0 ? `Serapan ${fmtPct(data.kpi.serapan)}` : 'Belum ada realisasi tercatat'"
      >
        <div v-if="data.kpi.realisasi > 0" class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100">
          <div
            class="h-full rounded-full"
            :style="{ width: Math.min(data.kpi.serapan, 100) + '%', background: serapanColor }"
          />
        </div>
      </StatCard>
      <StatCard
        :label="data.kpi.prev_year ? `RKAP ${data.kpi.prev_year} (YoY)` : 'Tahun Sebelumnya'"
        :value="data.kpi.prev_rkap ? fmtShort(data.kpi.prev_rkap) : '–'"
        :hint="data.kpi.yoy !== null ? `${fmtPct(data.kpi.yoy, true)} terhadap ${data.kpi.prev_year}` : 'Tidak ada pembanding'"
        :trend="data.kpi.yoy === null ? null : data.kpi.yoy >= 0 ? 'up' : 'down'"
        :trend-good="data.kpi.yoy !== null && data.kpi.yoy <= 5"
      />
      <StatCard
        label="Rata-rata per Bulan"
        :value="fmtShort(avgPerMonth)"
        :hint="`${jumlahBulan} bulan dalam periode`"
      />
    </div>

    <!-- Grafik -->
    <div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-3">
      <div class="hc-card p-4 xl:col-span-2">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Tren Bulanan {{ tahun.year }}</h2>
        <BarLineChart
          :labels="BULAN"
          :bars="{ name: `RKAP ${tahun.year}`, values: data.monthly.map(m => m.rkap), color: '#2a78d6' }"
          :lines="trendLines"
          aria-label="Tren anggaran bulanan"
        />
      </div>
      <div class="hc-card p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Komposisi Jenis Biaya</h2>
        <DonutChart :items="donutItems" aria-label="Komposisi jenis biaya" />
      </div>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
      <!-- Per unit -->
      <div class="hc-card p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Anggaran per Unit Kerja</h2>
        <HBarChart :items="unitItems" />
        <p v-if="!unitItems.length" class="py-6 text-center text-sm text-gray-400">Tidak ada data untuk filter ini.</p>
      </div>

      <!-- Sorotan -->
      <div class="hc-card p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Sorotan untuk Manajemen</h2>
        <ul class="space-y-2.5">
          <li v-for="(s, i) in insights" :key="i" class="flex gap-2.5 text-sm text-gray-700">
            <component :is="s.icon" class="mt-0.5 h-4 w-4 shrink-0" :class="s.iconClass" />
            <span v-html="s.text" />
          </li>
        </ul>
      </div>
    </div>

    <!-- Tabel ringkasan kategori -->
    <div class="hc-card overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-100">
        <thead class="bg-gray-50/60">
          <tr>
            <th class="hc-th">Jenis Biaya</th>
            <th class="hc-th text-right">RKAP {{ tahun.year }}</th>
            <th class="hc-th text-right">Porsi</th>
            <th class="hc-th text-right">Realisasi</th>
            <th class="hc-th text-right">Serapan</th>
            <th class="hc-th text-right">RKAP {{ data.kpi.prev_year ?? 'Th. Lalu' }}</th>
            <th class="hc-th text-right">YoY</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <tr v-for="cat in data.by_category" :key="cat.id" class="hover:bg-gray-50/50">
            <td class="hc-td font-medium text-gray-900">
              <span class="mr-2 inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: colorFor(cat.code) }" />
              {{ cat.name }}
            </td>
            <td class="hc-td text-right tabular-nums">{{ fmtIDR(cat.rkap) }}</td>
            <td class="hc-td text-right tabular-nums text-gray-500">{{ fmtPct(cat.share) }}</td>
            <td class="hc-td text-right tabular-nums">{{ cat.realisasi ? fmtIDR(cat.realisasi) : '–' }}</td>
            <td class="hc-td text-right tabular-nums">{{ cat.rkap && cat.realisasi ? fmtPct(cat.realisasi / cat.rkap * 100) : '–' }}</td>
            <td class="hc-td text-right tabular-nums text-gray-500">{{ cat.prev_rkap ? fmtIDR(cat.prev_rkap) : '–' }}</td>
            <td class="hc-td text-right tabular-nums" :class="yoyClass(cat.yoy)">{{ cat.yoy !== null ? fmtPct(cat.yoy, true) : '–' }}</td>
          </tr>
        </tbody>
        <tfoot class="border-t border-gray-200 bg-gray-50/60">
          <tr>
            <td class="hc-td font-semibold text-gray-900">Total Biaya Personil</td>
            <td class="hc-td text-right font-semibold tabular-nums text-gray-900">{{ fmtIDR(data.kpi.rkap) }}</td>
            <td class="hc-td text-right tabular-nums text-gray-500">100%</td>
            <td class="hc-td text-right font-semibold tabular-nums">{{ data.kpi.realisasi ? fmtIDR(data.kpi.realisasi) : '–' }}</td>
            <td class="hc-td text-right tabular-nums">{{ data.kpi.serapan !== null && data.kpi.realisasi > 0 ? fmtPct(data.kpi.serapan) : '–' }}</td>
            <td class="hc-td text-right font-semibold tabular-nums text-gray-500">{{ data.kpi.prev_rkap ? fmtIDR(data.kpi.prev_rkap) : '–' }}</td>
            <td class="hc-td text-right font-semibold tabular-nums" :class="yoyClass(data.kpi.yoy)">{{ data.kpi.yoy !== null ? fmtPct(data.kpi.yoy, true) : '–' }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </HcLayout>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import {
  ArrowPathIcon, ChartPieIcon, ArrowTrendingUpIcon, BuildingOffice2Icon,
  BanknotesIcon, ExclamationTriangleIcon, CheckCircleIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import StatCard from '@/Components/HcRkap/StatCard.vue'
import BarLineChart from '@/Components/HcRkap/BarLineChart.vue'
import DonutChart from '@/Components/HcRkap/DonutChart.vue'
import HBarChart from '@/Components/HcRkap/HBarChart.vue'
import UnitCascade from '@/Components/HcRkap/UnitCascade.vue'
import { useHcFormat, BULAN, BULAN_PANJANG } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  filters: Object,
  options: Object,
  data: Object,
})

const { fmtIDR, fmtShort, fmtPct } = useHcFormat()

// warna kategorikal tetap per kode kategori (bukan per urutan)
const CATEGORY_COLORS = {
  GAJI: '#2a78d6', TUNJ: '#1baf7a', HONOR: '#eda100',
  IURAN: '#008300', LEMBUR: '#4a3aa7', LAIN: '#e34948',
}
const FALLBACKS = ['#e87ba4', '#eb6834']
const colorFor = (code) => CATEGORY_COLORS[code]
  ?? FALLBACKS[Math.abs([...code].reduce((a, c) => a + c.charCodeAt(0), 0)) % FALLBACKS.length]

// ── filter ────────────────────────────────────────────────────────────────
const form = reactive({
  bulan_awal: props.filters.month_start ?? 1,
  bulan_akhir: props.filters.month_end ?? 12,
  jenis_biaya: props.filters.cost_type_id ?? null,
  unit: props.filters.unit_id ?? null,
})

watch(form, () => {
  if (form.bulan_akhir < form.bulan_awal) form.bulan_akhir = form.bulan_awal
  router.get(route('hc.dashboard'), {
    tahun: props.tahun.year,
    bulan_awal: form.bulan_awal,
    bulan_akhir: form.bulan_akhir,
    jenis_biaya: form.jenis_biaya ?? undefined,
    unit: form.unit ?? undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
})

const isFiltered = computed(() =>
  form.bulan_awal !== 1 || form.bulan_akhir !== 12 || form.jenis_biaya || form.unit)

function resetFilters() {
  Object.assign(form, { bulan_awal: 1, bulan_akhir: 12, jenis_biaya: null, unit: null })
}

const costTypeTree = computed(() => props.options.costTypes
  .filter(t => !t.parent_id)
  .map(root => ({
    ...root,
    children: props.options.costTypes.filter(t => t.parent_id === root.id),
  })))

// ── turunan tampilan ──────────────────────────────────────────────────────
const jumlahBulan = computed(() => form.bulan_akhir - form.bulan_awal + 1)
const periodeLabel = computed(() => jumlahBulan.value === 12
  ? 'Setahun'
  : `${BULAN[form.bulan_awal - 1]}–${BULAN[form.bulan_akhir - 1]}`)
const avgPerMonth = computed(() => props.data.kpi.rkap / jumlahBulan.value)

const hasRealisasi = computed(() => props.data.monthly.some(m => m.realisasi > 0))
const hasPrev = computed(() => props.data.monthly.some(m => m.prev_rkap > 0))

const trendLines = computed(() => {
  const lines = []
  if (hasRealisasi.value) {
    lines.push({ name: 'Realisasi', values: props.data.monthly.map(m => m.realisasi), color: '#1baf7a' })
  }
  if (hasPrev.value) {
    lines.push({ name: `RKAP ${props.data.kpi.prev_year}`, values: props.data.monthly.map(m => m.prev_rkap), color: '#eda100', dashed: true })
  }
  return lines
})

const donutItems = computed(() => props.data.by_category
  .filter(c => c.rkap > 0)
  .map(c => ({ label: c.name, value: c.rkap, color: colorFor(c.code) })))

const unitItems = computed(() => props.data.by_unit.map(u => ({
  label: u.code, name: u.name, value: u.rkap,
  secondary: u.realisasi > 0 ? u.realisasi : null,
})))

const serapanColor = computed(() => {
  const s = props.data.kpi.serapan ?? 0
  return s > 100 ? '#d03b3b' : s > 90 ? '#fab219' : '#0ca30c'
})

const yoyClass = (yoy) => yoy === null ? '' : yoy > 10 ? 'text-red-600' : yoy < 0 ? 'text-emerald-700' : 'text-gray-700'

// ── sorotan otomatis ──────────────────────────────────────────────────────
const insights = computed(() => {
  const out = []
  const cats = props.data.by_category
  if (!cats.length) return out

  const biggest = [...cats].sort((a, b) => b.rkap - a.rkap)[0]
  out.push({
    icon: ChartPieIcon, iconClass: 'text-[#2a78d6]',
    text: `<b>${biggest.name}</b> adalah komponen terbesar (${biggest.share.toLocaleString('id-ID')}% dari total anggaran periode ini).`,
  })

  const growth = cats.filter(c => c.yoy !== null).sort((a, b) => b.yoy - a.yoy)[0]
  if (growth && growth.yoy > 0) {
    out.push({
      icon: ArrowTrendingUpIcon, iconClass: 'text-[#eda100]',
      text: `Kenaikan YoY tertinggi pada <b>${growth.name}</b>: ${fmtPct(growth.yoy, true)} dibanding ${props.data.kpi.prev_year}.`,
    })
  }

  const units = props.data.by_unit
  if (units.length) {
    out.push({
      icon: BuildingOffice2Icon, iconClass: 'text-[#4a3aa7]',
      text: `Unit dengan anggaran terbesar: <b>${units[0].code}</b> (${fmtShort(units[0].rkap)}).`,
    })
  }

  if (props.data.kpi.realisasi > 0) {
    const s = props.data.kpi.serapan
    out.push(s > 100
      ? { icon: ExclamationTriangleIcon, iconClass: 'text-[#d03b3b]', text: `Realisasi <b>melampaui</b> RKAP (${fmtPct(s)}). Perlu evaluasi pengendalian biaya.` }
      : { icon: CheckCircleIcon, iconClass: 'text-[#0ca30c]', text: `Serapan anggaran ${fmtPct(s)} — masih dalam pagu RKAP.` })
  } else {
    out.push({
      icon: BanknotesIcon, iconClass: 'text-gray-400',
      text: `Belum ada realisasi tercatat untuk ${props.tahun.year}. Input melalui modul <b>Realisasi & Monitoring</b>.`,
    })
  }

  return out
})
</script>
