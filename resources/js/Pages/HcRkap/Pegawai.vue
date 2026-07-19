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

      <!-- grand total per komponen biaya -->
      <div class="mb-5 hc-card p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold text-gray-900">
            Grand Total Biaya {{ isAll ? 'Seluruh Pegawai' : STATUS_LABELS[tab] }} — {{ tahun.year }}
          </h2>
          <!-- filter periode: bulanan (÷12) / tahunan -->
          <div class="flex rounded-lg border border-gray-200 bg-gray-50 p-0.5">
            <button
              v-for="p in [['bulanan', 'Bulanan'], ['tahunan', 'Tahunan']]" :key="p[0]"
              class="rounded-md px-3 py-1 text-xs font-medium transition-colors"
              :class="totalPeriod === p[0] ? 'bg-white text-[#1c5cab] shadow-sm' : 'text-gray-500 hover:text-gray-700'"
              @click="totalPeriod = p[0]"
            >{{ p[1] }}</button>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-4">
          <div
            v-for="key in componentKeys" :key="'gt-' + key"
            class="rounded-lg border border-gray-100 bg-gray-50/60 px-3 py-2"
            :title="fmtIDR(periodValue(current.totals[key]))"
          >
            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ periodLabel(key) }}</p>
            <p class="mt-0.5 text-sm font-semibold tabular-nums text-gray-800">{{ fmtShort(periodValue(current.totals[key])) }}</p>
          </div>
          <div
            class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2"
            :title="fmtIDR(periodValue(current.grand_total))"
          >
            <p class="text-[10px] font-semibold uppercase tracking-wide text-[#1c5cab]">Grand Total {{ periodSuffix }}</p>
            <p class="mt-0.5 text-sm font-bold tabular-nums text-[#1c5cab]">{{ fmtShort(periodValue(current.grand_total)) }}</p>
          </div>
        </div>
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
              placeholder="Cari nama / jabatan / catatan…"
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
              <th class="hc-th cursor-pointer select-none" @click="sortBy('jabatan')">
                Jabatan <SortMark col="jabatan" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none" @click="sortBy('unit')">
                Unit <SortMark col="unit" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th v-if="isAll" class="hc-th cursor-pointer select-none" @click="sortBy('status')">
                Status <SortMark col="status" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none" @click="sortBy('grade_level')">
                Grade <SortMark col="grade_level" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none" @click="sortBy('birth_date')">
                Tgl Lahir <SortMark col="birth_date" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none" @click="sortBy('join_date')">
                TMT / Join <SortMark col="join_date" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none" @click="sortBy('ptkp_status')">
                PTKP <SortMark col="ptkp_status" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none text-right" @click="sortBy('prev_year_salary')">
                Gaji Pokok Thn Sebelumnya <SortMark col="prev_year_salary" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th class="hc-th cursor-pointer select-none text-right" @click="sortBy('base_salary')">
                Gaji Pokok /bln <SortMark col="base_salary" :sort-key="sortKey" :sort-dir="sortDir" />
              </th>
              <th
                v-for="(key, i) in monthlyKeys" :key="'m-' + key"
                class="hc-th text-right" :class="i === 0 ? 'border-l border-gray-200' : ''"
              >{{ MONTHLY_LABELS[key] ?? key }}</th>
              <th
                v-for="(key, i) in componentKeys" :key="key"
                class="hc-th text-right" :class="i === 0 ? 'border-l border-gray-200' : ''"
              >{{ COMPONENT_LABELS[key] ?? key }}</th>
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
              <td class="hc-td max-w-44 truncate text-xs text-gray-600" :title="emp.jabatan">{{ emp.jabatan ?? '–' }}</td>
              <td class="hc-td text-xs text-gray-500" :title="emp.unit_name">{{ emp.unit ?? '–' }}</td>
              <td v-if="isAll" class="hc-td">
                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusChip(emp.status)">
                  {{ STATUS_LABELS[emp.status] ?? emp.status }}
                </span>
              </td>
              <td class="hc-td">
                <span v-if="emp.grade" class="rounded bg-blue-50 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-[#1c5cab]">{{ emp.grade }}</span>
                <span v-else class="text-xs text-gray-400">–</span>
              </td>
              <td class="hc-td text-xs tabular-nums text-gray-500">{{ emp.birth_date ?? '–' }}</td>
              <td class="hc-td text-xs tabular-nums text-gray-500">{{ emp.join_date ?? '–' }}</td>
              <td class="hc-td font-mono text-xs text-gray-600">{{ emp.ptkp_status ?? '–' }}</td>
              <td class="hc-td text-right tabular-nums text-gray-500">{{ emp.prev_year_salary ? fmtNum(emp.prev_year_salary) : '–' }}</td>
              <td class="hc-td text-right tabular-nums">{{ fmtNum(emp.base_salary) }}</td>
              <td
                v-for="(key, i) in monthlyKeys" :key="'m-' + key"
                class="hc-td text-right tabular-nums"
                :class="[i === 0 ? 'border-l border-gray-200' : '', key === 'total' ? 'font-medium text-gray-800' : 'text-gray-600']"
              >
                {{ emp.monthly && key in emp.monthly ? fmtNum(emp.monthly[key]) : '–' }}
              </td>
              <td
                v-for="(key, i) in componentKeys" :key="key"
                class="hc-td text-right tabular-nums text-gray-600"
                :class="i === 0 ? 'border-l border-gray-200' : ''"
              >
                {{ key in emp.components ? fmtNum(emp.components[key]) : '–' }}
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
              <td class="hc-td sticky left-0 z-10 bg-gray-50 font-semibold" :colspan="isAll ? 10 : 9">
                Total {{ isAll ? 'Semua Pegawai' : STATUS_LABELS[tab] }}{{ isFiltered ? ` (${filteredEmployees.length} pegawai tersaring)` : '' }}
              </td>
              <td
                v-for="(key, i) in monthlyKeys" :key="'m-' + key"
                class="hc-td text-right font-medium tabular-nums"
                :class="i === 0 ? 'border-l border-gray-200' : ''"
              >{{ fmtNum(viewTotals.monthly[key]) }}</td>
              <td
                v-for="(key, i) in componentKeys" :key="key"
                class="hc-td text-right font-medium tabular-nums"
                :class="i === 0 ? 'border-l border-gray-200' : ''"
              >{{ fmtNum(viewTotals.components[key]) }}</td>
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
            <span class="mb-1 block text-xs font-medium text-gray-600">Jabatan</span>
            <select v-model="form.jabatan" class="hc-select w-full" @change="onJabatanChange">
              <option value="">– pilih jabatan –</option>
              <option v-for="j in jabatanOptions" :key="j" :value="j">{{ j }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Grade / Level</span>
            <select v-model="form.salary_grade_id" class="hc-select w-full" @change="onGradeChange">
              <option :value="null">– tanpa grade (isi manual) –</option>
              <option v-for="g in gradeOptions" :key="g.id" :value="g.id">
                {{ g.code }} · Level {{ g.level }} — {{ g.jabatan }}
              </option>
            </select>
          </label>
        </div>
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
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Gaji Pokok Thn Sebelumnya /bln</span>
            <HcNumberInput v-model="form.prev_year_salary" class="hc-input w-full text-right" placeholder="kosongkan bila isi manual" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Gaji Pokok /bln</span>
            <HcNumberInput
              v-model="form.base_salary" required :disabled="hasPrevSalary"
              class="hc-input w-full text-right disabled:bg-gray-50 disabled:text-gray-500"
              :class="salaryError ? '!border-red-400 focus:!border-red-500 focus:!ring-red-500' : ''"
            />
          </label>
        </div>
        <p v-if="hasPrevSalary" class="text-[11px] leading-relaxed text-gray-500">
          Gaji Pokok /bln otomatis = Gaji Pokok Thn Sebelumnya + kenaikan
          <b>{{ kenaikanPct }}%</b> (asumsi {{ STATUS_LABELS[form.status] }} tahun {{ tahun.year }}).
        </p>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Jabatan /bln</span>
            <HcNumberInput v-model="form.position_allowance" :disabled="!isTetap || !!selectedGrade" class="hc-input w-full text-right disabled:bg-gray-50 disabled:text-gray-500" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Transport /bln</span>
            <HcNumberInput v-model="form.transport_allowance" :disabled="!isTetap || !!selectedGrade" class="hc-input w-full text-right disabled:bg-gray-50 disabled:text-gray-500" />
          </label>
        </div>
        <p v-if="!isTetap" class="text-[11px] leading-relaxed text-gray-500">
          Tunj. Jabatan &amp; Transport hanya untuk Pegawai Tetap — status
          {{ STATUS_LABELS[form.status] }} otomatis 0.
        </p>
        <p v-if="salaryError" class="flex items-start gap-1 text-xs font-medium text-red-600">
          <ExclamationTriangleIcon class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {{ salaryError }}
        </p>
        <p v-else-if="selectedGrade" class="text-[11px] leading-relaxed text-gray-500">
          Skala upah grade <b>{{ selectedGrade.code }}</b>: Gaji Pokok
          {{ fmtNum(selectedGrade.salary_min) }} – {{ fmtNum(selectedGrade.salary_max) }} /bln.
          <template v-if="isTetap">Tunj. Jabatan &amp; Transport otomatis mengikuti tarif grade.</template>
        </p>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">TMT / Awal PKWT</span>
            <input v-model="form.join_date" type="date" class="hc-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tanggal Lahir</span>
            <input v-model="form.birth_date" type="date" class="hc-input w-full" />
          </label>
        </div>
        <div class="grid gap-3" :class="isTetap ? 'grid-cols-3' : 'grid-cols-1'">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Status PTKP</span>
            <select v-model="form.ptkp_status" class="hc-select w-full">
              <option :value="null">– (default TK/0)</option>
              <option v-for="p in PTKP_OPTIONS" :key="p" :value="p">{{ p }}</option>
            </select>
          </label>
          <label v-if="isTetap" class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Bulan Cuti</span>
            <select v-model="form.cuti_month" class="hc-select w-full">
              <option :value="null">–</option>
              <option v-for="(b, i) in BULAN_PANJANG" :key="i" :value="i + 1">{{ b }}</option>
            </select>
          </label>
          <label v-if="isTetap" class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Hak Cuti</span>
            <select v-model="form.cuti_entitlement" class="hc-select w-full">
              <option :value="null">–</option>
              <option value="thn">THN — THP × 1</option>
              <option value="3thn">3 THN — THP × 2</option>
            </select>
          </label>
        </div>
        <p class="text-[11px] leading-relaxed text-gray-400">
          Tanggal lahir → model Biaya Purnabakti · Status PTKP → PPh 21 TER ·
          Bulan &amp; Hak Cuti → Tunjangan Cuti (pegawai tetap).
        </p>
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
          <button type="submit" class="hc-btn" :disabled="!!salaryError">{{ editingEmp ? 'Simpan Perubahan' : 'Tambah' }}</button>
        </div>
      </form>
    </HcModal>
  </HcLayout>
</template>

<script setup>
import { computed, h, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  ArrowDownTrayIcon, ArrowUpTrayIcon, ChatBubbleBottomCenterTextIcon,
  ExclamationTriangleIcon, InformationCircleIcon, MagnifyingGlassIcon,
  PencilSquareIcon, PlusIcon, TrashIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'
import HcLayout from '@/Layouts/HcLayout.vue'
import StatCard from '@/Components/HcRkap/StatCard.vue'
import HcModal from '@/Components/HcRkap/HcModal.vue'
import HcNumberInput from '@/Components/HcRkap/HcNumberInput.vue'
import { BULAN_PANJANG, useHcFormat } from '@/composables/useHcFormat'

const PTKP_OPTIONS = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3']

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
  gaji: 'Gaji Pokok /thn',
  tunj_jabatan: 'Tunj. Jabatan /thn',
  tunj_transport: 'Tunj. Transport /thn',
  thr: 'THR',
  bonus: 'Bonus',
  kompensasi: 'Kompensasi',
  cuti: 'Tunj. Cuti /thn',
  bpjs_kes: 'BPJS Kes /thn',
  bpjs_tk: 'BPJS TK /thn',
  dplk: 'DPLK /thn',
  pph21: 'PPh 21 /thn',
  fee: 'Mgmt Fee /thn',
  ppn: 'PPN /thn',
}

// urutan kanonis komponen biaya tahunan (untuk tab "Semua Pegawai")
const COMPONENT_ORDER = [
  'gaji', 'tunj_jabatan', 'tunj_transport', 'thr', 'bonus', 'cuti', 'kompensasi',
  'bpjs_kes', 'bpjs_tk', 'dplk', 'pph21', 'fee', 'ppn',
]

// kolom biaya per bulan (mengikuti data yang tersedia per status)
const MONTHLY_ORDER = ['tunj_jabatan', 'tunj_transport', 'thp', 'thp_thn', 'cuti', 'bpjs_kes', 'bpjs_tk', 'dplk', 'pph21', 'fee', 'ppn', 'total']
const MONTHLY_LABELS = {
  tunj_jabatan: 'Tunj. Jabatan /bln',
  tunj_transport: 'Tunj. Transport /bln',
  thp: 'THP /bln',
  thp_thn: 'THP /thn',
  cuti: 'Tunj. Cuti /bln',
  bpjs_kes: 'BPJS Kes /bln',
  bpjs_tk: 'BPJS TK /bln',
  dplk: 'DPLK /bln',
  pph21: 'PPh 21 /bln',
  fee: 'Mgmt Fee /bln',
  ppn: 'PPN /bln',
  total: 'Total /bln',
}

const tab = ref(Object.keys(props.byStatus)[0] ?? 'tetap')
const isAll = computed(() => tab.value === 'semua')
const allEmployees = computed(() => Object.values(props.byStatus).flatMap(s => s.employees ?? []))
const totalHeadcount = computed(() => allEmployees.value.length)

// "current" = data status terpilih, atau gabungan semua status untuk tab "Semua"
const current = computed(() => {
  if (isAll.value) {
    const employees = allEmployees.value
    // tab Semua menampilkan seluruh komponen biaya: gabungkan total per
    // komponen lintas status (komponen yang tidak dimiliki status = 0)
    const totals = {}
    COMPONENT_ORDER.forEach((key) => {
      if (employees.some(e => key in (e.components ?? {}))) {
        totals[key] = employees.reduce((sum, e) => sum + (e.components[key] ?? 0), 0)
      }
    })
    return {
      headcount: employees.length,
      employees,
      grand_total: employees.reduce((sum, e) => sum + (e.total ?? 0), 0),
      totals,
      assumption_notes: [],
    }
  }
  return props.byStatus[tab.value]
})
const componentKeys = computed(() => Object.keys(current.value?.totals ?? {}))
const monthlyKeys = computed(() => {
  const employees = current.value?.employees ?? []
  return MONTHLY_ORDER.filter(key => employees.some(e => key in (e.monthly ?? {})))
})

// ── filter periode panel Grand Total: bulanan (÷12) / tahunan ─────────────
const totalPeriod = ref('tahunan')
const periodSuffix = computed(() => totalPeriod.value === 'bulanan' ? '/bln' : '/thn')
const periodValue = (v) => totalPeriod.value === 'bulanan' ? (v ?? 0) / 12 : (v ?? 0)
const periodLabel = (key) =>
  `${(COMPONENT_LABELS[key] ?? key).replace(' /thn', '')} ${periodSuffix.value}`

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
    sortDir.value = STRING_SORT_KEYS.includes(key) ? 'asc' : 'desc'
  }
}

