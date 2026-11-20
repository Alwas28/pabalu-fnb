<x-app-layout title="Dokumentasi API" subtitle="API kasir v1 untuk aplikasi Android · khusus super admin">
<style>
.apidoc { --d-get: #10b981; --d-get-soft: rgba(16,185,129,.14); --d-post: var(--ac); --d-post-soft: var(--ac-lt); --d-warn: #f59e0b; --d-warn-soft: rgba(245,158,11,.12); font-size: 14.5px; line-height: 1.6; color: var(--text); }
.apidoc .wrap { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 36px; }
@media (max-width: 1024px) { .apidoc .wrap { grid-template-columns: minmax(0, 1fr); gap: 16px; } }
.apidoc nav.toc { position: sticky; top: 12px; align-self: start; max-height: calc(100vh - 120px); overflow-y: auto; font-size: 13px; }
.apidoc nav.toc summary { display: none; }
.apidoc nav.toc ol { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 1px; }
.apidoc nav.toc a { display: block; padding: 4px 10px; border-radius: 6px; color: var(--sub); text-decoration: none; }
.apidoc nav.toc a:hover { color: var(--text); background: var(--surface2); }
.apidoc nav.toc .grp { margin-top: 12px; padding: 0 10px 3px; font-size: 10.5px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); font-weight: 700; }
@media (max-width: 1024px) { .apidoc nav.toc { position: static; max-height: none; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 4px 8px; } .apidoc nav.toc summary { display: block; cursor: pointer; padding: 8px 6px; font-weight: 600; } }
.apidoc main { min-width: 0; }
.apidoc header.top { padding-bottom: 22px; border-bottom: 1px solid var(--border); }
.apidoc .eyebrow { font: 600 11.5px/1 ui-monospace, Consolas, monospace; color: var(--ac); letter-spacing: .04em; }
.apidoc .doc-title { font-size: 28px; margin: 8px 0 8px; font-weight: 700; }
.apidoc h2 { font-size: 21px; font-weight: 700; margin: 0 0 10px; }
.apidoc h3 { font-size: 16px; font-weight: 700; margin: 24px 0 6px; }
.apidoc p, .apidoc li { max-width: 74ch; color: var(--sub); }
.apidoc p b, .apidoc li b { color: var(--text); }
.apidoc ul { list-style: disc; padding-left: 20px; display: flex; flex-direction: column; gap: 4px; }
.apidoc p { margin: 8px 0 0; }
.apidoc .lede { font-size: 15px; margin: 0; }
.apidoc section { padding-top: 36px; scroll-margin-top: 12px; }
.apidoc a { color: var(--ac); }
.apidoc code { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: .86em; background: var(--surface2); border: 1px solid var(--border); padding: 0 5px; border-radius: 4px; color: var(--text); }
.apidoc pre { margin: 10px 0 0; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; overflow-x: auto; font: 12.5px/1.55 ui-monospace, "Cascadia Mono", Consolas, monospace; color: var(--text); }
.apidoc pre code { background: none; border: 0; padding: 0; font-size: inherit; }
.apidoc .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 18px; }
.apidoc .fact { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
.apidoc .fact b { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); margin-bottom: 3px; }
.apidoc .fact span { font: 12.5px ui-monospace, Consolas, monospace; word-break: break-all; }
.apidoc .note { border-left: 3px solid var(--d-warn); background: var(--d-warn-soft); padding: 9px 13px; border-radius: 0 8px 8px 0; margin: 12px 0; max-width: 78ch; }
.apidoc .note p { margin: 0; }
.apidoc .ep { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-top: 14px; }
.apidoc .ep-head { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.apidoc .method { font: 700 11.5px/1 ui-monospace, Consolas, monospace; padding: 5px 8px; border-radius: 5px; }
.apidoc .method.get { color: var(--d-get); background: var(--d-get-soft); }
.apidoc .method.post { color: var(--d-post); background: var(--d-post-soft); }
.apidoc .path { font: 600 14px/1.4 ui-monospace, Consolas, monospace; word-break: break-all; color: var(--text); }
.apidoc .tag { margin-left: auto; font-size: 11.5px; color: var(--sub); border: 1px solid var(--border); border-radius: 99px; padding: 2px 10px; white-space: nowrap; }
.apidoc .ep h4 { margin: 14px 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); font-weight: 700; }
.apidoc .tbl-wrap { overflow-x: auto; margin-top: 8px; }
.apidoc table { border-collapse: collapse; width: 100%; font-size: 13px; }
.apidoc th, .apidoc td { text-align: left; vertical-align: top; padding: 7px 10px; border-bottom: 1px solid var(--border); color: var(--sub); }
.apidoc th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.apidoc td:first-child { font: 12px ui-monospace, Consolas, monospace; white-space: nowrap; color: var(--text); }
.apidoc td .req { color: var(--ac); font-family: inherit; font-size: 10.5px; margin-left: 4px; }
.apidoc .flow { counter-reset: step; list-style: none; padding: 0; margin: 12px 0 0; display: flex; flex-direction: column; gap: 8px; }
.apidoc .flow li { counter-increment: step; display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 10px; }
.apidoc .flow li::before { content: counter(step); font: 700 12px/26px ui-monospace, Consolas, monospace; text-align: center; width: 26px; height: 26px; border-radius: 50%; background: var(--ac-lt); color: var(--ac); }
</style>
<div class="apidoc">
<div class="wrap">
  <nav class="toc" aria-label="Daftar isi">
    <details open id="toc-details">
      <summary>Daftar isi</summary>
      <ol>
        <li class="grp">Dasar</li>
        <li><a href="#mulai">Mulai di sini</a></li>
        <li><a href="#konvensi">Format &amp; error</a></li>
        <li><a href="#alur">Alur aplikasi</a></li>
        <li><a href="#hitung">Rumus total</a></li>
        <li class="grp">Endpoint</li>
        <li><a href="#auth">Login &amp; profil</a></li>
        <li><a href="#outlet">Outlet &amp; menu</a></li>
        <li><a href="#shift">Shift kasir</a></li>
        <li><a href="#pesanan">Pesanan</a></li>
        <li><a href="#open-bill">Open bill</a></li>
        <li><a href="#refund">Void &amp; refund</a></li>
        <li><a href="#cetak">Struk &amp; tiket dapur</a></li>
        <li><a href="#pelanggan">Pelanggan</a></li>
        <li><a href="#meja">Meja</a></li>
        <li class="grp">Offline</li>
        <li><a href="#offline">Cara kerja offline</a></li>
        <li><a href="#perangkat">Daftar perangkat</a></li>
        <li><a href="#bundle">Unduh bundle</a></li>
        <li><a href="#sync">Sinkronisasi</a></li>
        <li><a href="#android">Saran implementasi</a></li>
        <li class="grp">Referensi</li>
        <li><a href="#enum">Nilai kode</a></li>
      </ol>
    </details>
  </nav>

  <main>
    <header class="top">
      <div class="eyebrow">REST · JSON · v1</div>
      <h2 class="doc-title">API Kasir Pabalu v1</h2>
      <p class="lede">Referensi untuk developer aplikasi kasir Android Pabalu F&amp;B: login kasir, shift, pesanan, open bill, cetak struk ESC/POS, dan mode offline dengan sinkronisasi.</p>
      <div class="facts">
        <div class="fact"><b>Base URL</b><span>{{ url('/api/v1') }}</span></div>
        <div class="fact"><b>Autentikasi</b><span>Authorization: Bearer &lt;token&gt;</span></div>
        <div class="fact"><b>Header wajib</b><span>Accept: application/json</span></div>
        <div class="fact"><b>Uang</b><span>integer rupiah (25000 = Rp 25.000)</span></div>
      </div>
    </header>

    <section id="mulai">
      <h2>Mulai di sini</h2>
      <p>Semua endpoint selain login butuh token dari <code>POST /auth/login</code>. Token terikat ke satu akun kasir dan berlaku sampai logout atau dicabut. Akun hanya bisa login bila punya izin <code>pos.use</code> di bisnisnya.</p>
      <p>Setiap pengguna punya satu bisnis aktif dan hanya bisa mengakses outlet yang ditugaskan kepadanya. Outlet, pesanan, atau shift di luar itu dijawab <code>403</code>.</p>
      <p>Server selalu menghitung ulang harga, pajak, service charge, dan total untuk transaksi online. Aplikasi cukup mengirim ID menu, varian, modifier, dan jumlah. Harga dari aplikasi hanya dipakai pada sinkronisasi offline.</p>
    </section>

    <section id="konvensi">
      <h2>Format &amp; error</h2>
      <ul>
        <li>Request dan response berformat JSON. Kirim <code>Content-Type: application/json</code> pada POST.</li>
        <li>Waktu dari server berformat ISO 8601 dalam UTC, misalnya <code>2026-11-19T01:35:00+00:00</code>. Tampilkan dalam zona waktu bisnis (<code>business.timezone</code>, misalnya <code>Asia/Makassar</code>).</li>
        <li>Waktu yang dikirim aplikasi (offline) harus ISO 8601 dengan offset, misalnya <code>2026-11-19T09:35:00+08:00</code>.</li>
        <li>Data utama ada di kunci <code>data</code>. Daftar berhalaman memakai format paginasi Laravel (<code>data</code>, <code>links</code>, <code>meta</code>).</li>
      </ul>
      <div class="tbl-wrap"><table>
        <thead><tr><th>Status</th><th>Arti</th><th>Bentuk body</th></tr></thead>
        <tbody>
          <tr><td>401</td><td>Token tidak ada, salah, atau sudah dicabut. Arahkan ke layar login.</td><td><code>{"message": "Unauthenticated."}</code></td></tr>
          <tr><td>403</td><td>Tidak punya izin, outlet bukan tugasnya, perangkat dicabut, atau mode offline belum aktif.</td><td><code>{"message": "…"}</code></td></tr>
          <tr><td>404</td><td>Data tidak ditemukan.</td><td><code>{"message": "…"}</code></td></tr>
          <tr><td>409</td><td><code>client_reference</code> sudah dipakai di outlet lain.</td><td><code>{"message": "…"}</code></td></tr>
          <tr><td>422</td><td>Validasi gagal. Pesan sudah dalam Bahasa Indonesia dan aman ditampilkan ke kasir.</td><td><code>{"message": "…", "errors": {"field": ["…"]}}</code></td></tr>
          <tr><td>429</td><td>Terlalu banyak percobaan (login: 5 kali per menit per email + IP).</td><td><code>{"message": "…"}</code></td></tr>
        </tbody>
      </table></div>
      <div class="note"><p>Error 422 dengan kunci <code>shift</code> berarti kasir belum membuka shift. Tampilkan layar buka shift.</p></div>
    </section>

    <section id="alur">
      <h2>Alur aplikasi</h2>
      <ol class="flow">
        <li><div><b>Login.</b> <code>POST /auth/login</code>, simpan token. Respons berisi role, izin, dan outlet yang boleh dipakai.</div></li>
        <li><div><b>Pilih outlet</b> lalu muat konfigurasinya: <code>GET /outlets/{id}</code> (layanan, meja, dapur, pajak, metode bayar) dan <code>GET /outlets/{id}/menu</code>.</div></li>
        <li><div><b>Cek shift.</b> <code>GET /outlets/{id}/shift</code>. Jika <code>data</code> null, minta modal awal lalu <code>POST /outlets/{id}/shifts</code>.</div></li>
        <li><div><b>Berjualan.</b> <code>POST /outlets/{id}/orders</code> untuk bayar langsung atau open bill dine-in (<code>pay_later: true</code>).</div></li>
        <li><div><b>Cetak.</b> <code>GET /orders/{id}/receipt</code> memberi byte ESC/POS siap kirim ke printer thermal, ditambah URL struk web untuk QR.</div></li>
        <li><div><b>Tutup shift.</b> Hitung uang di laci lalu <code>POST /shifts/{id}/close</code>.</div></li>
      </ol>
      <p>Jika outlet mengaktifkan mode offline, tambahkan pendaftaran perangkat dan unduh bundle setelah langkah 2. Lihat <a href="#offline">Cara kerja offline</a>.</p>
    </section>

    <section id="hitung">
      <h2>Rumus total</h2>
      <p>Aplikasi perlu rumus ini untuk menampilkan total sebelum dikirim, dan wajib memakainya saat offline. Pengaturannya ada di <code>config.tax</code> dan <code>config.service_charge</code> pada data outlet.</p>
