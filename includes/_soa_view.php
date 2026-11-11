<?php
/**
 * Statement of Account for one booking. Rendered by event_bookings.php after its ADMIN and OWNER check.
 * Expects: $soa, $soa_items, $soa_payments, $soa_inclusions, $soa_totals, $soa_business, $soa_flash.
 */
$peso = static fn($n) => '₱' . number_format((float)$n, 2);
$total = (float)$soa['grand_total'];
$balance = round($total - $soa_totals['paid'], 2);
$pay_status = jq_payment_status($total, $soa_totals, (string)$soa['status']);
$generated = date('F d, Y h:i A');
$downs = array_values(array_filter($soa_payments, static fn($p) => $p['payment_type'] === 'Downpayment'));
$extras = array_values(array_filter($soa_payments, static fn($p) => $p['payment_type'] !== 'Downpayment'));
$pdf_data = [
    'business' => $soa_business, 'ref' => $soa['booking_ref'], 'client' => $soa['fullname'], 'event' => $soa['event_name'], 'type' => $soa['event_type'],
    'date' => date('F d, Y', strtotime($soa['event_date'])), 'guests' => number_format((int)$soa['headcount']), 'package' => (string)$soa['package_name'],
    'inclusions' => $soa_inclusions, 'items' => $soa_items, 'downs' => $downs, 'extras' => $extras,
    'total' => $total, 'paid' => $soa_totals['paid'], 'verified' => $soa_totals['verified'], 'balance' => $balance, 'status' => $pay_status, 'generated' => $generated,
    'subtotal' => (float)$soa['subtotal'], 'tax' => (float)$soa['service_tax'], 'taxRate' => (float)$soa['service_tax_rate'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statement of Account <?= htmlspecialchars($soa['booking_ref']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#EFEAFB;color:#1A1A2E;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45}
.bar{max-width:860px;margin:16px auto 0;padding:0 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn{border:1px solid #5B3FA8;background:#5B3FA8;color:#fff;padding:9px 16px;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;text-decoration:none}
.btn.alt{background:#fff;color:#5B3FA8}
.msg{max-width:860px;margin:10px auto 0;padding:9px 16px;background:#E5F4E4;border-radius:8px}
.msg.danger{background:#FBEBEB}
details{max-width:860px;margin:10px auto 0;padding:0 16px}
details summary{cursor:pointer;font-weight:600;color:#4A3290}
details form{background:#fff;padding:14px;border-radius:8px;margin-top:8px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
details label{font-size:12px;font-weight:600;display:block}
details input{width:100%;padding:8px;border:1px solid #C8B8E8;border-radius:6px;margin-top:3px}
.sheet{max-width:860px;background:#fff;margin:14px auto 30px;padding:40px 46px;box-shadow:0 2px 12px rgba(45,36,73,.14)}
header{display:flex;gap:14px;align-items:center;border-bottom:3px solid #5B3FA8;padding-bottom:14px}
header img{width:64px;height:64px;border-radius:10px;object-fit:cover}
.co{font-size:19px;font-weight:700}.co small{display:block;font-size:12px;font-weight:400;color:#4A4A6A}
h1{font-size:18px;margin:18px 0 4px;letter-spacing:.03em}
.gen{color:#4A4A6A;font-size:12.5px;margin:0 0 12px}
h2{font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:#4A3290;margin:18px 0 6px;padding-bottom:4px;border-bottom:1px solid #E2D6F0}
table{width:100%;border-collapse:collapse}
.kv th{width:30%;text-align:left;font-weight:600;color:#4A4A6A;padding:3px 8px 3px 0;vertical-align:top}.kv td{padding:3px 0}
.t th{text-align:left;background:#F8F5FC;font-size:12px;padding:6px 8px;border-bottom:1px solid #CDBDE3}.t td{padding:6px 8px;border-bottom:1px solid #E2D6F0;vertical-align:top}
.num{text-align:right!important;white-space:nowrap}
ul{margin:2px 0 8px;padding-left:20px}.inc{font-weight:600;margin-top:6px}
.none{color:#4A4A6A;margin:2px 0 6px}
.sum{margin:14px 0 0 auto;max-width:360px}.sum td{padding:4px 8px}.sum tr.big td{font-size:16px;font-weight:700;border-top:2px solid #5B3FA8}
footer{margin-top:22px;padding-top:8px;border-top:1px solid #E2D6F0;font-size:11.5px;color:#4A4A6A;text-align:center}
tr{page-break-inside:avoid}h2{page-break-after:avoid}
@media (max-width:600px){.sheet{padding:20px 16px}}
@media print{@page{size:A4;margin:14mm}body{background:#fff;font-size:12.5px}.bar,.msg,details{display:none}.sheet{box-shadow:none;margin:0;padding:0;max-width:none}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div class="bar">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <button type="button" class="btn" onclick="saveSoaPdf()">Download PDF</button>
    <a class="btn alt" href="event_bookings.php?open=<?= (int)$soa['booking_id'] ?>&amp;tab=payments">Back to Booking</a>
</div>
<?php if ($soa_flash): ?><div class="msg <?= $soa_flash['type'] === 'success' ? '' : 'danger' ?>"><?= htmlspecialchars($soa_flash['message']) ?></div><?php endif; ?>
<details>
    <summary>Edit business details on this statement</summary>
    <form method="POST" action="event_bookings.php">
        <?= jq_csrf_field() ?>
        <input type="hidden" name="booking_action" value="save_business">
        <input type="hidden" name="booking_id" value="<?= (int)$soa['booking_id'] ?>">
        <label>Business name<input name="business_name" maxlength="150" value="<?= htmlspecialchars($soa_business['name']) ?>"></label>
        <label>Address<input name="business_address" maxlength="255" value="<?= htmlspecialchars($soa_business['address']) ?>"></label>
        <label>Phone<input name="business_phone" maxlength="60" value="<?= htmlspecialchars($soa_business['phone']) ?>"></label>
        <label>Email<input type="email" name="business_email" maxlength="120" value="<?= htmlspecialchars($soa_business['email']) ?>"></label>
        <div><button type="submit" class="btn">Save Details</button></div>
    </form>
</details>
<main class="sheet">
    <header>
        <img src="static/images/logo.png" alt="">
        <div class="co"><?= htmlspecialchars($soa_business['name']) ?>
            <small><?= htmlspecialchars(implode(' | ', array_filter([$soa_business['address'], $soa_business['phone'], $soa_business['email']]))) ?></small>
        </div>
    </header>
    <h1>STATEMENT OF ACCOUNT</h1>
    <p class="gen">Date generated: <?= htmlspecialchars($generated) ?></p>
    <table class="kv">
        <tr><th>Booking reference</th><td><?= htmlspecialchars($soa['booking_ref']) ?></td></tr>
        <tr><th>Client name</th><td><?= htmlspecialchars($soa['fullname']) ?></td></tr>
        <tr><th>Event</th><td><?= htmlspecialchars($soa['event_name']) ?></td></tr>
        <tr><th>Event type</th><td><?= htmlspecialchars($soa['event_type']) ?></td></tr>
        <tr><th>Event date</th><td><?= date('F d, Y', strtotime($soa['event_date'])) ?></td></tr>
        <tr><th>Number of guests</th><td><?= number_format((int)$soa['headcount']) ?></td></tr>
        <tr><th>Selected package</th><td><?= htmlspecialchars((string)$soa['package_name']) ?></td></tr>
    </table>

    <h2>Food and Catering Inclusions</h2>
    <?php if (!$soa_inclusions): ?><p class="none">No inclusions are recorded for this booking.</p><?php endif; ?>
    <?php foreach ($soa_inclusions as $source => $list): ?>
    <div class="inc"><?= htmlspecialchars($source) ?></div>
    <ul><?php foreach ($list as $text): ?><li><?= htmlspecialchars($text) ?></li><?php endforeach; ?></ul>
    <?php endforeach; ?>

    <h2>Additional Add-ons</h2>
    <?php if (!$soa_items): ?><p class="none">None.</p><?php else: ?>
    <table class="t"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($soa_items as $it): ?>
        <tr><td><?= htmlspecialchars($it['item_name']) ?></td><td class="num"><?= (int)$it['quantity'] ?></td><td class="num"><?= $peso($it['unit_price']) ?><?= $it['price_mode'] === 'per_guest' ? ' / guest' : '' ?></td><td class="num"><?= $peso($it['line_total']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <?php foreach (['Downpayment Records' => $downs, 'Additional Payment Records' => $extras] as $title => $rows): ?>
    <h2><?= $title ?></h2>
    <?php if (!$rows): ?><p class="none">None recorded.</p><?php else: ?>
    <table class="t"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Verification</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($rows as $p): ?>
        <tr><td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td><td><?= htmlspecialchars($p['payment_method']) ?></td><td><?= htmlspecialchars((string)$p['reference_no']) ?></td><td><?= htmlspecialchars($p['verification_status']) ?></td><td class="num"><?= $peso($p['amount']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php endforeach; ?>

    <table class="sum">
        <?php if ((float)$soa['service_tax'] > 0): ?>
        <tr><td>Subtotal</td><td class="num"><?= $peso($soa['subtotal']) ?></td></tr>
        <tr><td>Service tax (<?= (float)$soa['service_tax_rate'] ?>%)</td><td class="num"><?= $peso($soa['service_tax']) ?></td></tr>
        <?php endif; ?>
        <tr><td><strong>Total booking price</strong></td><td class="num"><strong><?= $peso($total) ?></strong></td></tr>
        <tr><td>Total amount recorded</td><td class="num"><?= $peso($soa_totals['paid']) ?></td></tr>
        <tr><td>Verified by receipt</td><td class="num"><?= $peso($soa_totals['verified']) ?></td></tr>
        <tr class="big"><td>Remaining balance</td><td class="num"><?= $peso($balance) ?></td></tr>
        <tr><td>Payment status</td><td class="num"><?= htmlspecialchars($pay_status) ?></td></tr>
    </table>
    <footer><?= htmlspecialchars($soa_business['name']) ?>. This statement reflects payments recorded as of the date generated.</footer>
</main>
<script src="static/js/vendor/jspdf.umd.min.js"></script>
<script>
(function () { var t = <?= json_encode(jq_csrf_token()) ?>, f = window.fetch; window.fetch = function (u, o) { o = o || {}; o.headers = new Headers(o.headers || {}); o.headers.set('X-CSRF-Token', t); return f(u, o); }; })();
</script>
<script src="static/js/idle-logout.js" data-limit="<?= (int)JQ_SESSION_IDLE_SECONDS ?>" data-warn="120" data-ping="ajax/session_ping.php" data-logout="logout.php?reason=idle" defer></script>
<script>
const SOA = <?= json_encode($pdf_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
/* jsPDF's built-in fonts cover Western European letters. Other symbols get a plain equivalent. */
function pt(v) {
    return String(v == null ? '' : v).replace(/₱/g, 'PHP ').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/[–—]/g, '-').replace(/•/g, '-').replace(/[^\n\x20-\x7E -ÿ]/g, '?');
}
const money = n => 'PHP ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function saveSoaPdf() {
    const doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' });
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 46, right = W - M;
    let y = M;
    const room = n => { if (y + n > H - 46) { doc.addPage(); y = M; } };
    const font = (size, style, c) => { doc.setFont('helvetica', style || 'normal'); doc.setFontSize(size); doc.setTextColor.apply(doc, c || [30, 30, 46]); };
    const line = (t, size, style, c, lead) => { font(size, style, c); doc.splitTextToSize(pt(t), right - M).forEach(l => { room(lead); doc.text(l, M, y); y += lead; }); };
    const head = t => { y += 8; room(30); font(10, 'bold', [74, 50, 144]); doc.text(pt(t).toUpperCase(), M, y); y += 5; doc.setDrawColor(200, 184, 232); doc.line(M, y, right, y); y += 13; };
    const kv = (k, v) => { font(10, 'bold', [74, 74, 106]); room(15); doc.text(pt(k), M, y); font(10); const w = doc.splitTextToSize(pt(v), right - M - 130); w.forEach((l, i) => { if (i) room(13); doc.text(l, M + 130, y); y += 13; }); y += 2; };
    const row = (cols, pos, bold) => { font(9.5, bold ? 'bold' : 'normal'); const first = doc.splitTextToSize(pt(cols[0]), pos[1] - pos[0] - 8); room(first.length * 12 + 3); first.forEach((l, i) => doc.text(l, pos[0], y + i * 12)); for (let i = 1; i < cols.length; i++) doc.text(pt(cols[i]), pos[i], y, { align: i === cols.length - 1 || pos.right ? 'right' : 'left' }); y += first.length * 12 + 3; };
    line(SOA.business.name, 15, 'bold', [45, 36, 73], 19);
    const contact = [SOA.business.address, SOA.business.phone, SOA.business.email].filter(Boolean).join(' | ');
    if (contact) line(contact, 9, 'normal', [74, 74, 106], 12);
    y += 3; doc.setDrawColor(91, 63, 168); doc.setLineWidth(2); doc.line(M, y, right, y); doc.setLineWidth(.6); y += 20;
    line('STATEMENT OF ACCOUNT', 13, 'bold', [30, 30, 46], 16);
    line('Date generated: ' + SOA.generated, 9, 'normal', [74, 74, 106], 15);
    [['Booking reference', SOA.ref], ['Client name', SOA.client], ['Event', SOA.event], ['Event type', SOA.type], ['Event date', SOA.date], ['Number of guests', SOA.guests], ['Selected package', SOA.package]].forEach(r => kv(r[0], r[1]));
    head('Food and Catering Inclusions');
    const groups = Object.keys(SOA.inclusions);
    if (!groups.length) line('No inclusions are recorded for this booking.', 10, 'normal', [74, 74, 106], 14);
    groups.forEach(g => { line(g, 10, 'bold', [30, 30, 46], 14); SOA.inclusions[g].forEach(t => line('-  ' + t, 9.5, 'normal', [30, 30, 46], 12.5)); y += 3; });
    head('Additional Add-ons');
    if (!SOA.items.length) line('None.', 10, 'normal', [74, 74, 106], 14);
    else { const pos = [M, right - 190, right - 90, right]; row(['Item', 'Qty', 'Unit price', 'Amount'], pos, true); SOA.items.forEach(i => row([i.item_name, String(i.quantity), money(i.unit_price) + (i.price_mode === 'per_guest' ? '/guest' : ''), money(i.line_total)], pos)); }
    [['Downpayment Records', SOA.downs], ['Additional Payment Records', SOA.extras]].forEach(([title, list]) => {
        head(title);
        if (!list.length) { line('None recorded.', 10, 'normal', [74, 74, 106], 14); return; }
        const pos = [M, M + 85, M + 180, M + 300, right];
        row(['Date', 'Method', 'Reference', 'Verification', 'Amount'], pos, true);
        list.forEach(p => row([p.payment_date, p.payment_method, p.reference_no || '', p.verification_status, money(p.amount)], pos));
    });
    y += 8;
    const sum = [];
    if (SOA.tax > 0) { sum.push(['Subtotal', money(SOA.subtotal)], ['Service tax (' + SOA.taxRate + '%)', money(SOA.tax)]); }
    sum.push(['Total booking price', money(SOA.total), 1], ['Total amount recorded', money(SOA.paid)], ['Verified by receipt', money(SOA.verified)], ['Remaining balance', money(SOA.balance), 1], ['Payment status', SOA.status]);
    sum.forEach(r => { room(16); font(10.5, r[2] ? 'bold' : 'normal'); doc.text(r[0], right - 230, y); doc.text(pt(r[1]), right, y, { align: 'right' }); y += 15; });
    const n = doc.internal.getNumberOfPages();
    for (let p = 1; p <= n; p++) { doc.setPage(p); font(8, 'normal', [119, 119, 119]); doc.text('Statement of Account ' + pt(SOA.ref), M, H - 24); doc.text('Page ' + p + ' of ' + n, right, H - 24, { align: 'right' }); }
    doc.save('SOA_' + SOA.ref.replace(/[^A-Za-z0-9]+/g, '_') + '.pdf');
}
</script>
</body>
</html>
