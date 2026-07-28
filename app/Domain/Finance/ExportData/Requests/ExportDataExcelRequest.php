<?php

namespace App\Domain\Finance\ExportData\Requests;

use App\Domain\Finance\ExportData\Services\ExportDataWorkbookService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportDataExcelRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'reports'   => ['required', 'array', 'min:1'],
            'reports.*' => ['required', 'string', 'distinct', Rule::in(array_keys(ExportDataWorkbookService::REPORTS))],

            'filters'                     => ['nullable', 'array'],
            'filters.segment'             => ['nullable', 'in:B2B,B2C,ALL'],
            'filters.tanggal_dari'        => ['nullable', 'date'],
            'filters.tanggal_sampai'      => ['nullable', 'date', 'after_or_equal:filters.tanggal_dari'],
            'filters.klien_ar_id'         => ['nullable', 'integer', 'exists:tb_klien_ar,id'],
            'filters.metode_pembayaran'   => ['nullable', 'in:TRANSFER,CASH,GIRO'],

            // Aging Report
            'filters.as_of_date'          => ['nullable', 'date'],

            // Rekap Per Klien
            'filters.periode_bulan'       => ['nullable', 'integer', 'between:1,12'],
            'filters.periode_tahun'       => ['nullable', 'integer', 'between:2000,2100'],

            // Jurnal Aktivitas per PIC
            'filters.no_referensi'        => ['nullable', 'string', 'max:100'],
            'filters.karyawan_id'         => ['nullable', 'integer', 'exists:tb_karyawan,id'],
            'filters.status_rekonsiliasi' => ['nullable', 'in:MATCHED,POSSIBLE,UNMATCHED,DIABAIKAN'],

            // Pendapatan di Muka
            'filters.status'              => ['nullable', 'in:AKTIF,DIBATALKAN,TERPAKAI'],

            // Rekening Koran
            'filters.pic_ar_id'           => ['nullable', 'integer', 'exists:tb_users,id'],
            'filters.bank_type'           => ['nullable', 'in:BCA,MANDIRI,CIMB,BSI'],
            'filters.dk'                  => ['nullable', 'in:K,D'],
            'filters.status_posting_1'    => ['nullable', 'in:MATCHED,UNMATCHED,DIABAIKAN'],
            'filters.status_posting_2'    => ['nullable', 'in:POSTED,PENDING'],
        ];
    }

    public function messages(): array
    {
        return [
            'reports.required'   => 'Pilih minimal satu laporan untuk di-export.',
            'reports.min'        => 'Pilih minimal satu laporan untuk di-export.',
            'reports.*.in'       => 'Terdapat laporan yang tidak dikenal pada pilihan export.',
            'reports.*.distinct' => 'Laporan yang sama tidak boleh dipilih dua kali.',
            'filters.tanggal_sampai.after_or_equal' => 'Tanggal "sampai" tidak boleh lebih awal dari tanggal "dari".',
        ];
    }

    /** @return string[] */
    public function reportKeys(): array
    {
        return array_values($this->validated()['reports']);
    }

    public function reportFilters(): array
    {
        return $this->validated()['filters'] ?? [];
    }
}