<pre><code>diskon   = amount  → round(nilai)
           percent → round(subtotal × min(nilai,100) / 100)
           lalu dibatasi 0 … subtotal
dasar    = subtotal − diskon
service  = round(dasar × service_rate / 100)        // 0 bila service tidak aktif

pajak tidak termasuk harga (prices_include_tax = false):
  pajak  = round((dasar + service) × tax_rate / 100)
  total  = dasar + service + pajak

pajak termasuk harga (prices_include_tax = true):
  total  = dasar + service
  pajak  = total − round(total × 100 / (100 + tax_rate))   // hanya informasi di struk</code></pre>
      <p><code>round</code> membulatkan ke rupiah terdekat, setengah dibulatkan menjauhi nol (<code>RoundingMode.HALF_UP</code> di Kotlin/Java). Contoh: subtotal 24.000, service 5%, pajak 10% tidak termasuk harga → service 1.200, pajak 2.520, total 27.720.</p>
      <p><b>Harga per porsi</b> = harga menu di outlet (<code>price</code>, atau <code>variants[].price</code> bila memilih varian) + jumlah <code>price</code> modifier yang dipilih.</p>
      <p><b>Pembayaran:</b> metode non-tunai tidak boleh melebihi total. Tunai menutup sisanya, dan kelebihannya menjadi kembalian.</p>
    </section>

    <section id="auth">
      <h2>Login &amp; profil</h2>

      <div class="ep" id="ep-login">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/auth/login</span><span class="tag">tanpa token</span></div>
        <p>Login kasir dan dapatkan token. Respons 201.</p>
        <h4>Body</h4>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>email<span class="req">wajib</span></td><td>Email akun.</td></tr>
          <tr><td>password<span class="req">wajib</span></td><td>Kata sandi.</td></tr>
          <tr><td>device_name<span class="req">wajib</span></td><td>Nama perangkat, maks. 100 karakter. Muncul di daftar token, misalnya "Tablet Kasir 1".</td></tr>
        </tbody></table></div>
