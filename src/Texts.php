<?php

declare(strict_types=1);

namespace CineHubX\PhpBot;

final class Texts
{
    public const WELCOME = "Assalomu alaykum! 👋\n\nBu bot orqali o'zbekcha kino, Drama va animelarni topishingiz mumkin.\nQuyidagi menyudan tanlang.";

    public const SUB_REQUIRED = "📌 Davom etish uchun quyidagi kanallarga obuna bo'ling.\nSo'ng `✅ Tekshirdim` tugmasini bosing.";

    public const SEARCH_PROMPT = 'Qidirish uchun nom yoki kod yuboring:';
    public const NO_RESULTS = 'Hech narsa topilmadi.';

    public const CARD_TEMPLATE = "🎬 Nomi: {title}\n\n{code_line}📺 Turi: {type}\n📆 Yili: {year}\n🌍 Davlati: {country}\n🇺🇿 Tili: {language}\n🎞 Janr: {genres}\n{parts_line}";

    public const ACCOUNT_TEMPLATE = "👤 Hisobim\nID: {user_id}\nUsername: @{username}\n";

    public const HELP_TEXT = "📖 Qo'llanma\n- Qidiruv: nom yoki kod bilan toping\n- Saqlangan: yoqqanlarni tez toping\n- Kontent sahifasida qismni tanlang";

    public const ADMIN_MENU = 'Admin panel';
    public const ADMIN_ONLY = "Bu bo'lim faqat adminlar uchun.";
    public const ADMIN_ASK_CONTENT_ID = 'Kontent ID ni yuboring:';
    public const ADMIN_NOT_FOUND = 'Kontent topilmadi.';
    public const ADMIN_EDIT_PICK = "Tahrirlash uchun maydonni tanlang yoki o'chiring:";
    public const ADMIN_EDIT_VALUE = "Yangi qiymatni yuboring (o'chirish uchun '-' yozing):";
    public const ADMIN_DELETE_CONFIRM = "Rostdan ham o'chirmoqchimisiz?";
    public const ADMIN_SETTINGS_MENU = 'Kategoriya tanlang:';
    public const ADMIN_SETTINGS_EMPTY = "Hech narsa topilmadi.";
    public const ADMIN_FORCED_MENU = 'Majburiy obuna sozlamalari:';
    public const ADMIN_FORCED_ADD = "Kanal ID yuboring. Ixtiyoriy link: `-100123|https://t.me/+abcd`";
    public const ADMIN_FORCED_REMOVE = "O'chirish uchun kanal ID yuboring:";
    public const ADMIN_FORCED_LIST_EMPTY = "Majburiy obuna kanallari yo'q.";
    public const ADMIN_ADMINS_MENU = 'Adminlar sozlamalari:';
    public const ADMIN_ADMINS_ADD = 'Admin ID yuboring:';
    public const ADMIN_ADMINS_REMOVE = "O'chirish uchun admin ID yuboring:";
    public const ADMIN_ADMINS_LIST_EMPTY = "Adminlar ro'yxati bo'sh.";

    public const ASK_FORWARD = "Kontent kanalidan videoni botga forward qiling.";
    public const ASK_TYPE = 'Turini tanlang:';
    public const ASK_TITLE = 'Nomi:';
    public const ASK_PARTS_COUNT = 'Qismi necha?';
    public const ASK_PART = 'Qism raqami:';
    public const ASK_YEAR = 'Yili (ixtiyoriy):';
    public const ASK_COUNTRY = 'Davlati (ixtiyoriy):';
    public const ASK_LANGUAGE = 'Tili (ixtiyoriy):';
    public const ASK_GENRES = 'Janrlar (ixtiyoriy):';
    public const ASK_DESCRIPTION = 'Tavsif (ixtiyoriy):';
    public const ASK_POSTER = 'Poster rasm yuboring (ixtiyoriy):';
    public const SAVED = '✅ Saqlandi.';

    public const BROADCAST_PROMPT = 'Reklama matnini yuboring:';
    public const BROADCAST_DONE = 'Yuborildi: {sent} ta.';

    public const INVALID_PART = 'Qism topilmadi.';

    public const BACK_TEXT = '⬅️ Orqaga';
}
