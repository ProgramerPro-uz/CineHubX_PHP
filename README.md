# PHP Bot (CineHubX)

Bu papkada Python botning PHP versiyasi joylashtirildi. Python fayllar o'zgartirilmadi.

## Ishga tushirish

```bash
php "php bot/app.php"
```

`.env` root papkadan (`CineHubX/.env`) o'qiladi.

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
