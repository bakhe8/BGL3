# CRI-style Chat عبر Open WebUI + الأدوات المحلية

## لماذا هذا الخيار؟
- واجهة شات جاهزة (تشبه تجربة المحادثة في لقطة CRI).
- بثّ ردود، وإظهار خطوات الأدوات كـ Tool Calls داخل المحادثة.
- يرتبط مباشرة بـ Ollama المحلي (`http://localhost:11434`).
- يمكن توصيل أدوات الوكيل الحالية كـ Functions بدون إعادة بناء UI.

## المتطلبات
- Docker (مفضل) أو تشغيل binary.
- Ollama يعمل محلياً على `http://localhost:11434`.
- Python 3.12 (موجود للمشروع) لتشغيل جسر الأدوات.

## الخطوات السريعة (بدون Docker)
1) نزّل binary أو zip من الإصدار: https://github.com/open-webui/open-webui/releases/tag/v0.7.2  
   فك الضغط في مسار مثل `C:\open-webui`.
2) شغّل Open WebUI (مثال ويندوز PowerShell):
```bash
set OLLAMA_BASE_URL=http://localhost:11434
.\open-webui.exe
```
3) شغّل جسر الأدوات (يوصل أدوات الوكيل كـ API بسيطة):
```bash
python scripts/tool_server.py --port 8891
```
سيستمع على `http://localhost:8891/tool`.

4) افتح المتصفح: http://localhost:3000  
   أنشئ حساباً محلياً (مرة واحدة).

## إعداد الأدوات في Open WebUI
في الإعدادات → Tools → Add Tool (Function / API):

- **run_checks**  
  - Type: Function (Remote)  
  - Endpoint: `POST http://host.docker.internal:8891/tool`  
  - Payload مثال:  
    ```json
    {"tool": "run_checks"}
    ```
- **route_index**  
  ```json
  {"tool": "route_index"}
  ```
- **logic_bridge**  
  ```json
  {"tool": "logic_bridge", "payload": {"candidates": [], "record": {}}}
  ```
- **layout_map**  
  ```json
  {"tool": "layout_map", "payload": {"url": "http://localhost:8000"}}
  ```

يمكنك أيضاً إضافة `score_response`, `context_pack`, `embeddings_search` بنفس الصيغة.

## مطابقة تجربة CRI
- سمِّ الأدوات بأسلوب المراحل:  
  - `think_run_checks` ← يعرض Thinking  
  - `act_route_index` ← يعرض Acting  
  - `observe_layout_map` ← يعرض Observing  
  - `report_logic_bridge` ← يعرض Reporting  
- اختر ثيم داكن في الإعدادات؛ يمكن تعديل CSS من لوحة WebUI لتقريب الشكل كما في الصورة (صناديق ملونة للعناوين).

## الاستخدام
- اكتب رسالة طبيعية؛ إذا احتاج النموذج أداة سيستدعيها ويعرض نتيجة الـ tool call داخل المحادثة.  
- للأوامر التنفيذية (مثل “شغّل فحص شامل”) سيستدعي `run_checks` و`route_index` تلقائياً بعد تعريف الأدوات.  
- للأحاديث العامة لن تُستدعى الأدوات.

## تشغيل بدون Docker
- حمّل binary Open WebUI من https://github.com/open-webui/open-webui/releases  
- شغّل مع متغير البيئة `OLLAMA_BASE_URL=http://localhost:11434`  
- باقي الخطوات (جسر الأدوات + الإعداد في Tools) كما أعلاه.

## صيانة
- لإيقاف الجسر: Ctrl+C في الطرفية التي شغّلت فيها `tool_server.py`.
- لإيقاف WebUI Docker: `docker stop open-webui`، وللتشغيل مجدداً: `docker start open-webui`.

## ملاحظة
تم إيقاف شات لوحة التحكم القديم؛ CopilotKit سيُدمج لاحقاً. هذا الدليل مخصص لاستخدام Open WebUI محلياً بدون Docker.
