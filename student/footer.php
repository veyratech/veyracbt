<?php
/**
 * Student Portal Footer — ID Card Modal (CSP-compliant)
 */
$idConfig = file_exists(__DIR__ . '/../config/idcard.php') ? require __DIR__ . '/../config/idcard.php' : [];
$primaryColor   = $idConfig['primary_color'] ?? '#0f50b8';
$secondaryColor = $idConfig['secondary_color'] ?? '#0ca0df';
$cardTitle      = $idConfig['card_title'] ?? 'KARTU PESERTA UJIAN';
$academicYear   = $idConfig['academic_year'] ?? (date('Y') . ' / ' . (date('Y') + 1));
$rules          = $idConfig['rules'] ?? [
    'Wajib membawa kartu ujian ini selama pelaksanaan asesmen.',
    'Dilarang membawa catatan, kalkulator, gawai lain, atau membuka tab lain.',
    'Hadir di ruang ujian selambat-lambatnya 15 menit sebelum waktu dimulai.',
    'Wajib mematuhi seluruh instruksi pengawas ruang & proktor.',
];
$contactPhone   = $idConfig['contact_phone'] ?? '';
$signerTitle    = $idConfig['signer_title'] ?? 'Panitia Ujian';
$signerName     = $idConfig['signer_name'] ?? 'Proktor / Panitia Pelaksana';

// Barcode SVG
$nisCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $student['nis'] ?? '123456')) ?: '123456';
$bars = ''; $xPos = 6;
for ($bi = 0; $bi < strlen($nisCode); $bi++) {
    $val = ord($nisCode[$bi]) % 5 + 1;
    $bw  = ($val % 2 === 0) ? 2 : 1;
    $bars .= "<rect x='{$xPos}' y='1' width='{$bw}' height='20' fill='#1e293b' />";
    $xPos += $bw + 2;
    $bw2 = (($val + 1) % 2 === 0) ? 2.5 : 1;
    $bars .= "<rect x='{$xPos}' y='1' width='{$bw2}' height='20' fill='#1e293b' />";
    $xPos += $bw2 + 2;
}
$barcodeSvg = "<svg viewBox='0 0 {$xPos} 22' style='height:36px;width:auto;display:block;margin:0 auto;' xmlns='http://www.w3.org/2000/svg'>{$bars}</svg><div style='text-align:center;font-size:9px;color:#64748b;font-family:monospace;margin-top:2px;'>*" . htmlspecialchars($student['nis'] ?? '-') . "*</div>";

$schoolLogo = !empty($school['logo_url']) ? '../' . ltrim(str_replace('\\', '/', $school['logo_url']), '/') : null;
if ($schoolLogo && !file_exists(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $school['logo_url']), '/'))) $schoolLogo = null;
$stuPhoto  = !empty($student['photo']) ? '/' . ltrim($student['photo'], '/') : null;
$hasPhoto  = $stuPhoto && file_exists(__DIR__ . $stuPhoto);
$initials  = mb_substr($student['name'] ?? 'S', 0, 1);

