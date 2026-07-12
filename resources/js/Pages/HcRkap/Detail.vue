<template>
  <HcLayout title="RKAP Detail">
    <!-- Toolbar -->
    <div class="hc-card mb-5 flex flex-wrap items-end gap-x-5 gap-y-3 p-4">
      <label class="block">
        <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Skenario</span>
        <select :value="skenario" class="hc-select" @change="reload({ skenario: $event.target.value })">
          <option value="rkap">RKAP (anggaran)</option>
          <option value="realisasi">Realisasi</option>
          <option value="prognosa">Prognosa</option>
        </select>
      </label>

      <UnitCascade :model-value="unit" :units="options.units" @update:model-value="reload({ unit: $event })" />

      <div class="ml-auto flex items-center gap-2">
        <button class="hc-btn-secondary" @click="toggleAll">
          <ChevronUpDownIcon class="h-4 w-4" /> {{ allExpanded ? 'Tutup Semua' : 'Buka Semua' }}
        </button>
        <button class="hc-btn" @click="exportXlsx">
          <ArrowDownTrayIcon class="h-4 w-4" /> Export Excel
        </button>
      </div>
    </div>

    <p v-if="canEdit" class="mb-3 flex items-center gap-1.5 text-xs text-gray-500">
      <PencilSquareIcon class="h-4 w-4" />
      Klik sel angka pada baris unit kerja untuk mengubah nilai ({{ skenario }}) — tersimpan otomatis.
    </p>

    <!-- Matriks -->
    <div class="hc-card overflow-x-auto">
      <table class="min-w-max divide-y divide-gray-100 text-sm">
        <thead class="bg-gray-50/60">
          <tr>
            <th class="hc-th sticky left-0 z-10 min-w-64 bg-gray-50">Uraian</th>
            <th v-for="b in BULAN" :key="b" class="hc-th min-w-24 text-right">{{ b }}</th>
            <th class="hc-th min-w-28 border-l border-gray-200 text-right">Total</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <template v-for="cat in matrix.categories" :key="cat.id">
            <!-- kategori -->
            <tr class="cursor-pointer bg-blue-50/40 hover:bg-blue-50" @click="toggle(expandedCats, cat.id)">
              <td class="hc-td sticky left-0 z-10 bg-blue-50 font-semibold text-gray-900">
                <span class="flex items-center gap-1.5">
                  <ChevronRightIcon class="h-3.5 w-3.5 transition-transform" :class="expandedCats.has(cat.id) ? 'rotate-90' : ''" />
                  {{ cat.name }}
                </span>
              </td>
              <td v-for="(v, i) in cat.months" :key="i" class="hc-td text-right font-medium tabular-nums">{{ fmtCell(v) }}</td>
              <td class="hc-td border-l border-gray-200 text-right font-semibold tabular-nums">{{ fmtCell(cat.total) }}</td>
            </tr>

            <template v-if="expandedCats.has(cat.id)">
              <template v-for="comp in cat.components" :key="comp.id">
                <!-- komponen -->
                <tr class="cursor-pointer hover:bg-gray-50" @click="toggle(expandedComps, cat.id + '-' + comp.id)">
                  <td class="hc-td sticky left-0 z-10 bg-white pl-8 text-gray-800">
                    <span class="flex items-center gap-1.5">
                      <ChevronRightIcon class="h-3 w-3 text-gray-400 transition-transform" :class="expandedComps.has(cat.id + '-' + comp.id) ? 'rotate-90' : ''" />
                      {{ comp.name }}
                      <span v-if="comp.employee_status" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-gray-500">{{ comp.employee_status }}</span>
                    </span>
                  </td>
                  <td v-for="(v, i) in comp.months" :key="i" class="hc-td text-right tabular-nums text-gray-600">{{ fmtCell(v) }}</td>
                  <td class="hc-td border-l border-gray-200 text-right font-medium tabular-nums">{{ fmtCell(comp.total) }}</td>
                </tr>

                <!-- baris unit -->
                <template v-if="expandedComps.has(cat.id + '-' + comp.id)">
                  <tr v-for="u in comp.units" :key="u.unit_id" class="bg-gray-50/40">
                    <td class="hc-td sticky left-0 z-10 bg-gray-50 pl-14 text-xs text-gray-500" :title="u.name">{{ u.code }}</td>
                    <td v-for="(v, i) in u.months" :key="i" class="px-1 py-0.5 text-right">
                      <input
                        v-if="editing === editKey(comp.id, u.unit_id, i)"
                        ref="editInput"
                        type="number" min="0"
                        class="w-24 rounded border-blue-400 px-1 py-0.5 text-right text-xs tabular-nums focus:ring-blue-500"
                        :value="Math.round(v)"
                        @blur="saveCell($event, comp.id, u.unit_id, i)"
                        @keyup.enter="$event.target.blur()"
                        @keyup.esc="editing = null"
                      />
                      <button
                        v-else
                        class="w-full rounded px-2 py-1 text-right text-xs tabular-nums text-gray-600"
                        :class="canEdit ? 'hover:bg-blue-50 hover:text-blue-700' : 'cursor-default'"
                        @click="canEdit && startEdit(comp.id, u.unit_id, i)"
                      >{{ fmtCell(v) }}</button>
                    </td>
                    <td class="hc-td border-l border-gray-200 text-right text-xs tabular-nums text-gray-500">{{ fmtCell(u.total) }}</td>
                  </tr>
                </template>
              </template>
            </template>
          </template>
        </tbody>
        <tfoot class="border-t-2 border-gray-300 bg-gray-50/80">
          <tr>
            <td class="hc-td sticky left-0 z-10 bg-gray-50 font-bold text-gray-900">TOTAL BIAYA PERSONIL</td>
            <td v-for="(v, i) in matrix.grand.months" :key="i" class="hc-td text-right font-semibold tabular-nums">{{ fmtCell(v) }}</td>
            <td class="hc-td border-l border-gray-200 text-right font-bold tabular-nums">{{ fmtCell(matrix.grand.total) }}</td>
          </tr>
        </tfoot>
      </table>
      <p v-if="!matrix.categories.length" class="p-8 text-center text-sm text-gray-400">
        Belum ada data {{ skenario }} untuk tahun {{ tahun.year }}.
      </p>
    </div>

    <p class="mt-3 text-xs text-gray-400">Seluruh angka dalam Rupiah. Data skenario: <b class="uppercase">{{ skenario }}</b> — tahun {{ tahun.year }}.</p>
  </HcLayout>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import * as XLSX from 'xlsx'