const STRING_SORT_KEYS = ['name', 'unit', 'status', 'jabatan', 'birth_date', 'join_date', 'ptkp_status']

const filteredEmployees = computed(() => {
  let list = current.value?.employees ?? []
  const q = search.value.trim().toLowerCase()
  if (q) {
    list = list.filter(e => e.name.toLowerCase().includes(q)
      || (e.jabatan ?? '').toLowerCase().includes(q)
      || (e.notes ?? '').toLowerCase().includes(q))
  }
  if (filterUnit.value) list = list.filter(e => e.work_unit_id === filterUnit.value)

  const dir = sortDir.value === 'asc' ? 1 : -1
  const key = sortKey.value
  const isString = STRING_SORT_KEYS.includes(key)
  return [...list].sort((a, b) => {
    const va = isString ? (a[key] ?? '') : (a[key] ?? 0)
    const vb = isString ? (b[key] ?? '') : (b[key] ?? 0)
    return (typeof va === 'string' ? va.localeCompare(vb, 'id') : va - vb) * dir
  })
})

// total mengikuti baris yang tampil agar tabel konsisten saat difilter
const viewTotals = computed(() => {
  const components = {}
  componentKeys.value.forEach((key) => {
    components[key] = filteredEmployees.value.reduce((sum, e) => sum + (e.components[key] ?? 0), 0)
  })
  const monthly = {}
  monthlyKeys.value.forEach((key) => {
    monthly[key] = filteredEmployees.value.reduce((sum, e) => sum + (e.monthly?.[key] ?? 0), 0)
  })
  return {
    components,
    monthly,
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

// ── Grade / skala upah ────────────────────────────────────────────────────
const grades = computed(() => props.options.grades ?? [])

// referensi jabatan dari struktur grading (urut level); jabatan lama pegawai
// yang tidak ada di referensi tetap ditampilkan agar tidak hilang saat edit
const jabatanOptions = computed(() => {
  const seen = new Set()
  const out = []
  grades.value.forEach((g) => {
    if (!seen.has(g.jabatan)) { seen.add(g.jabatan); out.push(g.jabatan) }
  })
  if (form.jabatan && !seen.has(form.jabatan)) out.push(form.jabatan)
  return out
})

// grade difilter mengikuti jabatan terpilih
const gradeOptions = computed(() => form.jabatan
  ? grades.value.filter(g => g.jabatan === form.jabatan)
  : grades.value)

const selectedGrade = computed(() =>
  grades.value.find(g => g.id === form.salary_grade_id) ?? null)

// pilih grade → jabatan & tunjangan mengikuti referensi grade
// (tunjangan hanya untuk pegawai tetap; status lain selalu 0)
function onGradeChange() {
  const g = selectedGrade.value
  if (!g) return
  form.jabatan = g.jabatan
  form.position_allowance = isTetap.value ? g.position_allowance : 0
  form.transport_allowance = isTetap.value ? g.transport_allowance : 0
}

// ganti jabatan → grade yang tidak sesuai jabatan dilepas
function onJabatanChange() {
  if (selectedGrade.value && selectedGrade.value.jabatan !== form.jabatan) {
    form.salary_grade_id = null
  }
}

// gaji dasar wajib dalam rentang min–max skala upah grade terpilih
const salaryError = computed(() => {
  const g = selectedGrade.value
  if (!g) return null
  const salary = Number(form.base_salary) || 0
  if (salary < g.salary_min || salary > g.salary_max) {
    return `Gaji Pokok di luar skala upah grade ${g.code}: harus ${fmtNum(g.salary_min)} – ${fmtNum(g.salary_max)} /bln.`
  }
  return null
})

// ── CRUD ──────────────────────────────────────────────────────────────────
const modal = ref(false)
const editingEmp = ref(null)
const form = reactive({
  name: '', jabatan: '', salary_grade_id: null, status: 'tetap', work_unit_id: null,
  base_salary: 0, prev_year_salary: null, position_allowance: 0, transport_allowance: 0,
  join_date: null, birth_date: null, ptkp_status: null, cuti_month: null, cuti_entitlement: null,
  notes: '',
})

// ── Gaji /bln = Gaji Thn Sebelumnya + (THP thn sebelumnya × kenaikan%) ────
// THP = gaji thn sebelumnya + tunj. jabatan + tunj. transport (tetap saja)
const kenaikanPct = computed(() => props.options.kenaikan?.[form.status] ?? 0)
const hasPrevSalary = computed(() => Number(form.prev_year_salary) > 0)

watch(
  [() => form.prev_year_salary, () => form.status, () => form.position_allowance, () => form.transport_allowance],
  () => {
    if (!hasPrevSalary.value) return
    const prev = Number(form.prev_year_salary)
    const allowances = form.status === 'tetap'
      ? (Number(form.position_allowance) || 0) + (Number(form.transport_allowance) || 0)
      : 0
    form.base_salary = Math.round((prev + (prev + allowances) * kenaikanPct.value / 100) * 100) / 100
  },
)

// ── Tunj. Jabatan & Transport hanya untuk pegawai tetap ───────────────────
const isTetap = computed(() => form.status === 'tetap')

watch(isTetap, (tetap) => {
  if (!tetap) {
    form.position_allowance = 0
    form.transport_allowance = 0
    form.cuti_month = null
    form.cuti_entitlement = null
  } else if (selectedGrade.value) {
    form.position_allowance = selectedGrade.value.position_allowance
    form.transport_allowance = selectedGrade.value.transport_allowance
  }
})

function openCreate() {
  editingEmp.value = null
  Object.assign(form, {
    name: '', jabatan: '', salary_grade_id: null,
    status: isAll.value ? 'tetap' : tab.value, work_unit_id: null,
    base_salary: 0, prev_year_salary: null, position_allowance: 0, transport_allowance: 0,
    join_date: null, birth_date: null, ptkp_status: null, cuti_month: null, cuti_entitlement: null,
    notes: '',
  })
  modal.value = true
}

function openEdit(emp) {
  editingEmp.value = emp
  Object.assign(form, {
    name: emp.name,
    jabatan: emp.jabatan ?? '',
    salary_grade_id: emp.salary_grade_id ?? null,
    status: emp.status,
    work_unit_id: emp.work_unit_id,
    base_salary: emp.base_salary,
    prev_year_salary: emp.prev_year_salary ?? null,
    position_allowance: emp.position_allowance,
    transport_allowance: emp.transport_allowance,
    join_date: emp.join_date,
    birth_date: emp.birth_date ?? null,
    ptkp_status: emp.ptkp_status ?? null,
    cuti_month: emp.cuti_month ?? null,
    cuti_entitlement: emp.cuti_entitlement ?? null,
    notes: emp.notes ?? '',
  })
  // tunjangan pegawai ber-grade selalu mengikuti tarif grade-nya
  onGradeChange()
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
