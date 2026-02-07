# PHP Bot (CineHubX)

Bu papkada Python botning PHP versiyasi joylashtirildi. Python fayllar o'zgartirilmadi.

## Ishga tushirish

```bash
php app.php
```

`.env` loyiha root papkasidan (`CineHubX_PHP/.env`) o'qiladi.

## Railway deploy eslatma

PostgreSQL bilan ishlashi uchun `pdo_pgsql` drayveri kerak.

- `composer.json` ichida `ext-pdo_pgsql` borligini saqlang.
- Railway Variables ga `RAILPACK_PHP_EXTENSIONS=pdo_pgsql,pgsql` qo'shib, qayta deploy qiling.

## Qo'shilgan asosiy funksiyalar

- `/start` (deep-link: `content_...`, `dlp_...`)
- Majburiy obuna tekshiruvi
- Qidiruv, latest, top, favorites, profil, yordam
- Kontent kartasi, season/qism tanlash, ko'rish
- Favorit qo'shish/o'chirish
- Adminning asosiy bo'limlari:
  - `/admin`
  - statistika
  - majburiy obuna kanallarini boshqarish
  - adminlar ro'yxatini boshqarish
  - broadcast

## Eslatma

`admin:add_content`, `admin:add_part`, `admin:edit` callbacklari uchun PHP versiyada hozircha placeholder javob qo'yilgan.
