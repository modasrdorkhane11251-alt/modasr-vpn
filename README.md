<div align="center">

# مدثر بات — MODASRbot

**فروش خودکار سرویس VPN — از پرداخت تا کانفیگ، همزمان روی تلگرام، بله و روبیکا**

<a href="https://t.me/modasrpanel">
  <img src="https://img.shields.io/badge/Telegram-@modasrpanel-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white" alt="Telegram Channel"/>
</a>
<a href="https://rubika.ir/joing/BBIHBDHFD0NAYIDLGBSQYPOZBROKCTBQ">
  <img src="https://img.shields.io/badge/Rubika-@modasrpanel-FF6B35?style=for-the-badge" alt="Rubika Channel"/>
</a>
<a href="https://ble.ir/modasrpanel">
  <img src="https://img.shields.io/badge/Bale-@modasrpanel-1F8A70?style=for-the-badge" alt="Bale Channel"/>
</a>
<a href="https://github.com/modasrdorkhane11251-alt/modasr-vpn">
  <img src="https://img.shields.io/badge/GitHub-modasr--vpn-181717?style=for-the-badge&logo=github" alt="GitHub"/>
</a>

## کانال‌ها و گروه‌های پشتیبانی

| پلتفرم | کانال | گروه |
|---|---|---|
| تلگرام | [@modasrpanel](https://t.me/modasrpanel) | [@modasrpanelgroup](https://t.me/modasrpanelgroup) |
| بله | [@modasrpanel](https://ble.ir/modasrpanel) | [@modasrpanelgroup](https://ble.ir/modasrpanelgroup) |
| روبیکا | [@modasrpanel](https://rubika.ir/joing/BBIHBDHFD0NAYIDLGBSQYPOZBROKCTBQ) | — |

</div>

---

## معرفی

مدثر بات کل چرخه فروش اشتراک را خودکار می‌کند: کاربر پلن را انتخاب می‌کند، پرداخت می‌کند، بات روی پنل شما اکانت می‌سازد و کانفیگ را همان‌جا تحویل می‌دهد — بدون دخالت شما.

**تفاوت اصلی مدثر بات:** سه پلتفرم پیام‌رسان به‌صورت همزمان روی یک نصب و یک دیتابیس مشترک:

| پلتفرم | وضعیت |
|---|---|
| تلگرام | ✅ رابط اصلی + پنل مدیریت کامل |
| بله | ✅ تمام قابلیت‌ها |
| روبیکا | ✅ تمام قابلیت‌ها (منوها، خرید، ارسال رسید) |

## امکانات

- 🛍 فروش خودکار با دسته‌بندی، لوکیشن و پلن‌های حجم/زمان
- 💳 کارت به کارت با تأیید رسید تصویری (حتی رسید روبیکا برای ادمین تلگرام)
- 👛 کیف پول مشترک بین هر سه پلتفرم
- 📣 گروه گزارشات تاپیک‌دار تلگرام با ۱۰ تاپیک خودکار و برچسب پلتفرم هر گزارش
- 🖥 پشتیبانی از پنل مرزبان و چند پنل همزمان
- ⏱ کرون‌های خودکار: هشدار اتمام، پایش پنل، بکاپ شبانه
- 👥 سیستم نمایندگی و زیرمجموعه‌گیری
- 🌐 نصب یک‌دستوری با ثبت خودکار وب‌هوک هر سه پلتفرم

## نصب

```bash
bash <(curl -Ls https://raw.githubusercontent.com/modasrdorkhane11251-alt/modasr-vpn/main/install.sh)
```

نصاب روی سرور از شما دامنه و توکن بات‌ها را می‌پرسد و همه‌چیز را خودکار راه‌اندازی می‌کند.

## ساختار پروژه

```
modasr-vpn/
├── index.php          # منطق اصلی بات
├── admin.php          # پنل مدیریت
├── botapi.php         # لایه API و روتینگ پلتفرم‌ها
├── platform/          # لایه چندپلتفرمه (Bale / Rubika)
├── panels/            # درایورهای پنل (مرزبان و...)
├── cronbot/           # کرون‌ها
├── lang/              # فارسی، انگلیسی، روسی، چینی
└── install.sh         # نصاب خودکار
```

---

<div align="center">
فورک توسعه‌یافته از پروژه میرزا بات · توسعه و شخصی‌سازی: <b>MODASR</b>
</div>