<pre><code>{
  "token": "12|Qm9yb3c…",
  "token_type": "Bearer",
  "data": {
    "user": { "id": 5, "name": "Dedi Kasir", "email": "kasir@contoh.id" },
    "business": { "id": 1, "name": "Pabalu Coffee &amp; Kitchen", "currency": "IDR", "timezone": "Asia/Makassar" },
    "role": { "code": "kasir", "label": "Kasir" },
    "permissions": ["pos.use", "orders.view", "kitchen.view"],
    "outlets": [ { "id": 1, "code": "KDI", "name": "Outlet Kendari", … } ]
  }
}</code></pre>
        <p>Gagal: 422 email/kata sandi salah, 403 akun tanpa bisnis atau tanpa akses kasir, 429 terlalu sering mencoba.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/auth/logout</span></div>
        <p>Mencabut token yang sedang dipakai (perangkat ini saja).</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/me</span></div>
        <p>Profil yang sama dengan <code>data</code> pada login. Panggil saat aplikasi dibuka untuk memeriksa token dan memuat ulang izin. Izin <code>transactions.approve</code> berarti boleh memberi diskon, membatalkan pesanan, dan menghapus item.</p>
      </div>
    </section>

    <section id="outlet">
      <h2>Outlet &amp; menu</h2>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/outlets</span></div>
        <p>Outlet aktif yang ditugaskan ke kasir: <code>id, code, name, address, phone, opens_at, closes_at, is_active</code>.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/outlets/{outlet}</span></div>
        <p>Outlet beserta konfigurasi kasir.</p>
<pre><code>"config": {
  "service_types": [ { "code": "dine_in", "label": "Dine In" }, { "code": "takeaway", "label": "Takeaway" } ],
  "tables_enabled": true,
  "kitchen": { "mode": "display", "label": "…" },
  "tax": { "enabled": true, "rate": 10, "prices_include_tax": false },
  "service_charge": { "enabled": true, "rate": 5 },
  "payment_methods": [ { "code": "cash", "label": "Tunai", "via_gateway": false }, … ],
  "payment_gateway": { "enabled": false, "provider": null, "sandbox": null },
  "currency": "IDR",
  "timezone": "Asia/Makassar"
}</code></pre>
        <p><code>via_gateway: false</code> berarti pembayaran non-tunai dikonfirmasi manual oleh kasir.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/outlets/{outlet}/menu</span></div>
        <p>Kategori dan menu aktif dengan harga outlet ini. Menu yang habis tetap dikirim dengan <code>is_available: false</code> supaya bisa ditampilkan redup.</p>
<pre><code>{ "data": {
  "categories": [ { "id": 1, "name": "Kopi", "sort_order": 1 } ],
  "items": [ {
    "id": 1, "category_id": 1, "name": "Kopi Susu Gula Aren", "sku": "KOP-001",
    "description": "…", "image_url": null, "price": 22000, "is_available": true, "needs_kitchen": true,
    "variants": [ { "id": 1, "name": "Regular", "price": 22000 }, { "id": 2, "name": "Large", "price": 27000 } ],
    "modifier_groups": [ {
      "id": 1, "name": "Gula", "min_select": 0, "max_select": 1,
      "modifiers": [ { "id": 1, "name": "Less sugar", "price": 0, "is_available": true } ]
    } ]
  } ]
} }</code></pre>
        <p><code>needs_kitchen: false</code> menandai menu siap saji (mis. air mineral botol): item langsung tersaji, tidak masuk layar dapur, dan tidak dicetak di tiket dapur. Pada objek pesanan, setiap item punya <code>sent_to_kitchen</code>.</p>
        <p>Jika menu punya varian, kasir wajib memilih salah satu. Patuhi <code>min_select</code> dan <code>max_select</code> per grup modifier (<code>max_select</code> null berarti tidak dibatasi).</p>
      </div>
    </section>

    <section id="shift">
      <h2>Shift kasir</h2>
      <p>Shift wajib terbuka sebelum membuat, melunasi, atau mengubah pesanan. Satu kasir hanya punya satu shift berjalan per outlet.</p>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/outlets/{outlet}/shift</span></div>
        <p>Shift berjalan milik kasir di outlet ini. <code>data</code> bernilai null bila belum dibuka.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/outlets/{outlet}/shifts</span></div>
        <p>Buka shift. Respons 201 berisi objek shift.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>opening_cash<span class="req">wajib</span></td><td>Modal awal di laci (integer ≥ 0).</td></tr>
          <tr><td>opening_note</td><td>Catatan, maks. 255.</td></tr>
        </tbody></table></div>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/shifts/{shift}</span></div>
        <p>Detail shift beserta rekap.</p>