// Encode card data as JSON for print button (CSP-safe: no inline JS)
$cardDataJson = json_encode([
    'primary'    => $primaryColor,
    'secondary'  => $secondaryColor,
    'name'       => htmlspecialchars($student['name'] ?? 'Siswa'),
    'nis'        => htmlspecialchars($student['nis'] ?? '-'),
    'class'      => htmlspecialchars($student['class_name'] ?? 'Umum'),
    'school'     => htmlspecialchars($school['name'] ?? 'CBT Online'),
    'year'       => htmlspecialchars($academicYear),
    'title'      => htmlspecialchars($cardTitle),
    'barcode'    => $barcodeSvg,
    'photo'      => $hasPhoto ? htmlspecialchars($stuPhoto) : '',
    'initials'   => $initials,
    'contact'    => htmlspecialchars($contactPhone),
    'signerTitle'=> htmlspecialchars($signerTitle),
    'signerName' => htmlspecialchars($signerName),
    'rules'      => array_map(function($r){ return htmlspecialchars($r); }, $rules),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>

<!-- ── ID Card Modal ── -->
<div id="modalIdCard" style="display:none;position:fixed;inset:0;background:rgba(5,10,30,.82);backdrop-filter:blur(12px);z-index:9999;align-items:center;justify-content:center;padding:20px;">
<style>
@keyframes shimmer{0%{background-position:-200% center}100%{background-position:200% center}}
@keyframes floatIn{from{transform:scale(.92) translateY(20px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
@keyframes glow{0%,100%{box-shadow:0 0 20px rgba(14,165,233,.2)}50%{box-shadow:0 0 40px rgba(14,165,233,.35)}}
.pc-w{animation:floatIn .3s cubic-bezier(.34,1.56,.64,1);}
.pc-i{background:linear-gradient(160deg,#0a1628 0%,#0d2137 40%,#0a1628 100%);border-radius:24px;width:100%;max-width:720px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.5);animation:glow 4s ease-in-out infinite;position:relative;}
.pc-i::before{content:'';position:absolute;inset:0;border-radius:24px;padding:1px;background:linear-gradient(160deg,rgba(255,255,255,.15) 0%,transparent 50%,rgba(14,165,233,.1) 100%);-webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);-webkit-mask-composite:xor;mask-composite:exclude;pointer-events:none;}
.pc-hdr{display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1px solid rgba(255,255,255,.07);position:relative;z-index:2;}
.pc-ttl{font-size:14px;font-weight:800;color:#f1f5f9;letter-spacing:.5px;text-transform:uppercase;display:flex;align-items:center;gap:10px;}
.pc-dot{width:8px;height:8px;border-radius:50%;background:linear-gradient(135deg,#0ea5e9,#3b82f6);box-shadow:0 0 8px rgba(14,165,233,.6);}
.pc-clse{width:32px;height:32px;border-radius:10px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);color:#94a3b8;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.pc-clse:hover{background:rgba(255,255,255,.1);color:#fff;}
.pc-row{display:flex;gap:28px;padding:28px;flex-wrap:wrap;justify-content:center;position:relative;z-index:2;}
.pc-lbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1.5px;text-align:center;margin-bottom:12px;}
.pc-cd{width:340px;height:220px;border-radius:16px;overflow:hidden;position:relative;background:#0d1f3c;border:1px solid rgba(255,255,255,.08);box-shadow:0 8px 32px rgba(0,0,0,.4);display:flex;flex-direction:column;justify-content:space-between;transition:transform .3s cubic-bezier(.34,1.56,.64,1),box-shadow .3s;}
.pc-cd:hover{transform:translateY(-4px) scale(1.01);box-shadow:0 16px 48px rgba(0,0,0,.5);}
.pc-cd::before{content:'';position:absolute;inset:0;border-radius:16px;z-index:1;pointer-events:none;background:linear-gradient(135deg,rgba(255,255,255,.03) 0%,transparent 40%,rgba(14,165,233,.04) 60%,transparent 100%);}
.pc-wave{height:82px;background:linear-gradient(135deg,<?= $primaryColor ?> 0%,#0ea5e9 50%,<?= $secondaryColor ?> 100%);position:relative;overflow:hidden;}
.pc-wave::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 30% 0%,rgba(255,255,255,.2) 0%,transparent 60%),radial-gradient(ellipse at 80% 100%,rgba(255,255,255,.08) 0%,transparent 40%);}
.pc-wave::after{content:'';position:absolute;bottom:0;left:0;right:0;height:30px;background:linear-gradient(to top,#0d1f3c,transparent);opacity:.6;}
.pc-wpatt{position:absolute;inset:0;opacity:.08;background-image:repeating-linear-gradient(45deg,transparent,transparent 3px,rgba(255,255,255,.5) 3px,rgba(255,255,255,.5) 4px),repeating-linear-gradient(-45deg,transparent,transparent 3px,rgba(255,255,255,.5) 3px,rgba(255,255,255,.5) 4px);}
.pc-badge{position:absolute;top:10px;right:12px;background:rgba(255,255,255,.15);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:3px 8px;font-size:7px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
.pc-photo-ring{position:absolute;top:50px;left:50%;transform:translateX(-50%);width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,#0d1f3c);border:2.5px solid rgba(255,255,255,.25);box-shadow:0 0 20px rgba(14,165,233,.3),0 4px 16px rgba(0,0,0,.4);overflow:hidden;display:flex;align-items:center;justify-content:center;z-index:5;}
.pc-photo-ring img{width:100%;height:100%;object-fit:cover;}
.pc-pinit{font-size:20px;font-weight:900;color:rgba(255,255,255,.5);}
.pc-fbody{margin-top:34px;padding:0 14px;text-align:center;flex:1;display:flex;flex-direction:column;justify-content:space-between;position:relative;z-index:2;}
.pc-name{font-size:11px;font-weight:800;color:#f1f5f9;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pc-role-tag{display:inline-block;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);border-radius:999px;padding:2px 10px;font-size:6.5px;font-weight:800;color:#38bdf8;text-transform:uppercase;letter-spacing:1.2px;margin-top:4px;}
.pc-grid{display:grid;grid-template-columns:1fr 1fr;gap:3px 8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:5px 7px;margin:4px auto 0;width:100%;}
.pc-cell{display:flex;flex-direction:column;align-items:center;text-align:center;}
.pc-lbl2{font-size:5.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;}
.pc-val{font-size:8px;font-weight:700;color:#e2e8f0;margin-top:1px;}
.pc-val.pw2{color:#f87171;font-family:monospace;letter-spacing:1px;}
.pc-bar{display:flex;flex-direction:column;align-items:center;margin-top:2px;}
.pc-wbot{height:16px;background:linear-gradient(90deg,<?= $primaryColor ?>,<?= $secondaryColor ?>,<?= $primaryColor ?>);background-size:200% 100%;animation:shimmer 3s linear infinite;display:flex;align-items:center;justify-content:center;position:relative;}
.pc-wbot::before{content:'';position:absolute;inset:0;background:repeating-linear-gradient(90deg,transparent,transparent 2px,rgba(255,255,255,.06) 2px,rgba(255,255,255,.06) 4px);}
.pc-wtxt{font-size:6px;font-weight:800;color:rgba(255,255,255,.9);letter-spacing:1.5px;text-transform:uppercase;position:relative;z-index:1;}
/* Back */
.pc-bbody{padding:14px 16px;height:100%;display:flex;flex-direction:column;position:relative;z-index:2;}
.pc-btopbar{height:4px;border-radius:2px;margin:-14px -16px 10px -16px;background:linear-gradient(90deg,<?= $primaryColor ?>,<?= $secondaryColor ?>);}
.pc-bttl{font-size:8px;font-weight:800;color:#f1f5f9;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid rgba(255,255,255,.08);}
.pc-rules{list-style:none;padding:0;margin:0 0 8px 0;}
.pc-rules li{font-size:6.5px;color:#94a3b8;line-height:1.5;padding-left:8px;position:relative;margin-bottom:3px;}
.pc-rules li::before{content:'›';position:absolute;left:0;color:#0ea5e9;font-weight:700;}
.pc-sig{text-align:center;margin-top:auto;padding-top:8px;border-top:1px dashed rgba(255,255,255,.08);}
.pc-sigt{font-size:6px;color:#64748b;margin-bottom:6px;}
.pc-signame{font-size:8px;font-weight:800;color:#f1f5f9;text-decoration:underline;text-underline-offset:3px;}
.pc-footer{display:flex;gap:10px;padding:18px 28px 22px;border-top:1px solid rgba(255,255,255,.07);justify-content:flex-end;position:relative;z-index:2;}
.pc-btn{padding:10px 22px;border-radius:12px;font-size:13px;font-weight:700;cursor:pointer;border:none;font-family:inherit;display:inline-flex;align-items:center;gap:7px;transition:all .2s cubic-bezier(.34,1.56,.64,1);}
.pc-btn:hover{transform:translateY(-2px);}
.pc-print{background:linear-gradient(135deg,#0284c7,#0ea5e9);color:#fff;box-shadow:0 4px 16px rgba(2,132,199,.35);}
.pc-print:hover{box-shadow:0 8px 24px rgba(2,132,199,.5);background:linear-gradient(135deg,#0369a1,#0284c7);}
.pc-close{background:rgba(255,255,255,.06);color:#94a3b8;border:1px solid rgba(255,255,255,.1);}
.pc-close:hover{background:rgba(255,255,255,.1);color:#e2e8f0;}
@media(max-width:768px){.pc-row{flex-direction:column;align-items:center;}.pc-cd{width:100%;max-width:340px;}}
</style>

<div class="pc-w">
<div class="pc-i">
    <div class="pc-hdr">
        <div class="pc-ttl"><span class="pc-dot"></span>Kartu Peserta Ujian</div>
        <button type="button" class="pc-clse" data-close-idcard>&times;</button>
    </div>
    <div class="pc-row">
        <!-- FRONT -->
        <div>
            <div class="pc-lbl">Depan</div>
            <div class="pc-cd">
                <div class="pc-wave">
                    <div class="pc-wpatt"></div>
                    <?php if ($schoolLogo): ?><img src="<?= htmlspecialchars($schoolLogo) ?>" alt="" style="position:absolute;top:50%;left:10px;transform:translateY(-50%);width:30px;height:30px;object-fit:contain;border-radius:6px;opacity:.85;filter:brightness(1.2);"><?php endif; ?>
                    <div class="pc-badge"><?= htmlspecialchars($school['name'] ?? 'CBT Online') ?></div>
                </div>
                <div class="pc-photo-ring">
                    <?php if ($hasPhoto): ?><img src="<?= Helper::cacheBust(htmlspecialchars($stuPhoto)) ?>" alt=""><?php else: ?><span class="pc-pinit"><?= $initials ?></span><?php endif; ?>
                </div>
                <div class="pc-fbody">
                    <div>
                        <div class="pc-name"><?= htmlspecialchars($student['name'] ?? 'Siswa') ?></div>
                        <div class="pc-role-tag">PESERTA UJIAN CBT</div>
                    </div>
                    <div class="pc-grid">
                        <div class="pc-cell"><span class="pc-lbl2">NIS</span><span class="pc-val"><?= htmlspecialchars($student['nis'] ?? '-') ?></span></div>
                        <div class="pc-cell"><span class="pc-lbl2">Kelas</span><span class="pc-val"><?= htmlspecialchars($student['class_name'] ?? 'Umum') ?></span></div>
                        <div class="pc-cell"><span class="pc-lbl2">Sandi</span><span class="pc-val pw2">&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;</span></div>
                        <div class="pc-cell"><span class="pc-lbl2">Tahun</span><span class="pc-val"><?= htmlspecialchars($academicYear) ?></span></div>
                    </div>
                    <div class="pc-bar"><?= $barcodeSvg ?></div>
                </div>
                <div class="pc-wbot"><span class="pc-wtxt"><?= htmlspecialchars($cardTitle) ?></span></div>
            </div>
        </div>
        <!-- BACK -->
        <div>
            <div class="pc-lbl">Belakang</div>
            <div class="pc-cd">
                <div class="pc-bbody">
                    <div class="pc-btopbar"></div>
                    <div>
                        <div class="pc-bttl">Tata Tertib Ujian</div>
                        <ul class="pc-rules">
                            <?php foreach (array_slice($rules, 0, 4) as $rule): ?><li><?= htmlspecialchars($rule) ?></li><?php endforeach; ?>
                        </ul>
                        <?php if (!empty($contactPhone)): ?>
                        <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:6px;padding:5px 7px;font-size:6.5px;color:#64748b;margin-top:4px;">
                            <strong>Layanan:</strong> <?= htmlspecialchars($contactPhone) ?> &bull; <strong>Tahun:</strong> <?= htmlspecialchars($academicYear) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="pc-sig">
                        <div class="pc-sigt"><?= htmlspecialchars($signerTitle) ?>,</div>
                        <div class="pc-signame"><?= htmlspecialchars($signerName) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="pc-footer">
        <button type="button" class="pc-btn pc-close" data-close-idcard>Tutup</button>
        <button type="button" class="pc-btn pc-print" data-print-idcard data-card-data='<?= $cardDataJson ?>'>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Cetak Kartu
        </button>
    </div>
</div>
</div>
</div>

<script src="../assets/js/student-portal.js?v=<?= filemtime(__DIR__.'/../assets/js/student-portal.js') ?>&t=<?= time() ?>"></script>
<script src="../assets/js/pwa.js?v=<?= filemtime(__DIR__.'/../assets/js/pwa.js') ?>&t=<?= time() ?>"></script>
</body>
</html>