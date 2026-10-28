# IT Hub

ศูนย์รวมบริการ IT ของโรงงาน — สแกน QR บนอุปกรณ์เพื่อดูข้อมูลและแจ้งซ่อม, ยื่นคำขอบริการ IT ทุกประเภท
ผู้ใช้ทั่วไปไม่ต้อง login มีเฉพาะเจ้าหน้าที่ IT (Admin) ที่ต้อง login

- หน้าผู้ใช้: `https://abms.ddns.net:8445/it-hub/`
- QR อุปกรณ์: `https://abms.ddns.net:8445/it-hub/a/<รหัสอุปกรณ์>`
- หน้า Admin: `https://abms.ddns.net:8445/it-hub/admin`

## โครงสร้าง

```
api/            PHP API (ไม่ใช้ framework) + PDO
  config.php    ค่าเชื่อมต่อ DB / app_url (ไม่อยู่ใน web root)
  public/       จุดเข้า API — Apache Alias /it-hub/api
  src/          logic: tickets, assets, auth, admin
  bin/          สคริปต์ CLI (สร้าง admin)
web/            Vue 3 + Vite + TypeScript + Tailwind
  dist/         ไฟล์ที่ build แล้ว — Apache Alias /it-hub
sql/            สคริปต์สร้างฐานข้อมูล ithub
deploy/         Apache config
storage/uploads รูปที่แนบมากับใบแจ้งซ่อม (www-data ต้องเขียนได้)
dev/router.php  router สำหรับทดสอบด้วย php -S
```

## ประเภทคำขอ (ตรงกับแบบฟอร์ม FM-IT)

| ประเภท | ฟอร์ม | เลขที่ |
|---|---|---|
| แจ้งซ่อม | FM-IT-003 | `RP-ปปดด-####` |
| ร้องขอดำเนินการด้าน IT | FM-IT-010 | `SV-...` |
| ขอใช้ E-mail บริษัท | FM-IT-009 | `EM-...` |
| เบิก-ยืมอุปกรณ์ | FM-IT-008 | `BR-...` |
| ขอซื้อครุภัณฑ์คอมพิวเตอร์ | FM-IT-007 | `PR-...` |

ทะเบียนอุปกรณ์ = FM-IT-001, ประวัติบำรุงรักษา (BM/PM) = FM-IT-004
ปิดงานซ่อมที่ผูกกับอุปกรณ์เป็น "เสร็จสิ้น" → ระบบบันทึกประวัติ BM ให้อัตโนมัติ

## ติดตั้ง (ครั้งแรก)

**1. ฐานข้อมูล** — ใน phpMyAdmin ด้วย root (แท็บ SQL) รันตามลำดับ:

1. `sql/00_create_db.sql` — สร้าง `ithub` + ให้สิทธิ์ `atthasan`
2. เลือก database `ithub` แล้วรัน `sql/01_schema.sql`
3. `sql/02_seed_assets.sql` — นำเข้าอุปกรณ์ 59 เครื่องจาก FM-IT-001

**2. สร้าง Admin คนแรก**

```bash
php /var/www/IT-Hub/api/bin/create-admin.php <username> "<ชื่อที่แสดง>"
```

**3. Apache** (ต้องใช้ sudo) — เพิ่มบรรทัดนี้ใน `/etc/apache2/sites-available/web-erp-ssl.conf`
ทั้งใน `<VirtualHost *:8443>` และ `<VirtualHost *:8445>` วางก่อนบรรทัด `DocumentRoot /var/www/web-erp/public`
(API รันผ่าน PHP-FPM pool `www` แยกจาก pool `erp-production` ของ ERP)

```apache
Include /var/www/IT-Hub/deploy/apache-it-hub.conf
```

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
```

## แก้ไขหน้าเว็บ

```bash
cd /var/www/IT-Hub/web
npm run build        # build ใหม่ → web/dist (มีผลทันที ไม่ต้อง restart อะไร)
```

พัฒนาแบบ hot reload:

```bash
php -S 127.0.0.1:8099 /var/www/IT-Hub/dev/router.php   # API
cd web && npm run dev                                   # http://localhost:5173/it-hub/
```

## ความปลอดภัย

- หน้าสแกน QR แสดงเฉพาะ รหัส/รุ่น/หน่วยงาน/ชื่อเล่น — ไม่แสดง Serial, ชื่อจริง, หมายเหตุภายใน
- หน้าติดตามสถานะไม่แสดงข้อมูลผู้แจ้ง และไม่แสดงบันทึกที่ตั้งเป็น "ภายใน"
- จำกัดการส่งคำขอ 10 ครั้ง / 10 นาที / IP, login ผิด 5 ครั้งล็อก 15 นาที
- คำขอที่เปลี่ยนข้อมูลต้องมี header `X-IT-Hub` + cookie `SameSite=Strict` (กัน CSRF)
- ห้ามใส่รหัสผ่านชั่วคราวของ E-mail ในช่องผลการดำเนินการ (ผู้แจ้งเห็นได้)