<pre><code>{ "data": {
  "id": 3, "outlet_id": 1, "cashier": { "id": 5, "name": "Dedi Kasir" },
  "status": "open", "opening_cash": 100000, "opened_at": "…", "closed_at": null,
  "expected_cash": null, "actual_cash": null, "cash_difference": null, "closing_note": null,
  "summary": {
    "opening_cash": 100000, "cash_sales": 54000, "cash_in": 0, "cash_out": 20000, "cash_refunds": 0,
    "expected_cash": 134000, "payments_total": 81720, "orders_paid": 3, "orders_opened": 3,
    "discount_total": 0, "open_bills": 1, "payments_by_method": { "cash": 54000, "qris": 27720 }
  },
  "movements": [ { "id": 1, "type": "out", "amount": 20000, "reason": "Beli es batu", "created_at": "…" } ]
} }</code></pre>
        <p><code>expected_cash</code> hanya menghitung uang tunai di laci: modal + penjualan tunai + kas masuk − kas keluar − refund tunai.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/shifts/{shift}/cash-movements</span></div>
        <p>Kas masuk atau keluar di luar penjualan. Respons 201 berisi shift terbaru.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>type<span class="req">wajib</span></td><td><code>in</code> atau <code>out</code>. Kas keluar tidak boleh melebihi uang di laci.</td></tr>
          <tr><td>amount<span class="req">wajib</span></td><td>Integer ≥ 1.</td></tr>
          <tr><td>reason<span class="req">wajib</span></td><td>Alasan, maks. 255.</td></tr>
        </tbody></table></div>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/shifts/{shift}/close</span></div>
        <p>Tutup shift.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>actual_cash<span class="req">wajib</span></td><td>Uang yang dihitung di laci.</td></tr>
          <tr><td>closing_note</td><td>Wajib bila <code>actual_cash</code> berbeda dari <code>expected_cash</code>. Maks. 500.</td></tr>
        </tbody></table></div>
      </div>
    </section>

    <section id="pesanan">
      <h2>Pesanan</h2>

      <div class="ep" id="ep-order-store">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/outlets/{outlet}/orders</span></div>
        <p>Buat pesanan dan pembayarannya (split payment didukung), atau open bill dine-in dengan <code>pay_later: true</code>. Respons 201 untuk pesanan baru, atau 200 bila <code>client_reference</code> yang sama sudah pernah diterima.</p>
        <h4>Body</h4>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>client_reference</td><td>UUID buatan aplikasi, maks. 64. Sangat disarankan, supaya kiriman ulang tidak membuat pesanan ganda.</td></tr>
          <tr><td>service_type<span class="req">wajib</span></td><td><code>dine_in</code>, <code>takeaway</code>, atau <code>delivery</code>, sesuai yang aktif di outlet.</td></tr>
          <tr><td>pay_later</td><td><code>true</code> untuk open bill. Hanya dine-in di outlet yang mengizinkan. Tanpa <code>payments</code> dan tanpa diskon.</td></tr>
          <tr><td>dining_table_id</td><td>Hanya untuk dine-in di outlet yang memakai meja. Wajib bila <code>pay_later</code>.</td></tr>
          <tr><td>customer_id</td><td>Pelanggan terdaftar (opsional).</td></tr>
          <tr><td>customer_name</td><td>Maks. 120. Wajib untuk delivery antar sendiri tanpa <code>customer_id</code>, dan untuk bayar nanti di outlet tanpa meja.</td></tr>
          <tr><td>customer_phone</td><td>Maks. 32. Wajib untuk delivery antar sendiri tanpa <code>customer_id</code>.</td></tr>
          <tr><td>delivery_channel</td><td>Wajib bila delivery: <code>direct</code>, <code>gofood</code>, <code>grabfood</code>, <code>shopeefood</code>, <code>other</code>. Dilarang untuk layanan lain.</td></tr>
          <tr><td>delivery_reference</td><td>Nomor pesanan aplikasi ojol, maks. 64. Hanya untuk delivery.</td></tr>
          <tr><td>delivery_address</td><td>Wajib bila <code>delivery_channel = direct</code>. Maks. 500.</td></tr>
          <tr><td>notes</td><td>Catatan pesanan, maks. 500.</td></tr>
          <tr><td>discount_type</td><td><code>amount</code> atau <code>percent</code>. Butuh izin <code>transactions.approve</code>.</td></tr>
          <tr><td>discount_value</td><td>Angka ≥ 0, maks. 100 untuk persen.</td></tr>
          <tr><td>discount_reason</td><td>Maks. 255.</td></tr>
          <tr><td>items<span class="req">wajib</span></td><td>1–100 baris.</td></tr>
          <tr><td>items[].menu_item_id<span class="req">wajib</span></td><td>ID menu.</td></tr>
          <tr><td>items[].variant_id</td><td>Wajib bila menu punya varian.</td></tr>
          <tr><td>items[].modifier_ids</td><td>Array ID modifier, maks. 30.</td></tr>
          <tr><td>items[].quantity<span class="req">wajib</span></td><td>1–999.</td></tr>
          <tr><td>items[].notes</td><td>Catatan item untuk dapur, maks. 255.</td></tr>
          <tr><td>payments<span class="req">wajib</span></td><td>1–10 baris, kecuali <code>pay_later</code>.</td></tr>
          <tr><td>payments[].method<span class="req">wajib</span></td><td>Metode yang aktif di outlet.</td></tr>
          <tr><td>payments[].amount<span class="req">wajib</span></td><td>Uang yang diterima. Untuk tunai boleh lebih dari tagihan; kelebihannya menjadi kembalian.</td></tr>
          <tr><td>payments[].reference</td><td>Nomor referensi non-tunai, maks. 100.</td></tr>
        </tbody></table></div>
<pre><code>POST /api/v1/outlets/1/orders
{
  "client_reference": "8f0e7a52-1c7e-4d0f-9a43-5b1d6f2f9e11",
  "service_type": "takeaway",
  "items": [
    { "menu_item_id": 1, "variant_id": 2, "modifier_ids": [1], "quantity": 1, "notes": "es sedikit" },
    { "menu_item_id": 6, "quantity": 2 }
  ],
  "payments": [
    { "method": "qris", "amount": 20000, "reference": "QR-88123" },
    { "method": "cash", "amount": 50000 }
  ]
}</code></pre>
        <h4>Respons (objek pesanan)</h4>
