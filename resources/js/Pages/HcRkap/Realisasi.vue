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
      </table>
    </div>

    <!-- input realisasi -->
    <form class="hc-card overflow-hidden" @submit.prevent="save">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
        <div>
          <h2 class="text-sm font-semibold text-gray-900">Input Realisasi — {{ BULAN_PANJANG[bulan - 1] }} {{ tahun.year }}</h2>
          <p class="mt-0.5 text-xs text-gray-500">Isi nilai realisasi per komponen biaya dan unit kerja. Kosongkan bila belum ada.</p>
        </div>
        <button v-if="canEdit" type="submit" class="hc-btn" :disabled="saving">
          <CheckIcon class="h-4 w-4" /> {{ saving ? 'Menyimpan…' : 'Simpan Realisasi' }}
        </button>
      </div>

      <div class="max-h-[32rem] overflow-y-auto">
        <table class="min-w-full divide-y divide-gray-100">
          <thead class="sticky top-0 z-10 bg-gray-50">
            <tr>
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
                <td colspan="5" class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-[#1c5cab]">{{ category }}</td>
              </tr>
              <tr v-for="row in group" :key="rowKey(row)" class="hover:bg-gray-50/50">
                <td class="hc-td text-gray-700">{{ row.component }}</td>
                <td class="hc-td text-xs text-gray-500">{{ row.unit }}</td>
                <td class="hc-td text-right tabular-nums text-gray-600">{{ fmtNum(row.rkap) }}</td>
                <td class="px-3 py-1 text-right">
                  <input
                    v-model="inputs[rowKey(row)]"
                    type="number" min="0" step="any" :disabled="!canEdit"
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
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { CheckIcon, CheckCircleIcon, ExclamationTriangleIcon, FireIcon } from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
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

const rowKey = (r) => `${r.cost_type_id}-${r.work_unit_id}`

const inputs = reactive(Object.fromEntries(
  props.rows.map(r => [rowKey(r), r.realisasi ?? ''])
))

const groupedRows = computed(() => {
  const out = {}
  props.rows.forEach(r => { (out[r.category] ??= []).push(r) })
  return out
})

function pickMonth(m) {
  router.get(route('hc.realisasi'), { tahun: props.tahun.year, bulan: m }, { preserveScroll: true })
}

const pctOf = (row) => {
  const v = parseFloat(inputs[rowKey(row)])
  if (!v || !row.rkap) return '–'
  return fmtPct(v / row.rkap * 100)
}
const pctClass = (row) => {
  const v = parseFloat(inputs[rowKey(row)])
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
  const items = props.rows
    .map(r => ({ ...r, val: inputs[rowKey(r)] }))
    .filter(r => r.val !== '' && r.val !== null && !Number.isNaN(parseFloat(r.val)))
    .map(r => ({
      cost_type_id: r.cost_type_id,
      work_unit_id: r.work_unit_id,
      amount: parseFloat(r.val),
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