import {
  ArrowDownTrayIcon, ChevronRightIcon, ChevronUpDownIcon, PencilSquareIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import UnitCascade from '@/Components/HcRkap/UnitCascade.vue'
import { BULAN, useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Object,
  years: Array,
  skenario: String,
  unit: [Number, String],
  options: Object,
  matrix: Object,
  canEdit: Boolean,
})

const { fmtNum } = useHcFormat()
const fmtCell = (v) => (!v || Math.abs(v) < 0.005) ? '–' : fmtNum(v)

function reload(params) {
  router.get(route('hc.detail'), {
    tahun: props.tahun.year,
    skenario: props.skenario,
    unit: props.unit ?? undefined,
    ...Object.fromEntries(Object.entries(params).map(([k, v]) => [k, v ?? undefined])),
  }, { preserveState: true, preserveScroll: true, replace: true })
}

// ── expand / collapse ─────────────────────────────────────────────────────
const expandedCats = ref(new Set(props.matrix.categories.map(c => c.id)))
const expandedComps = ref(new Set())

function toggle(set, key) {
  set.has(key) ? set.delete(key) : set.add(key)
}

const allExpanded = computed(() =>
  props.matrix.categories.every(c => expandedCats.value.has(c.id))
  && props.matrix.categories.flatMap(c => c.components.map(k => c.id + '-' + k.id))
    .every(k => expandedComps.value.has(k)))

function toggleAll() {
  if (allExpanded.value) {
    expandedComps.value.clear()
  } else {
    props.matrix.categories.forEach(c => {
      expandedCats.value.add(c.id)
      c.components.forEach(k => expandedComps.value.add(c.id + '-' + k.id))
    })
  }
}

// ── edit inline ───────────────────────────────────────────────────────────
const editing = ref(null)
const editInput = ref(null)
const editKey = (compId, unitId, monthIdx) => `${compId}:${unitId}:${monthIdx}`

async function startEdit(compId, unitId, monthIdx) {
  editing.value = editKey(compId, unitId, monthIdx)
  await nextTick()
  const el = Array.isArray(editInput.value) ? editInput.value[0] : editInput.value
  el?.focus()
  el?.select()
}

function saveCell(event, costTypeId, unitId, monthIdx) {
  const key = editKey(costTypeId, unitId, monthIdx)
  if (editing.value !== key) return
  editing.value = null

  const amount = parseFloat(event.target.value)
  if (Number.isNaN(amount) || amount < 0) return

  router.put(route('hc.detail.upsert'), {
    fiscal_year_id: props.tahun.id,
    cost_type_id: costTypeId,
    work_unit_id: unitId,
    month: monthIdx + 1,
    scenario: props.skenario,
    amount,
  }, { preserveState: false, preserveScroll: true })
}

// ── export ────────────────────────────────────────────────────────────────
function exportXlsx() {
  const header = ['Uraian', 'Unit', ...BULAN, 'Total']
  const rows = []
  props.matrix.categories.forEach(cat => {
    rows.push([cat.name, '', ...cat.months, cat.total])
    cat.components.forEach(comp => {
      rows.push(['  ' + comp.name, '', ...comp.months, comp.total])
      comp.units.forEach(u => rows.push(['    ' + comp.name, u.code, ...u.months, u.total]))
    })
  })
  rows.push(['TOTAL BIAYA PERSONIL', '', ...props.matrix.grand.months, props.matrix.grand.total])

  const ws = XLSX.utils.aoa_to_sheet([
    [`RKAP HC ${props.tahun.year} — Detail (${props.skenario.toUpperCase()})`],
    [],
    header,
    ...rows,
  ])
  ws['!cols'] = [{ wch: 42 }, { wch: 12 }, ...Array(13).fill({ wch: 14 })]
  const wb = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(wb, ws, `RKAP ${props.tahun.year}`)
  XLSX.writeFile(wb, `RKAP-HC-${props.tahun.year}-${props.skenario}.xlsx`)
}
</script>