<pre><code>{ "data": {
  "id": 8, "number": "KDI-261119-0003", "client_reference": null, "outlet_id": 1,
  "service_type": "takeaway", "table_number": null, "dining_table_id": null, "cash_shift_id": 3,
  "customer_id": null, "customer_name": null, "customer_phone": null,
  "delivery_address": null, "delivery_channel": null, "delivery_reference": null, "notes": null,
  "status": "completed", "payment_status": "paid",
  "subtotal": 24000,
  "discount": { "type": null, "value": 0, "amount": 0, "reason": null },
  "service_charge": { "rate": 5, "amount": 1200 },
  "tax": { "rate": 10, "included": false, "amount": 2520 },
  "total": 27720, "paid_amount": 27720, "change_amount": 0,
  "cashier": { "id": 5, "name": "Dedi Kasir" },
  "items": [ {
    "id": 14, "menu_item_id": 6, "variant_id": null, "name": "Air Mineral", "variant_name": null,
    "unit_price": 6000, "quantity": 4, "voided_quantity": 0, "line_total": 24000, "notes": null,
    "kitchen_status": "served", "kitchen_batch": 1, "sent_to_kitchen": true, "modifiers": []
  } ],
  "payments": [ {
    "id": 8, "method": "qris", "amount": 27720, "tendered_amount": 27720, "change_amount": 0,
    "reference": null, "status": "paid", "paid_at": "2026-11-18T21:47:02+00:00"
  } ],
  "receipt_url": "{{ url('/') }}/r/I0st7Do8zW09n9pXcl8iaZhBaqJeNG2C",
  "placed_at": "2026-11-18T21:47:02+00:00", "completed_at": "…", "cancelled_at": null, "cancel_reason": null
} }</code></pre>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/outlets/{outlet}/orders</span></div>
        <p>Riwayat pesanan, 50 per halaman, terbaru dulu.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>date</td><td><code>YYYY-MM-DD</code> dalam zona waktu bisnis. Bawaan: hari ini.</td></tr>
          <tr><td>search</td><td>Cari nomor pesanan atau nama pelanggan.</td></tr>
          <tr><td>status</td><td><code>open</code>: semua open bill outlet, tanpa batas tanggal.</td></tr>
          <tr><td>page</td><td>Nomor halaman.</td></tr>
        </tbody></table></div>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/orders/{order}</span></div>
        <p>Objek pesanan lengkap.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/outlets/{outlet}/summary</span></div>
        <p>Ringkasan penjualan outlet untuk dashboard aplikasi. Definisinya sama dengan halaman Keuangan web: hanya pesanan lunas yang tidak dibatalkan. Biaya bahan dan laba tidak dikirim.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>date</td><td>Satu hari, <code>YYYY-MM-DD</code> zona waktu bisnis. Bawaan: hari ini.</td></tr>
          <tr><td>from, to</td><td>Rentang tanggal (keduanya wajib bila dipakai), maksimal 31 hari.</td></tr>
          <tr><td>scope</td><td><code>outlet</code> (bawaan): seluruh outlet. <code>me</code>: hanya pesanan yang dibuat kasir yang sedang login, termasuk open bill-nya.</td></tr>
        </tbody></table></div>
<pre><code>{ "data": {
  "period": { "from": "2026-11-19", "to": "2026-11-19", "timezone": "Asia/Makassar" },
  "scope": "outlet",
  "summary": { "orders": 12, "gross_sales": 310000, "discount": 5000, "refunds": 0, "net_sales": 305000,
               "service_charge": 15250, "tax": 32025, "total_billed": 352275, "average_order": 29356,
               "void_count": 1, "void_amount": 27720 },
  "payments_by_method": [ { "method": "cash", "received": 200000, "refunded": 0, "net": 200000 } ],
  "series_unit": "hour",
  "series": [ { "label": "08:00", "orders": 3, "total": 81720 }, … ],
  "top_items": [ { "name": "Kopi Susu Gula Aren", "quantity": 9, "revenue": 198000 } ],
  "open_bills": { "count": 2, "total": 84000 }
} }</code></pre>
        <p><code>series</code> berisi 24 jam (<code>series_unit: "hour"</code>) untuk satu hari, atau per tanggal (<code>"day"</code>, dengan <code>net_sales</code>) untuk rentang.</p>
      </div>
    </section>

    <section id="open-bill">
      <h2>Open bill</h2>
      <p>Pesanan dine-in bayar nanti tetap terbuka (<code>payment_status: pending</code>) sampai dilunasi atau dibatalkan. Semua endpoint di bawah membutuhkan shift berjalan.</p>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/orders/{order}/items</span></div>
        <p>Tambah item. Body: <code>items</code> dengan format yang sama seperti saat membuat pesanan. Item tambahan masuk dapur sebagai kiriman (<code>kitchen_batch</code>) berikutnya.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/orders/{order}/settle</span></div>
        <p>Lunasi. Body: <code>payments</code>, serta opsional <code>discount_type</code>, <code>discount_value</code>, <code>discount_reason</code>. Total dihitung ulang dari item saat ini.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/orders/{order}/items/{item}/void</span><span class="tag">transactions.approve</span></div>
        <p>Kurangi atau hapus item open bill. Item terakhir tidak bisa dihapus; batalkan pesanannya.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>quantity<span class="req">wajib</span></td><td>1 sampai jumlah item.</td></tr>
          <tr><td>reason<span class="req">wajib</span></td><td>Alasan, maks. 255.</td></tr>
        </tbody></table></div>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/orders/{order}/merge</span></div>
        <p>Gabungkan open bill lain ke pesanan ini. Body: <code>source_order_id</code> (open bill lain di outlet yang sama). Pesanan asal dibatalkan dengan alasan "Digabung ke …".</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/orders/{order}/move-table</span></div>
        <p>Pindah meja. Body: <code>dining_table_id</code>. Ditolak bila meja tujuan masih punya open bill.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/orders/{order}/cancel</span><span class="tag">transactions.approve</span></div>
        <p>Batalkan open bill. Body: <code>reason</code> (wajib, maks. 255).</p>
      </div>
    </section>

    <section id="refund">
      <h2>Void &amp; refund</h2>
      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/orders/{order}/refund-requests</span></div>
        <p>Ajukan void atau refund untuk pesanan yang sudah lunas. Pengajuan menunggu persetujuan owner (<code>status: pending</code>), kecuali diajukan oleh owner sendiri. Respons 201.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>type<span class="req">wajib</span></td><td><code>void</code> (batalkan seluruh pesanan) atau <code>refund</code> (kembalikan sebagian uang).</td></tr>
          <tr><td>amount</td><td>Wajib untuk refund, tidak melebihi sisa yang bisa dikembalikan.</td></tr>
          <tr><td>method</td><td>Wajib untuk refund: metode pengembalian uang.</td></tr>
          <tr><td>reason<span class="req">wajib</span></td><td>Alasan, maks. 255.</td></tr>
        </tbody></table></div>
<pre><code>{ "data": { "id": 4, "type": "refund", "amount": 15000, "status": "pending" } }</code></pre>
      </div>
    </section>

    <section id="cetak">
      <h2>Struk &amp; tiket dapur</h2>
      <p>Server menyiapkan byte ESC/POS lengkap (teks, potong kertas, QR struk web). Aplikasi cukup men-decode base64 lalu mengirim byte-nya apa adanya ke printer Bluetooth atau USB.</p>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/orders/{order}/receipt</span></div>
        <p>Query <code>paper_width</code>: <code>58</code> atau <code>80</code> (bawaan: pengaturan outlet).</p>
