<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $klienNama }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="background:#1b5e20;padding:20px 28px;">
                            <span style="color:#ffffff;font-size:18px;font-weight:bold;">Tagihan Iron</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">
                                Yth. Bapak/Ibu <strong>{{ $klienNama }}</strong>,
                            </p>
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">
                                @if (count($documents) === 1)
                                    Bersama ini kami sampaikan {{ $documents[0]['is_opening_balance'] ? 'Opening Balance' : 'Invoice' }} <strong>{{ $documents[0]['no_invoice'] }}</strong> dengan rincian sebagai berikut:
                                @else
                                    Bersama ini kami sampaikan rincian tagihan yang perlu diselesaikan:
                                @endif
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-bottom:16px;">
                                <thead>
                                    <tr>
                                        <td style="padding:8px 10px;background:#f1f8e9;border:1px solid #dcedc8;font-weight:bold;">No. Dokumen</td>
                                        <td style="padding:8px 10px;background:#f1f8e9;border:1px solid #dcedc8;font-weight:bold;">Jenis</td>
                                        <td style="padding:8px 10px;background:#f1f8e9;border:1px solid #dcedc8;font-weight:bold;text-align:right;">Total</td>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($documents as $doc)
                                        <tr>
                                            <td style="padding:8px 10px;border:1px solid #e2e8f0;">
                                                <a href="{{ $doc['share_url'] }}" style="color:#2e7d32;text-decoration:none;font-weight:bold;">{{ $doc['no_invoice'] }}</a>
                                            </td>
                                            <td style="padding:8px 10px;border:1px solid #e2e8f0;">{{ $doc['is_opening_balance'] ? 'Opening Balance' : 'Invoice Reguler' }}</td>
                                            <td style="padding:8px 10px;border:1px solid #e2e8f0;text-align:right;">Rp {{ number_format($doc['subtotal'], 0, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if (count($documents) > 1)
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" style="padding:8px 10px;border:1px solid #e2e8f0;font-weight:bold;">Total Keseluruhan</td>
                                            <td style="padding:8px 10px;border:1px solid #e2e8f0;font-weight:bold;text-align:right;">Rp {{ number_format(collect($documents)->sum('subtotal'), 0, ',', '.') }}</td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>

                            <p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#475569;">
                                Klik nomor dokumen pada tabel di atas untuk mengakses dan mengunduh dokumen. Tautan berlaku selama 30 hari.
                            </p>

                            <p style="margin:0;font-size:14px;line-height:1.6;">
                                Mohon kesediaannya untuk melakukan pembayaran sesuai dengan total tagihan di atas. Atas perhatian dan kerja samanya, kami ucapkan terima kasih.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                            <span style="font-size:11px;color:#94a3b8;">Email ini dikirim otomatis dari sistem Iron. Mohon tidak membalas ke alamat pengirim ini kecuali dari PIC AR yang menangani akun Anda.</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
