<template>
  <ModuleLayout title="Turnover Pegawai" :brand="brand" accent="#0d9488" :menus="menus">
    <!-- kontrol modul: pemilih tahun + aksi utama -->
    <template #header>
      <label class="flex items-center gap-2 text-sm">
        <CalendarIcon class="h-4 w-4 text-gray-400" />
        <select
          :value="tahun" class="hc-select"
          @change="router.get(route('turnover.dashboard'), { tahun: $event.target.value })"
        >
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
      </label>
      <button class="hc-btn !bg-[#0d9488] hover:!bg-[#0b7c71]" @click="openCreate">
        <PlusIcon class="h-4 w-4" /> Catat Kejadian
      </button>
    </template>

    <p class="mb-5 text-sm text-gray-500">Arus masuk-keluar pegawai, tingkat turnover & retensi — tahun {{ tahun }}</p>

    <!-- ringkasan -->
    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard
        label="Headcount" :value="`${stats.headcount_awal} → ${stats.headcount_akhir}`"
        :hint="`rata-rata ${fmtNum(stats.avg_headcount)} · ${stats.net >= 0 ? '+' : ''}${stats.net} netto`"
      />
      <StatCard
        label="Pegawai Masuk" :value="String(stats.masuk)"
        :hint="stats.hire_rate !== null ? `hire rate ${stats.hire_rate}%` : '–'"
      />
      <StatCard
        label="Pegawai Keluar" :value="String(stats.keluar)"
        :hint="`${stats.voluntary} sukarela · ${stats.involuntary} tdk sukarela · ${stats.other_exit} lainnya`"
      />
      <StatCard
        label="Turnover Rate" :value="stats.turnover_rate !== null ? `${stats.turnover_rate}%` : '–'"
        :hint="stats.voluntary_rate !== null ? `sukarela ${stats.voluntary_rate}% · keluar ÷ rata-rata headcount` : 'keluar ÷ rata-rata headcount'"
      />
    </div>

    <!-- tren bulanan -->
    <div class="hc-card mb-5 p-4">
      <div class="mb-2 flex flex-wrap items-baseline gap-x-3">
        <h2 class="text-sm font-semibold text-gray-800">Tren Bulanan {{ tahun }}</h2>
        <p class="text-xs text-gray-400">kejadian masuk/keluar per bulan & pergerakan headcount</p>
      </div>
      <TrendChart :monthly="monthly" />
    </div>

    <!-- retensi + rincian -->
    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <!-- alasan keluar -->
      <div class="hc-card p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-800">Alasan Keluar</h2>
        <p v-if="!byReason.length" class="py-6 text-center text-xs text-gray-400">Belum ada kejadian keluar tahun ini.</p>
        <div v-else class="space-y-2.5">
          <div v-for="r in byReason" :key="r.reason ?? 'kosong'">
            <div class="mb-0.5 flex items-baseline justify-between gap-2 text-xs">
              <span class="font-medium text-gray-700">
                {{ r.label }}
                <span class="ml-1 rounded-full px-1.5 py-px text-[10px]" :class="CATEGORY_CHIP[r.category] ?? 'bg-gray-100 text-gray-500'">
                  {{ CATEGORY_LABELS[r.category] ?? '–' }}
                </span>
              </span>
              <span class="tabular-nums text-gray-500">{{ r.count }} · {{ r.pct }}%</span>
            </div>
            <div class="h-1.5 rounded-full bg-gray-100">
              <div class="h-1.5 rounded-full bg-[#0d9488]" :style="{ width: `${r.pct}%` }" />
            </div>
          </div>
        </div>
      </div>

      <!-- masa kerja & retensi -->
      <div class="hc-card p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-800">Masa Kerja & Retensi</h2>
        <dl class="space-y-3 text-sm">
          <div class="flex items-baseline justify-between">
            <dt class="text-gray-500">Rata-rata masa kerja pegawai keluar</dt>
            <dd class="font-semibold tabular-nums text-gray-900">{{ fmtTenure(stats.avg_tenure_months) }}</dd>
          </div>
          <div class="flex items-baseline justify-between">
            <dt class="text-gray-500">Keluar dini (&lt; 12 bulan)</dt>
            <dd class="font-semibold tabular-nums text-gray-900">
              {{ stats.early_leavers }}<span v-if="stats.early_rate !== null" class="ml-1 text-xs font-normal text-gray-400">({{ stats.early_rate }}% dari keluar)</span>
            </dd>
          </div>
          <div class="flex items-baseline justify-between">
            <dt class="text-gray-500">Turnover sukarela : tidak sukarela</dt>
            <dd class="font-semibold tabular-nums text-gray-900">{{ stats.voluntary }} : {{ stats.involuntary }}</dd>
          </div>
        </dl>
        <!-- per status -->
        <h3 class="mb-1.5 mt-5 text-xs font-semibold uppercase tracking-wide text-gray-400">Per Status Pegawai</h3>
        <table class="w-full text-xs">
          <thead>
            <tr class="text-left text-gray-400">
              <th class="py-1 font-medium">Status</th>
              <th class="py-1 text-right font-medium">Masuk</th>
              <th class="py-1 text-right font-medium">Keluar</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="s in byStatus" :key="s.status">
              <td class="py-1.5 text-gray-700">{{ STATUS_LABELS[s.status] ?? s.status }}</td>
              <td class="py-1.5 text-right tabular-nums text-emerald-600">{{ s.masuk }}</td>
              <td class="py-1.5 text-right tabular-nums text-red-600">{{ s.keluar }}</td>
            </tr>
            <tr v-if="!byStatus.length"><td colspan="3" class="py-4 text-center text-gray-400">Belum ada kejadian.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- per unit -->
      <div class="hc-card p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-800">Per Unit Kerja</h2>
        <table class="w-full text-xs">
          <thead>
            <tr class="text-left text-gray-400">
              <th class="py-1 font-medium">Unit</th>
              <th class="py-1 text-right font-medium">Aktif</th>
              <th class="py-1 text-right font-medium">Masuk</th>
              <th class="py-1 text-right font-medium">Keluar</th>
              <th class="py-1 text-right font-medium">Rate</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="u in byUnit" :key="u.unit">
              <td class="py-1.5 font-medium text-gray-700" :title="u.unit_name">{{ u.unit }}</td>
              <td class="py-1.5 text-right tabular-nums text-gray-500">{{ u.headcount }}</td>
              <td class="py-1.5 text-right tabular-nums text-emerald-600">{{ u.masuk }}</td>
              <td class="py-1.5 text-right tabular-nums text-red-600">{{ u.keluar }}</td>
              <td class="py-1.5 text-right tabular-nums text-gray-700">{{ u.rate !== null ? `${u.rate}%` : '–' }}</td>
            </tr>
            <tr v-if="!byUnit.length"><td colspan="5" class="py-4 text-center text-gray-400">Belum ada kejadian tahun ini.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- riwayat kejadian -->
    <div class="hc-card">
      <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3">
        <h2 class="text-sm font-semibold text-gray-800">Riwayat Kejadian {{ tahun }}</h2>
        <div class="ml-auto flex items-center gap-2">
          <select v-model="filterType" class="hc-select">
            <option value="">Semua jenis</option>
            <option value="masuk">Masuk</option>
            <option value="keluar">Keluar</option>
          </select>
          <div class="relative">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input v-model="search" type="search" class="hc-input w-48 py-1.5 pl-8" placeholder="Cari nama…" />
          </div>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-max w-full divide-y divide-gray-100 text-sm">
          <thead class="bg-gray-50/60">
            <tr>
              <th class="hc-th">Tanggal</th>
              <th class="hc-th">Jenis</th>
              <th class="hc-th">Nama</th>
              <th class="hc-th">Unit</th>
              <th class="hc-th">Status</th>
              <th class="hc-th">Alasan</th>
              <th class="hc-th text-right">Masa Kerja</th>
              <th class="hc-th">Catatan</th>
              <th class="hc-th text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="e in filteredEvents" :key="e.id" class="hover:bg-gray-50/50">
              <td class="hc-td tabular-nums text-gray-600">{{ fmtDate(e.event_date) }}</td>
              <td class="hc-td">
                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                  :class="e.type === 'masuk' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'"
                >{{ e.type === 'masuk' ? 'Masuk' : 'Keluar' }}</span>
              </td>
              <td class="hc-td font-medium text-gray-900">
                {{ e.employee_name }}
                <span v-if="e.jabatan" class="block text-[11px] font-normal text-gray-400">{{ e.jabatan }}</span>
              </td>
              <td class="hc-td text-xs text-gray-500" :title="e.unit_name">{{ e.unit ?? '–' }}</td>
              <td class="hc-td text-xs text-gray-600">{{ STATUS_LABELS[e.employee_status] ?? e.employee_status ?? '–' }}</td>
              <td class="hc-td text-xs">
                <template v-if="e.reason">
                  {{ e.reason_label }}
                  <span class="ml-1 rounded-full px-1.5 py-px text-[10px]" :class="CATEGORY_CHIP[e.category] ?? 'bg-gray-100 text-gray-500'">
                    {{ CATEGORY_LABELS[e.category] ?? '–' }}
                  </span>
                </template>
                <span v-else class="text-gray-400">–</span>
              </td>
              <td class="hc-td text-right tabular-nums text-xs text-gray-600">{{ fmtTenure(e.tenure_months) }}</td>
              <td class="hc-td max-w-52 truncate text-xs text-gray-500" :title="e.notes">{{ e.notes ?? '–' }}</td>
              <td class="hc-td text-right">
                <button class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600" title="Ubah" @click="openEdit(e)">
                  <PencilSquareIcon class="h-4 w-4" />
                </button>
                <button class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Hapus" @click="confirmDelete(e)">
                  <TrashIcon class="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!filteredEvents.length" class="p-8 text-center text-sm text-gray-400">
          {{ events.length ? 'Tidak ada kejadian yang cocok dengan filter.' : `Belum ada kejadian turnover tahun ${tahun}.` }}
        </p>
      </div>
    </div>
    <p class="mt-3 text-xs text-gray-400">
      Turnover rate = pegawai keluar ÷ rata-rata headcount (awal + akhir periode ÷ 2) × 100%.
      Pegawai baru dari modul RKAP HC otomatis tercatat sebagai kejadian masuk; mencatat kejadian keluar dapat
      sekaligus menonaktifkan pegawai dari roster & menyamakan anggaran biaya personil.
    </p>

    <!-- modal catat/ubah kejadian -->
    <HcModal :show="modal" :title="modalTitle" @close="modal = false">
      <!-- jenis kejadian (hanya saat mencatat baru) -->
      <div v-if="!editingEvent" class="mb-3 grid grid-cols-2 gap-2">
        <button
          v-for="t in [['masuk', 'Pegawai Masuk'], ['keluar', 'Pegawai Keluar']]" :key="t[0]" type="button"
          class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
          :class="createType === t[0]
            ? (t[0] === 'masuk' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-red-400 bg-red-50 text-red-700')
            : 'border-gray-200 text-gray-500 hover:bg-gray-50'"
          @click="createType = t[0]"
        >{{ t[1] }}</button>
      </div>

      <!-- ── FORM PEGAWAI MASUK — daftar pegawai baru langsung ke roster ─── -->
      <form v-if="isHireForm" class="space-y-3" @submit.prevent="submitHire">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Nama Pegawai</span>
          <input v-model="hireForm.name" type="text" required class="hc-input w-full" />
        </label>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Jabatan</span>
            <select v-model="hireForm.jabatan" class="hc-select w-full" @change="onJabatanChange">
              <option value="">– pilih jabatan –</option>
              <option v-for="j in jabatanOptions" :key="j" :value="j">{{ j }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Grade / Level</span>
            <select v-model="hireForm.salary_grade_id" class="hc-select w-full" @change="onGradeChange">
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
            <select v-model="hireForm.status" class="hc-select w-full">
              <option v-for="(label, s) in STATUS_LABELS" :key="s" :value="s">{{ label }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Unit Kerja</span>
            <select v-model="hireForm.work_unit_id" class="hc-select w-full">
              <option :value="null">–</option>
              <option v-for="u in unitOptions" :key="u.id" :value="u.id">{{ u.label }}</option>
            </select>
          </label>
        </div>

        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Gaji Pokok /bln</span>
          <HcNumberInput
            v-model="hireForm.base_salary" required
            class="hc-input w-full text-right"
            :class="salaryError ? '!border-red-400 focus:!border-red-500 focus:!ring-red-500' : ''"
          />
        </label>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Jabatan /bln</span>
            <HcNumberInput v-model="hireForm.position_allowance" :disabled="!isTetap || !!selectedGrade" class="hc-input w-full text-right disabled:bg-gray-50 disabled:text-gray-500" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tunj. Transport /bln</span>
            <HcNumberInput v-model="hireForm.transport_allowance" :disabled="!isTetap || !!selectedGrade" class="hc-input w-full text-right disabled:bg-gray-50 disabled:text-gray-500" />
          </label>
        </div>
        <p v-if="!isTetap" class="text-[11px] leading-relaxed text-gray-500">
          Tunj. Jabatan &amp; Transport hanya untuk Pegawai Tetap — status
          {{ STATUS_LABELS[hireForm.status] }} otomatis 0.
        </p>
        <p v-if="salaryError" class="flex items-start gap-1 text-xs font-medium text-red-600">
          <ExclamationTriangleIcon class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {{ salaryError }}
        </p>
        <p v-else-if="selectedGrade" class="text-[11px] leading-relaxed text-gray-500">
          Skala upah grade <b>{{ selectedGrade.code }}</b>: Gaji Pokok
          {{ fmtNum(selectedGrade.salary_min) }} – {{ fmtNum(selectedGrade.salary_max) }} /bln.
          <template v-if="isTetap">Tunj. Jabatan &amp; Transport otomatis mengikuti tarif grade.</template>
        </p>

        <div class="grid grid-cols-3 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tgl Lahir</span>
            <input v-model="hireForm.birth_date" type="date" class="hc-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tgl Join</span>
            <input v-model="hireForm.join_date" type="date" class="hc-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Status PTKP</span>
            <select v-model="hireForm.ptkp_status" class="hc-select w-full">
              <option :value="null">– (default TK/0)</option>
              <option v-for="p in PTKP_OPTIONS" :key="p" :value="p">{{ p }}</option>
            </select>
          </label>
        </div>
        <p class="text-[11px] leading-relaxed text-gray-400">
          Tgl Lahir → model Biaya Purnabakti · Status PTKP → PPh 21 TER ·
          Tgl Join → dasar masa kerja &amp; tanggal kejadian masuk.
        </p>

        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Catatan</span>
          <textarea v-model="hireForm.notes" rows="2" maxlength="1000" class="hc-input w-full resize-y"
            placeholder="mis. penempatan awal, hasil rekrutmen, dsb." />
        </label>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="modal = false">Batal</button>
          <button type="submit" class="hc-btn !bg-[#0d9488] hover:!bg-[#0b7c71]" :disabled="!!salaryError">
            Catat &amp; Tambah ke Roster
          </button>
        </div>
      </form>

      <!-- ── FORM PEGAWAI KELUAR / UBAH KEJADIAN ─────────────────────────── -->
      <form v-else class="space-y-3" @submit.prevent="submit">

        <!-- pegawai roster (opsional; keluar dari roster memicu nonaktif) -->
        <label v-if="!editingEvent" class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">
            Pegawai (roster RKAP HC) <span class="font-normal text-gray-400">— kosongkan untuk data historis/manual</span>
          </span>
          <select v-model="form.employee_id" class="hc-select w-full" @change="onEmployeeChange">
            <option :value="null">– entri manual –</option>
            <option v-for="e in options.employees" :key="e.id" :value="e.id">{{ e.name }}</option>
          </select>
        </label>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Nama Pegawai</span>
            <input v-model="form.employee_name" type="text" :disabled="!!form.employee_id" required
              class="hc-input w-full disabled:bg-gray-50 disabled:text-gray-500" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Status</span>
            <select v-model="form.employee_status" :disabled="!!form.employee_id" class="hc-select w-full disabled:bg-gray-50 disabled:text-gray-500">
              <option :value="null">–</option>
              <option v-for="(label, s) in STATUS_LABELS" :key="s" :value="s">{{ label }}</option>
            </select>
          </label>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Unit Kerja</span>
            <select v-model="form.work_unit_id" :disabled="!!form.employee_id" class="hc-select w-full disabled:bg-gray-50 disabled:text-gray-500">
              <option :value="null">–</option>
              <option v-for="u in unitOptions" :key="u.id" :value="u.id">{{ u.label }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Jabatan</span>
            <input v-model="form.jabatan" type="text" :disabled="!!form.employee_id"
              class="hc-input w-full disabled:bg-gray-50 disabled:text-gray-500" />
          </label>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">Tanggal Keluar</span>
            <input v-model="form.event_date" type="date" required class="hc-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-600">TMT Masuk <span class="font-normal text-gray-400">(dasar masa kerja)</span></span>
            <input v-model="form.join_date" type="date" :disabled="!!form.employee_id"
              class="hc-input w-full disabled:bg-gray-50 disabled:text-gray-500" />
          </label>
        </div>

        <label v-if="form.type === 'keluar'" class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Alasan Keluar</span>
          <select v-model="form.reason" required class="hc-select w-full">
            <option :value="null" disabled>– pilih alasan –</option>
            <option v-for="r in options.reasons" :key="r.key" :value="r.key">
              {{ r.label }} ({{ CATEGORY_LABELS[r.category] }})
            </option>
          </select>
        </label>

        <label
          v-if="!editingEvent && form.type === 'keluar' && form.employee_id"
          class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/60 p-3 text-xs text-amber-800"
        >
          <input v-model="form.deactivate" type="checkbox" class="mt-0.5 rounded border-gray-300" />
          <span>
            <b>Nonaktifkan pegawai dari roster RKAP HC.</b>
            Entri anggaran bersumber pegawai (Gaji Pokok, Tunj. Jabatan, Transport) ikut disamakan.
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Catatan</span>
          <textarea v-model="form.notes" rows="2" maxlength="1000" class="hc-input w-full resize-y"
            placeholder="mis. pindah ke perusahaan lain, restrukturisasi, dsb." />
        </label>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="modal = false">Batal</button>
          <button type="submit" class="hc-btn !bg-[#0d9488] hover:!bg-[#0b7c71]">
            {{ editingEvent ? 'Simpan Perubahan' : 'Catat' }}
          </button>
        </div>
      </form>
    </HcModal>
  </ModuleLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  ArrowsRightLeftIcon, CalendarIcon, ExclamationTriangleIcon, MagnifyingGlassIcon,
  PencilSquareIcon, PlusIcon, TrashIcon,
} from '@heroicons/vue/24/outline'
import ModuleLayout from '@/Layouts/ModuleLayout.vue'
import StatCard from '@/Components/HcRkap/StatCard.vue'
import HcModal from '@/Components/HcRkap/HcModal.vue'
import HcNumberInput from '@/Components/HcRkap/HcNumberInput.vue'
import TrendChart from '@/Components/Turnover/TrendChart.vue'
import { useHcFormat } from '@/composables/useHcFormat'

const props = defineProps({
  tahun: Number,
  years: Array,
  stats: Object,
  monthly: Array,
  byReason: Array,
  byStatus: Array,
  byUnit: Array,
  events: Array,
  options: Object,
})

const { fmtNum } = useHcFormat()

// identitas & navigasi sidebar modul Turnover
const brand = {
  badge: 'TO',
  name: 'Turnover Pegawai',
  subtitle: 'Human Capital & Corporate Secretary',
  note: 'Monitoring arus masuk-keluar\npegawai & tingkat retensi',
}
const menus = [
  { label: 'Dashboard Turnover', icon: ArrowsRightLeftIcon, route: 'turnover.dashboard', exact: true },
]

const STATUS_LABELS = { tetap: 'Pegawai Tetap', kontrak: 'Pegawai Kontrak', honor: 'Honor / Outsource', direksi: 'Direksi' }
const CATEGORY_LABELS = { sukarela: 'sukarela', tidak_sukarela: 'tidak sukarela', lainnya: 'lainnya' }
const CATEGORY_CHIP = {
  sukarela: 'bg-amber-50 text-amber-700',
  tidak_sukarela: 'bg-red-50 text-red-700',
  lainnya: 'bg-gray-100 text-gray-500',
}

const fmtDate = (d) => new Date(`${d}T00:00:00`)
  .toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
const fmtTenure = (months) => {
  if (months === null || months === undefined) return '–'
  const y = Math.floor(months / 12)
  const m = months % 12
  return y ? `${y} thn ${m} bln` : `${m} bln`
}

// ── filter riwayat ─────────────────────────────────────────────────────────
const search = ref('')
const filterType = ref('')
const filteredEvents = computed(() => {
  let list = props.events
  if (filterType.value) list = list.filter(e => e.type === filterType.value)
  const q = search.value.trim().toLowerCase()
  if (q) list = list.filter(e => e.employee_name.toLowerCase().includes(q))
  return list
})

const unitOptions = computed(() => {
  const units = props.options.units
  const out = []
  const walk = (parentId, depth) => {
    units.filter(u => u.parent_id === parentId).forEach(u => {
      out.push({ id: u.id, label: `${'  '.repeat(depth)}${u.code} — ${u.name}` })
      walk(u.id, depth + 1)
    })
  }
  walk(null, 0)
  return out
})

// ── CRUD kejadian ──────────────────────────────────────────────────────────
const modal = ref(false)
const editingEvent = ref(null)
// jenis kejadian saat mencatat baru; menentukan form mana yang tampil
const createType = ref('masuk')
const isHireForm = computed(() => !editingEvent.value && createType.value === 'masuk')
const modalTitle = computed(() => editingEvent.value
  ? 'Ubah Kejadian'
  : (createType.value === 'masuk' ? 'Catat Pegawai Masuk' : 'Catat Pegawai Keluar'))

const form = reactive({
  type: 'keluar', employee_id: null, employee_name: '', work_unit_id: null,
  employee_status: null, jabatan: '', event_date: '', join_date: null,
  reason: null, notes: '', deactivate: true,
})

// ── Form "Pegawai Masuk": buat pegawai roster (aturan sama dg modul RKAP HC) ─
const PTKP_OPTIONS = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3']
const hireForm = reactive({
  name: '', jabatan: '', salary_grade_id: null, status: 'tetap', work_unit_id: null,
  base_salary: 0, position_allowance: 0, transport_allowance: 0,
  join_date: '', birth_date: null, ptkp_status: null, notes: '',
})

// struktur grade → jabatan & skala upah (mengikuti menu Pegawai & Biaya RKAP HC)
const grades = computed(() => props.options.grades ?? [])
const jabatanOptions = computed(() => {
  const seen = new Set()
  const out = []
  grades.value.forEach((g) => {
    if (!seen.has(g.jabatan)) { seen.add(g.jabatan); out.push(g.jabatan) }
  })
  return out
})
// grade difilter mengikuti jabatan terpilih (cascading dropdown)
const gradeOptions = computed(() => hireForm.jabatan
  ? grades.value.filter(g => g.jabatan === hireForm.jabatan)
  : grades.value)
const selectedGrade = computed(() => grades.value.find(g => g.id === hireForm.salary_grade_id) ?? null)
const isTetap = computed(() => hireForm.status === 'tetap')

// pilih grade → jabatan & tunjangan mengikuti tarif grade (tetap saja)
function onGradeChange() {
  const g = selectedGrade.value
  if (!g) return
  hireForm.jabatan = g.jabatan
  hireForm.position_allowance = isTetap.value ? g.position_allowance : 0
  hireForm.transport_allowance = isTetap.value ? g.transport_allowance : 0
}
// ganti jabatan → grade yang tidak sesuai dilepas
function onJabatanChange() {
  if (selectedGrade.value && selectedGrade.value.jabatan !== hireForm.jabatan) {
    hireForm.salary_grade_id = null
  }
}
// tunjangan hanya untuk pegawai tetap; status lain otomatis 0 & terkunci
watch(isTetap, (tetap) => {
  if (!tetap) {
    hireForm.position_allowance = 0
    hireForm.transport_allowance = 0
  } else if (selectedGrade.value) {
    hireForm.position_allowance = selectedGrade.value.position_allowance
    hireForm.transport_allowance = selectedGrade.value.transport_allowance
  }
})
// gaji pokok wajib dalam rentang skala upah grade terpilih
const salaryError = computed(() => {
  const g = selectedGrade.value
  if (!g) return null
  const salary = Number(hireForm.base_salary) || 0
  if (salary < g.salary_min || salary > g.salary_max) {
    return `Gaji Pokok di luar skala upah grade ${g.code}: harus ${fmtNum(g.salary_min)} – ${fmtNum(g.salary_max)} /bln.`
  }
  return null
})

function submitHire() {
  if (salaryError.value) return
  router.post(route('turnover.store'), { ...hireForm, type: 'masuk', tahun: props.tahun }, {
    preserveScroll: true,
    onSuccess: () => { modal.value = false },
  })
}

// pilih pegawai roster → snapshot terisi otomatis
function onEmployeeChange() {
  const e = props.options.employees.find(x => x.id === form.employee_id)
  if (!e) return
  form.employee_name = e.name
  form.work_unit_id = e.work_unit_id
  form.employee_status = e.status
  form.jabatan = e.jabatan ?? ''
  form.join_date = e.join_date
}

function openCreate() {
  editingEvent.value = null
  createType.value = 'masuk'
  Object.assign(hireForm, {
    name: '', jabatan: '', salary_grade_id: null, status: 'tetap', work_unit_id: null,
    base_salary: 0, position_allowance: 0, transport_allowance: 0,
    join_date: '', birth_date: null, ptkp_status: null, notes: '',
  })
  Object.assign(form, {
    type: 'keluar', employee_id: null, employee_name: '', work_unit_id: null,
    employee_status: null, jabatan: '', event_date: new Date().toISOString().slice(0, 10),
    join_date: null, reason: null, notes: '', deactivate: true,
  })
  modal.value = true
}

function openEdit(e) {
  editingEvent.value = e
  Object.assign(form, {
    type: e.type,
    employee_id: null, // tautan pegawai tidak diubah saat edit
    employee_name: e.employee_name,
    work_unit_id: e.work_unit_id,
    employee_status: e.employee_status,
    jabatan: e.jabatan ?? '',
    event_date: e.event_date,
    join_date: e.join_date,
    reason: e.reason,
    notes: e.notes ?? '',
    deactivate: false,
  })
  modal.value = true
}

function submit() {
  const opts = { preserveScroll: true, onSuccess: () => { modal.value = false } }
  if (editingEvent.value) {
    router.put(route('turnover.update', editingEvent.value.id), { ...form, tahun: props.tahun }, opts)
  } else {
    router.post(route('turnover.store'), { ...form, tahun: props.tahun }, opts)
  }
}

function confirmDelete(e) {
  const isCancelExit = e.type === 'keluar' && e.employee_id
  Swal.fire({
    title: 'Hapus kejadian?',
    text: `${e.employee_name} — ${e.type} ${fmtDate(e.event_date)}.`
      + (isCancelExit ? ' Bila ini kejadian keluar terakhirnya, pegawai akan diaktifkan kembali di roster.' : ''),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d03b3b',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  }).then((res) => {
    if (res.isConfirmed) {
      router.delete(route('turnover.destroy', { event: e.id, tahun: props.tahun }), { preserveScroll: true })
    }
  })
}
</script>