<pre><code>{ "data": {
  "order_id": 8, "number": "KDI-261119-0003",
  "paper_width": 58, "columns": 32,
  "receipt_url": "{{ url('/') }}/r/I0st7Do8…",
  "escpos_base64": "G0AbYQEb…",
  "text": "      Pabalu Coffee &amp; Kitchen\n…"
} }</code></pre>
        <p><code>text</code> adalah pratinjau polos untuk layar. Untuk mencetak, gunakan <code>escpos_base64</code>.</p>
      </div>

      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/orders/{order}/kitchen-ticket</span></div>
        <p>Tiket dapur tanpa harga, untuk outlet dengan mode cetak tiket. Query <code>batch</code> (bawaan: kiriman terakhir) dan <code>paper_width</code>. Respons: <code>order_id, batch, columns, escpos_base64, text</code>. Hanya berisi item dengan <code>sent_to_kitchen: true</code>; bila kiriman itu tidak punya item dapur (mis. hanya air mineral) dijawab 422.</p>
      </div>
    </section>

    <section id="pelanggan">
      <h2>Pelanggan</h2>
      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/customers?search=</span></div>
        <p>Cari pelanggan berdasarkan nama atau nomor HP (minimal 2 karakter). Maksimal 20 hasil: <code>id, name, phone, notes</code>.</p>
      </div>
      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/customers</span></div>
        <p>Tambah pelanggan cepat. Body: <code>name</code> (wajib, maks. 120), <code>phone</code> (unik per bisnis, dinormalisasi), <code>notes</code>. Respons 201.</p>
      </div>
    </section>

    <section id="meja">
      <h2>Meja</h2>
      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/outlets/{outlet}/tables</span></div>
<pre><code>{ "data": {
  "tables_enabled": true, "status_tracking": true,
  "tables": [ { "id": 1, "area": "Indoor", "name": "A1", "capacity": 4, "is_occupied": true,
                "open_orders": [ { "id": 9, "number": "KDI-261119-0004", "total": 42000 } ] } ]
} }</code></pre>
        <p><code>is_occupied</code> bernilai null bila outlet tidak melacak status meja.</p>
      </div>
      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/tables/{table}/clear</span></div>
        <p>Kosongkan meja secara manual setelah tamu pergi. Ditolak bila masih ada open bill.</p>
      </div>
    </section>

    <section id="offline">
      <h2>Cara kerja offline</h2>
      <p>Owner menyalakan mode offline per outlet. Jika belum aktif, endpoint offline menjawab 403 dan aplikasi harus tetap online.</p>
      <ol class="flow">
        <li><div><b>Daftarkan perangkat</b> sekali setelah login. Server memberi kode pendek, misalnya <code>K1</code>.</div></li>
        <li><div><b>Unduh bundle</b> setiap kali online: menu, harga, pajak, meja, metode bayar, dan shift berjalan.</div></li>
        <li><div><b>Saat offline</b>, aplikasi menghitung total sendiri (lihat <a href="#hitung">Rumus total</a>), menerima bayar, mencetak struk dan tiket dapur ke printer lokal, lalu menyimpan setiap kejadian ke antrean di perangkat.</div></li>
        <li><div><b>Saat online kembali</b>, kirim antrean berurutan ke <code>/sync</code>, maksimal 100 operasi per kiriman.</div></li>
      </ol>
      <h3>Aturan saat offline</h3>
      <ul>
        <li>Hanya pesanan yang langsung dibayar. Bayar nanti (open bill), payment gateway, struk web via QR, dan persetujuan owner menunggu online.</li>
        <li>Nomor pesanan dibuat perangkat dengan format <code>{KODE_OUTLET}-{YYMMDD}-{KODE_PERANGKAT}-{urut 4 digit}</code>, misalnya <code>KDI-261119-K1-0001</code>. Tanggal memakai zona waktu bisnis, dan urutan mulai dari 0001 setiap hari. Nomor ini tercetak di struk dan dipakai permanen.</li>
        <li>Harga dan total yang tercetak di struk yang disimpan server. Bila berbeda dengan data server, pesanan tetap diterima dan diberi catatan untuk owner.</li>
        <li>Kasir tidak bisa berganti akun saat offline; aplikasi memakai token kasir yang terakhir login.</li>
        <li>Patuhi <code>offline.max_hours</code> dari bundle: setelah lewat batas sejak sinkron terakhir, minta kasir menyambungkan internet dulu.</li>
      </ul>
    </section>

    <section id="perangkat">
      <h2>Daftar perangkat</h2>
      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/outlets/{outlet}/pos-devices</span></div>
        <p>Daftarkan perangkat. Mengirim ulang <code>device_uid</code> yang sama mengembalikan perangkat yang sudah ada dengan kode yang sama (200). Perangkat baru dijawab 201. Perangkat yang dicabut owner dijawab 403.</p>
        <div class="tbl-wrap"><table><tbody>
          <tr><td>name<span class="req">wajib</span></td><td>Nama perangkat, maks. 100.</td></tr>
          <tr><td>device_uid<span class="req">wajib</span></td><td>ID tetap perangkat (UUID yang dibuat sekali saat instalasi dan disimpan), maks. 100.</td></tr>
        </tbody></table></div>
<pre><code>{ "data": { "id": 3, "code": "K1", "name": "Tablet Kasir", "outlet_id": 1, "last_synced_at": null } }</code></pre>
      </div>
    </section>

    <section id="bundle">
      <h2>Unduh bundle</h2>
      <div class="ep">
        <div class="ep-head"><span class="method get">GET</span><span class="path">/pos-devices/{device}/bundle</span></div>
        <p>Semua data untuk berjualan tanpa internet. Simpan di database lokal dan ganti seluruhnya setiap kali berhasil mengunduh.</p>
<pre><code>{ "data": {
  "server_time": "2026-11-19T01:40:00+00:00",
  "device": { "id": 3, "code": "K1", "name": "Tablet Kasir", "outlet_id": 1, "last_synced_at": "…" },
  "offline": {
    "max_hours": 24,
    "pay_later_allowed": false,
    "number_format": "KDI-YYMMDD-K1-0001",
    "last_number": "KDI-261119-K1-0002"
  },
  "cashier": { "id": 5, "name": "Dedi Kasir", "can_discount": false },
  "current_shift": { "id": 3, "opening_cash": 100000, "opened_at": "…" },
  "outlet": { …sama seperti GET /outlets/{outlet}, termasuk "config"… },
  "categories": [ … ],
  "items": [ …sama seperti GET /outlets/{outlet}/menu… ],
  "tables": [ { "id": 1, "area": "Indoor", "name": "A1", "capacity": 4 } ]
} }</code></pre>
        <ul>
          <li><code>last_number</code>: nomor terakhir perangkat ini yang sudah diterima server. Pakai untuk melanjutkan urutan bila data lokal hilang (misalnya aplikasi dipasang ulang).</li>
          <li><code>current_shift</code>: shift server yang sedang berjalan. Pesanan offline dalam shift ini dikirim dengan <code>shift_id</code>. Jika null dan internet mati, aplikasi membuka shift offline sendiri.</li>
          <li><code>can_discount</code>: sembunyikan diskon bila false.</li>
          <li>Bandingkan <code>server_time</code> dengan jam perangkat. Jika selisihnya lebih dari beberapa menit, minta kasir membetulkan jam.</li>
        </ul>
      </div>
    </section>

    <section id="sync">
      <h2>Sinkronisasi</h2>
      <div class="ep">
        <div class="ep-head"><span class="method post">POST</span><span class="path">/pos-devices/{device}/sync</span><span class="tag">maks. 60 kiriman/menit</span></div>
        <p>Body: <code>{"operations": [ … ]}</code>, 1–100 operasi, urut sesuai kejadian. Setiap operasi diproses sendiri-sendiri; satu yang gagal tidak membatalkan yang lain.</p>
        <h4>Hasil per operasi</h4>
