# مساعد سين جيم v2 — الواتساب + تطبيق الكمبيوتر والموبايل

| المجلد | الوظيفة |
|---|---|
| `wp-plugin/seengeem-wa-bridge` | إضافة ووردبريس: رقمان (طلاب وأولياء أمور / مدرسين ومتابعة)، Cloud API، طابور، تتبع التسليم، منع التكرار، ساعات الهدوء، حد يومي، إلغاء الاشتراك، واجهة REST |
| `gateway` | بوابة QR احتياطية (جلستان) — Node 18+ |
| `desktop` | برنامج ويندوز (Electron) بتحديث تلقائي من GitHub |
| `mobile` | تطبيق أندرويد (Capacitor) + لوحة المتابعة |

## البناء والإصدار
من تبويب Actions ← Release ← Run workflow واكتب رقم الإصدار (مثل 2.0.1). بعد ~10 دقائق تلاقي في Releases: ملف تثبيت الويندوز + SeenGeem-Assistant.apk + seengeem-wa-bridge.zip.
المطلوب مرة واحدة في Settings ← Secrets ← Actions: ANDROID_KEYSTORE_B64 و ANDROID_KEYSTORE_PASSWORD (مفتاح توقيع الأندرويد).

## تثبيت الإضافة
1. ارفع seengeem-wa-bridge.zip من الإضافات في ووردبريس وفعّلها (الإضافة القديمة seengeem-assistant تفضل شغالة).
2. من «واتساب المساعد» أدخل لكل رقم Phone Number ID و Access Token من Meta، وضع Webhook URL و Verify Token في إعدادات Meta.
3. انسخ «مفتاح التطبيقات» للتطبيقات وبوابة QR.

## ربط القواعد بالإرسال
```php
do_action('sgb_enqueue', 'students', $phone, $text, ['rule'=>'renew_7', 'dedupe'=>"renew_7:$user_id:$date"]);
// القناة: students (طلاب وأولياء أمور) أو staff (مدرسين ومتابعة)
```
أو من الخارج: POST /wp-json/sgb/v1/enqueue مع هيدر X-SGB-Key.
الرسائل المبادِرة على Cloud API تحتاج قالبًا معتمدًا من Meta (template و params)؛ النص الحر يصل فقط خلال 24 ساعة من آخر رد للعميل.

## بوابة QR
```
cd gateway && npm install
cp config.example.json config.json   # عدّل الرابط والمفتاح
npm start                            # امسح QR لكل رقم مرة واحدة
```
واتساب Web غير الرسمي فيه خطر حظر؛ استخدمه كاحتياطي فقط.

## التحديث
- الكمبيوتر: تلقائي (كل 6 ساعات) ويثبّت عند الإغلاق.
- الأندرويد: شريط «تحديث جديد» داخل التطبيق ثم تثبيت الملف يدويًا.
- آيفون: افتح الموقع في Safari ← إضافة للشاشة الرئيسية.