<pre><code>{ "data": [
  { "index": 0, "type": "shift_open", "client_reference": "s-7b1…", "status": "created", "id": 12 },
  { "index": 1, "type": "order", "client_reference": "o-91c…", "status": "created", "id": 40,
    "number": "KDI-261119-K1-0003",
    "issues": ["Harga Cappuccino di perangkat Rp 23.000, di server Rp 24.000."] },
  { "index": 2, "type": "order", "client_reference": "o-a02…", "status": "rejected",
    "errors": { "totals.total": ["Total tidak sama dengan subtotal − diskon + service charge + pajak."] } }
] }</code></pre>
        <div class="tbl-wrap"><table>
          <thead><tr><th>status</th><th>Arti</th><th>Tindakan aplikasi</th></tr></thead>
          <tbody>
            <tr><td>created</td><td>Diterima dan disimpan.</td><td>Hapus dari antrean. Untuk pesanan, simpan <code>id</code> dan <code>number</code> dari server.</td></tr>
            <tr><td>duplicate</td><td>Sudah pernah diterima sebelumnya.</td><td>Hapus dari antrean.</td></tr>
            <tr><td>rejected</td><td>Data rusak atau tidak konsisten.</td><td>Simpan dan tandai "gagal sinkron" agar bisa diperiksa. Jangan dikirim ulang tanpa perbaikan.</td></tr>
          </tbody>
        </table></div>
        <p><code>issues</code> hanya informasi; pesanan tetap diterima dan owner melihat catatannya di web.</p>
      </div>

      <h3>Operasi <code>shift_open</code></h3>
      <div class="tbl-wrap"><table><tbody>
        <tr><td>type<span class="req">wajib</span></td><td><code>"shift_open"</code></td></tr>
        <tr><td>client_reference<span class="req">wajib</span></td><td>UUID shift, maks. 64. Dipakai sebagai <code>shift_reference</code> di operasi lain.</td></tr>
        <tr><td>opening_cash<span class="req">wajib</span></td><td>Modal awal.</td></tr>
        <tr><td>opening_note</td><td>Maks. 255.</td></tr>
        <tr><td>opened_at<span class="req">wajib</span></td><td>Waktu buka (ISO 8601 dengan offset).</td></tr>
      </tbody></table></div>

      <h3>Operasi <code>order</code></h3>
      <p>Setiap operasi yang terkait shift menyebut <b>salah satu</b> dari <code>shift_id</code> (shift server dari bundle) atau <code>shift_reference</code> (shift yang dibuka offline).</p>
      <div class="tbl-wrap"><table><tbody>
        <tr><td>type<span class="req">wajib</span></td><td><code>"order"</code></td></tr>
        <tr><td>client_reference<span class="req">wajib</span></td><td>UUID pesanan, maks. 64.</td></tr>
        <tr><td>number<span class="req">wajib</span></td><td>Nomor yang tercetak, format <code>KDI-YYMMDD-K1-0001</code>. Bila ternyata sudah terpakai, server menyimpannya dengan akhiran huruf (<code>…-0001A</code>) dan mengembalikannya di <code>number</code>.</td></tr>
        <tr><td>placed_at<span class="req">wajib</span></td><td>Waktu transaksi (ISO 8601 dengan offset).</td></tr>
        <tr><td>shift_id / shift_reference<span class="req">wajib salah satu</span></td><td>Lihat di atas.</td></tr>
        <tr><td>service_type<span class="req">wajib</span></td><td><code>dine_in</code>, <code>takeaway</code>, <code>delivery</code>.</td></tr>
        <tr><td>dining_table_id, table_name</td><td>Meja (opsional). <code>table_name</code> dipakai bila ID meja sudah tidak ada.</td></tr>
        <tr><td>customer_id, customer_name, customer_phone</td><td>Opsional.</td></tr>
        <tr><td>delivery_channel, delivery_reference, delivery_address</td><td>Opsional, untuk delivery.</td></tr>
        <tr><td>notes</td><td>Maks. 500.</td></tr>
        <tr><td>items<span class="req">wajib</span></td><td>1–100 baris, persis seperti yang tercetak.</td></tr>
        <tr><td>items[].menu_item_id</td><td>ID menu (boleh null untuk menu yang sudah tidak ada).</td></tr>
        <tr><td>items[].variant_id</td><td>ID varian.</td></tr>
        <tr><td>items[].name<span class="req">wajib</span></td><td>Nama menu yang tercetak.</td></tr>
        <tr><td>items[].variant_name</td><td>Nama varian.</td></tr>
        <tr><td>items[].unit_price<span class="req">wajib</span></td><td>Harga per porsi <b>termasuk</b> varian dan modifier.</td></tr>
        <tr><td>items[].quantity<span class="req">wajib</span></td><td>1–999.</td></tr>
        <tr><td>items[].notes</td><td>Maks. 255.</td></tr>
        <tr><td>items[].modifiers[]</td><td><code>{modifier_id, group_name, name, price}</code>, nama dan harga wajib.</td></tr>
        <tr><td>discount_type, discount_value, discount_reason</td><td>Sama seperti pesanan online.</td></tr>
        <tr><td>totals<span class="req">wajib</span></td><td><code>subtotal, discount_amount, service_charge_rate, service_charge_amount, tax_rate, prices_include_tax, tax_amount, total</code>.</td></tr>
        <tr><td>payments<span class="req">wajib</span></td><td>Sama seperti pesanan online (<code>method, amount, reference</code>).</td></tr>
      </tbody></table></div>
      <p><b>Ditolak</b> bila: <code>subtotal</code> tidak sama dengan jumlah <code>unit_price × quantity</code>; diskon melebihi subtotal; <code>total</code> tidak sama dengan subtotal − diskon + service (+ pajak bila tidak termasuk harga); pembayaran kurang dari total; non-tunai melebihi total; format nomor salah; atau shift tidak ditemukan.</p>
      <p><b>Diterima dengan catatan</b> bila: harga berbeda dari server, menu sudah dihapus, total berbeda dari pengaturan pajak terbaru, diskon diberikan akun tanpa izin, layanan atau metode bayar sudah dinonaktifkan, pelanggan tidak ditemukan, sinkron melewati batas jam offline, atau jam perangkat terlalu cepat.</p>
<pre><code>{
  "type": "order",
  "client_reference": "o-91c4d5e2-0b7a-4c1e-8a55-2f3e9b0c7d61",
  "shift_reference": "s-7b1f0c3a-5d2e-4f6b-9c8a-1e2d3c4b5a69",
  "number": "KDI-261119-K1-0003",
  "placed_at": "2026-11-19T09:35:00+08:00",
  "service_type": "takeaway",
  "items": [
    { "menu_item_id": 4, "variant_id": null, "name": "Cappuccino", "unit_price": 23000, "quantity": 1,
      "modifiers": [] }
  ],
  "totals": {
    "subtotal": 23000, "discount_amount": 0,
    "service_charge_rate": 5, "service_charge_amount": 1150,
    "tax_rate": 10, "prices_include_tax": false, "tax_amount": 2415,
    "total": 26565
  },
  "payments": [ { "method": "cash", "amount": 30000 } ]
}</code></pre>

      <h3>Operasi <code>cash_movement</code></h3>
      <div class="tbl-wrap"><table><tbody>
        <tr><td>type<span class="req">wajib</span></td><td><code>"cash_movement"</code></td></tr>
        <tr><td>client_reference<span class="req">wajib</span></td><td>UUID, maks. 64.</td></tr>
        <tr><td>shift_id / shift_reference<span class="req">wajib salah satu</span></td><td>Shift tujuan.</td></tr>
        <tr><td>movement_type<span class="req">wajib</span></td><td><code>in</code> atau <code>out</code>. Kuncinya bukan <code>type</code>, karena <code>type</code> dipakai untuk jenis operasi.</td></tr>
        <tr><td>amount<span class="req">wajib</span></td><td>Integer ≥ 1.</td></tr>
        <tr><td>reason<span class="req">wajib</span></td><td>Maks. 255.</td></tr>
        <tr><td>created_at<span class="req">wajib</span></td><td>Waktu kejadian.</td></tr>
      </tbody></table></div>

      <h3>Operasi <code>shift_close</code></h3>
      <div class="tbl-wrap"><table><tbody>
        <tr><td>type<span class="req">wajib</span></td><td><code>"shift_close"</code></td></tr>
        <tr><td>shift_id / shift_reference<span class="req">wajib salah satu</span></td><td>Shift yang ditutup.</td></tr>
        <tr><td>actual_cash<span class="req">wajib</span></td><td>Uang yang dihitung di laci.</td></tr>
        <tr><td>closing_note</td><td>Maks. 500. Minta kasir mengisinya bila ada selisih.</td></tr>
        <tr><td>closed_at<span class="req">wajib</span></td><td>Waktu tutup.</td></tr>
      </tbody></table></div>
      <p>Kirim <code>shift_close</code> setelah semua pesanan dan kas masuk/keluar shift itu, karena kas ekspektasi dihitung dari data yang sudah diterima server saat penutupan. Shift yang sudah ditutup dijawab <code>duplicate</code>.</p>
    </section>

    <section id="android">
      <h2>Saran implementasi</h2>
      <ul>
        <li>Simpan antrean di database lokal (misalnya Room), satu baris per operasi, dengan urutan naik dan JSON body-nya. Buat <code>client_reference</code> saat kejadian, bukan saat mengirim.</li>
        <li>Jalankan pengiriman di WorkManager dengan syarat jaringan tersedia. Kirim dari yang terlama, maksimal 100 per kiriman, lalu proses hasil per <code>index</code>.</li>
        <li>Jika koneksi putus sebelum jawaban diterima, kirim ulang operasi yang sama tanpa diubah. Server menjawab <code>duplicate</code> untuk yang sudah masuk.</li>
        <li>Tetap pakai antrean juga saat online. Satu jalur tulis lebih sederhana dan tahan sinyal yang naik-turun; pesanan online tetap bisa memakai <code>POST /outlets/{id}/orders</code> dengan <code>client_reference</code>.</li>
        <li>Simpan penghitung nomor urut per tanggal di perangkat. Setelah instal ulang, lanjutkan dari <code>offline.last_number</code> di bundle.</li>
        <li>Cetak struk offline dari data lokal. QR struk web baru berlaku setelah pesanan tersinkron.</li>
        <li>Tangani 403 pada <code>/bundle</code> atau <code>/sync</code>: perangkat dicabut atau mode offline dimatikan. Hentikan mode offline dan beri tahu kasir; jangan hapus antrean yang belum terkirim.</li>
      </ul>
    </section>

    <section id="enum">
      <h2>Nilai kode</h2>
      <div class="tbl-wrap"><table>
        <thead><tr><th>Kolom</th><th>Nilai</th></tr></thead>
        <tbody>
          <tr><td>service_type</td><td><code>dine_in</code>, <code>takeaway</code>, <code>delivery</code></td></tr>
          <tr><td>status (pesanan)</td><td><code>confirmed</code>, <code>preparing</code>, <code>ready</code>, <code>served</code> (tersaji, belum lunas), <code>completed</code>, <code>cancelled</code></td></tr>
          <tr><td>payment_status</td><td><code>pending</code>, <code>paid</code>, <code>refunded</code>, <code>partially_refunded</code></td></tr>
          <tr><td>kitchen_status (item)</td><td><code>queued</code>, <code>preparing</code>, <code>ready</code>, <code>served</code>, <code>cancelled</code></td></tr>
          <tr><td>payments[].method</td><td><code>cash</code>, <code>qris</code>, <code>debit_card</code>, <code>credit_card</code>, <code>bank_transfer</code>, <code>e_wallet</code></td></tr>
          <tr><td>discount_type</td><td><code>amount</code>, <code>percent</code></td></tr>
          <tr><td>delivery_channel</td><td><code>direct</code>, <code>gofood</code>, <code>grabfood</code>, <code>shopeefood</code>, <code>other</code></td></tr>
          <tr><td>shift status</td><td><code>open</code>, <code>closed</code></td></tr>
          <tr><td>cash movement</td><td><code>in</code>, <code>out</code></td></tr>
          <tr><td>refund type / status</td><td><code>void</code>, <code>refund</code> / <code>pending</code>, <code>approved</code>, <code>rejected</code></td></tr>
        </tbody>
      </table></div>
    </section>
  </main>
</div>
</div>
</x-app-layout>
