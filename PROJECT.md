# PlatiHub API ICT — Tổng quan Backend

> File này giúp nắm nhanh bối cảnh backend mỗi khi mở terminal lại. Đây là repo BE, nằm cạnh FE tại `platihub-ict` trong cùng thư mục gộp (xem `PROJECT.md` của FE đó để biết bối cảnh 2 chiều). Cập nhật khi kiến trúc/luồng nghiệp vụ thay đổi.

## ⚡ TRẠNG THÁI HIỆN TẠI (2026-09-16) — ĐÃ ĐỔI SANG XAMPP + MONGODB
Dự án **KHÔNG còn chạy Docker** và **KHÔNG còn dùng MySQL** nữa. Đã chuyển:
1. **Môi trường chạy local**: từ Docker Compose → **XAMPP**. Toàn bộ project (FE + BE) đã copy/merge vào `C:\xampp\htdocs\Platihub\` (giữ nguyên cấu trúc gốc: `platihub-ict/`, `platihub-api-ict/`). File gốc ở `E:\Platihub` vẫn còn nhưng bản đang chạy thật và được sửa là bản trong `htdocs`. **Luôn thao tác trên `C:\xampp\htdocs\Platihub\platihub-api-ict`**, không phải `E:\Platihub`.
2. **Database**: từ MySQL/PDO → **MongoDB Atlas** (cloud, free tier M0 512MB). Toàn bộ 9 file `api/*.php` và `config/database.php` đã viết lại dùng `MongoDB\Client` (thư viện `mongodb/mongodb` qua Composer) thay vì PDO. Xem chi tiết mục "Cấu hình MongoDB" bên dưới.
3. Composer đã được cài vào `C:\xampp\php\composer.phar`, extension `mongodb` đã bật trong `C:\xampp\php\php.ini` (dòng `extension=mongodb`), cần **restart Apache** (qua XAMPP Control Panel) nếu extension/php.ini bị đổi.

## Đây là gì?
Backend **PHP thuần (không framework)** cung cấp REST API dạng JSON cho website PlatiHub ICT (FE React ở `platihub-ict`). Chức năng chính: CRUD sản phẩm (catalog) + một "bot" tự crawl dữ liệu sản phẩm từ trang **anphatpc.com.vn** để đổ vào hàng chờ duyệt.

## Stack kỹ thuật
- **Ngôn ngữ**: PHP 8.2, không dùng framework (Vanilla PHP, mỗi endpoint là 1 file `.php` độc lập)
- **DB**: **MongoDB Atlas** (cloud, M0 free 512MB) qua thư viện `mongodb/mongodb` (Composer) — xem `config/database.php`. **Không còn MySQL.**
- **Dependency manager**: Composer (`composer.json`/`composer.lock`, thư mục `vendor/` — đã gitignore)
- **Chạy local**: XAMPP (Apache + PHP 8.2 ZTS x64), code đặt tại `C:\xampp\htdocs\Platihub\platihub-api-ict`
- **Deploy production**: Docker (`php:8.2-apache`) trên **Render** (`https://platihub-ict.onrender.com`) — file `Dockerfile`/`docker-compose.yml` vẫn còn trong repo nhưng **không dùng cho local nữa**; nếu deploy lại production cần cập nhật Dockerfile để cài extension `mongodb` + `composer install` thay vì `pdo_mysql`.
- **Rewrite**: `.htaccess` bật `mod_rewrite`, route mọi request không phải file/thư mục tồn tại về `index.php` (dù hiện các file API được gọi trực tiếp theo path `/api/*.php`)
- Không có ORM, không có test

## Cấu trúc thư mục
```
api/
  get_products.php         GET  ?status=pending|approved - danh sách sản phẩm (chỉ lấy SP có image_url hợp lệ)
  get_product_detail.php   GET  ?id=... - chi tiết 1 SP (chỉ trả về nếu status='approved')
  add_product.php          POST - thêm SP thủ công (status mặc định 'approved', source='manual')
  update_product.php       POST - sửa SP (product_name, manufacturer, product_type, image_url, description, price, is_price_visible, specifications)
  approve_product.php      POST {id} - set status='approved'
  hide_product.php         POST {id} - set status='pending' (ẩn khỏi trang chủ)
  delete_product.php       POST {id} - xóa cứng khỏi DB
  bot_sync_anphatpc.php    GET  ?url=...&offset=... - bot crawl An Phát PC (rất phức tạp, xem bên dưới)
  get_configs.php          GET  - đọc collection configs (key-value) cho FE
config/
  database.php       Class Database - kết nối MongoDB\Client, đọc MONGO_URI/MONGO_DB_NAME từ $_SERVER/$_ENV/getenv/env.local.php
  env.local.php      (gitignored) chứa MONGO_URI thật của Atlas cho môi trường local — KHÔNG commit
  mongo_helpers.php  Hàm dùng chung: product_doc_to_array() (đổi _id→id string, format created_at), to_object_id() (parse ObjectId an toàn)
images/
  hoanghapc/, anphatpc/   Ảnh tải về khi crawl (lưu tạm trên container - mất khi Render redeploy, xem cảnh báo bên dưới)
vendor/        Thư viện Composer (mongodb/mongodb...) — gitignored, chạy `composer install` để tái tạo
Dockerfile     Build image PHP 8.2 + Apache (đang còn cấu hình cho PDO/MySQL cũ — CẦN CẬP NHẬT nếu deploy lại, xem mục trạng thái ở đầu file)
.htaccess      Rewrite rule
```

## Bảng dữ liệu chính — MongoDB collections (đã đổi từ MySQL tables)
`products` (collection): `_id (ObjectId, trả về FE dưới dạng "id" string), product_name, image_url, description, manufacturer, product_type, price (int|null), is_price_visible (0|1), specifications (JSON string|null), status ('pending'|'approved'), source ('manual'|'bot'), created_at (MongoDB\BSON\UTCDateTime, trả về FE dạng ISO string)`
`configs` (collection): `meta_key, meta_value` (key-value cấu hình chung)

⚠️ **Lưu ý quan trọng khi sửa code**: mọi document lấy từ Mongo phải qua `product_doc_to_array()` (trong `config/mongo_helpers.php`) trước khi `json_encode` trả về FE, để đổi `_id` (ObjectId) thành `id` (string) — FE luôn kỳ vọng field `id` là string, không phải object. Khi nhận `id` từ FE để update/delete/approve/hide, phải qua `to_object_id($id)` trước khi query (trả `null` nếu không phải ObjectId hợp lệ → nên trả lỗi 400, không được query thẳng bằng string).

## ⚠️ Vấn đề bảo mật đã xác minh (xem thêm `bug.txt` bên FE)
1. **Không có xác thực ở BẤT KỲ endpoint admin nào** (`approve/hide/delete/update/add_product.php`, `bot_sync_anphatpc.php`) — không check token/session/API key. Ai biết URL cũng gọi được trực tiếp.
2. **CORS mở toàn bộ**: `Access-Control-Allow-Origin: *` ở mọi file.
3. **Rò rỉ lỗi ra client**: nhiều file (`get_products.php`, `add_product.php`, `update_product.php`, `database.php`) trả `error_detail`/`host_debug` (message lỗi PDO gốc, host DB) thẳng trong JSON response khi có exception.
4. **Ảnh crawl lưu trên đĩa container** (`images/anphatpc`, `images/hoanghapc`) — trên Render, filesystem là ephemeral nên ảnh mất mỗi lần redeploy. Đây là lý do code có thêm bước tự động up ảnh lên **Imgur** (`buildCleanImageUrl` → `uploadImageToImgur`, Client-ID hard-code `139e72807f61c3c` — trùng key với FE `useProductForm.ts`, nên cân nhắc gộp về 1 biến môi trường dùng chung).

## Luồng "bot crawl" An Phát PC (`bot_sync_anphatpc.php`) — endpoint phức tạp nhất
1. FE gửi `?url=<link An Phát PC>&offset=0`.
2. Backend tải HTML trang đó bằng cURL (giả User-Agent trình duyệt để né chặn bot).
3. Phân biệt **trang danh mục** (có `show_more_product`, `#js-filter-container`, `.p-item`...) hay **trang sản phẩm đơn lẻ**:
   - Trang danh mục → gọi ngược API nội bộ của An Phát (`/ajax/get_json.php?action=product...`) để lấy danh sách link sản phẩm theo trang/offset, batch mỗi lần 5 sản phẩm (`$batch_size = 5`).
   - Trang đơn lẻ → xử lý luôn 1 link đó.
4. Với mỗi sản phẩm: cào tên (`h1`), giá, ảnh (nhiều fallback: og:image, các class ảnh phổ biến, style background-image...), bảng thông số kỹ thuật (nhiều xpath dò khác nhau, có bộ lọc chặn cột "Giá/Đơn giá" lẫn vào specs).
5. **Tự phân loại category** (`classifyAnPhatProductType`) dựa theo slug URL danh mục + tên sản phẩm, ánh xạ về đúng các giá trị category mà FE đang dùng (PC, Laptop, CPU, Mainboard, VGA, Linh kiện, Màn hình, HDD-SSD, Tản Nhiệt, Tai nghe) — **đây là nơi cần đồng bộ nếu FE đổi danh mục** (xem `PRODUCT_CATEGORY_OPTIONS` ở FE `constants/config.ts`).
6. Ảnh gốc → tải về → (dự trù xóa watermark bằng GD, hiện chưa thực sự áp watermark logic cho An Phát) → **upload lên Imgur** để có URL bền vững không phụ thuộc đĩa container.
7. Kiểm tra trùng theo `product_name` → nếu đã tồn tại (source='bot') thì UPDATE (giữ nguyên nếu source='manual' để không đè dữ liệu admin đã sửa tay), nếu chưa có thì INSERT với `status='pending'` (chờ duyệt).
8. Trả về `has_more`/`next_offset` để FE (`useProductBot.ts`) hỏi có tiếp tục cào batch kế tiếp không.

## Đồng bộ quan trọng cần nhớ giữa FE ↔ BE
- **Danh mục sản phẩm (category)**: phải khớp giữa `PRODUCT_CATEGORY_OPTIONS` (FE `src/constants/config.ts`) và `classifyAnPhatProductType()` (BE `bot_sync_anphatpc.php`). Lệch tên category ở 1 bên sẽ khiến sản phẩm bot cào về không được FE lọc/hiển thị đúng.
- **Imgur Client ID**: đang hard-code trùng ở cả FE (`useProductForm.ts`) và BE (`bot_sync_anphatpc.php`, hằng `IMGUR_CLIENT_ID`) — nên gộp về biến môi trường dùng chung.
- **Trường `is_price_visible`**: BE lưu ở `update_product.php`, FE `ProductDetail.tsx` có đọc; nhưng `Products.tsx` (trang danh sách) hiện **chưa check field này** trước khi hiển thị giá — có thể lộ giá dù admin đã ẩn (xem `bug.txt` mục 14 bên FE).

## Chạy local bằng XAMPP + MongoDB Atlas (cách hiện tại — Docker đã ngừng dùng)
1. Code đặt tại `C:\xampp\htdocs\Platihub\platihub-api-ict` (không phải `E:\Platihub` — đó là bản gốc/backup).
2. Bật **Apache** trong XAMPP Control Panel (không cần MySQL nữa vì DB đã chuyển lên Atlas cloud).
3. Nếu lần đầu setup máy mới: cài Composer (`php composer-setup.php` → `composer.phar` đặt ở `C:\xampp\php\`), rồi chạy `php composer.phar install` trong `platihub-api-ict/` để tải `vendor/` (thư viện `mongodb/mongodb`). Đồng thời cần extension `mongodb.dll` cho PHP 8.2 TS x64 (tải từ `windows.php.net/downloads/pecl/releases/mongodb/`) copy vào `C:\xampp\php\ext\` và thêm dòng `extension=mongodb` vào `php.ini`, rồi **restart Apache**.
4. Kết nối MongoDB đọc từ `config/env.local.php` (gitignored) — chứa `MONGO_URI` (connection string Atlas dạng `mongodb+srv://user:pass@cluster.xxx.mongodb.net/`) và `MONGO_DB_NAME=platihub_db`. File này **không có trong git**, nếu clone lại máy mới phải tự tạo lại (xin connection string từ chủ dự án hoặc tạo cluster Atlas mới).

Test nhanh API:
```bash
curl http://localhost/Platihub/platihub-api-ict/api/get_products.php
```

FE (`platihub-ict/.env`) đã trỏ `VITE_API_BASE_URL=http://localhost/Platihub/platihub-api-ict/api`.

## Cấu hình MongoDB Atlas (cluster hiện tại)
- Project trên Atlas: **"Platihub ICT"** (project riêng, KHÔNG chung với project "invoice" cũ của chủ dự án — đã tách project cẩn thận để tránh lẫn billing/dữ liệu).
- Cluster: `PlatihubCluster` (gói **M0 Free**, 512MB, region tùy chọn lúc tạo).
- Database name: `platihub_db`, 2 collection: `products`, `configs` (tự tạo khi ghi document đầu tiên, không cần chạy migration).
- Network Access: đang mở **Allow Access From Anywhere (0.0.0.0/0)** để tiện chạy từ máy local IP động — cân nhắc siết lại IP cụ thể nếu deploy production.
- Xem dữ liệu trực quan: Atlas Dashboard → project "Platihub ICT" → Cluster → **Browse Collections** (hoặc **Data Explorer**).

## Đã ngừng dùng: Docker / MySQL (giữ lại file nhưng KHÔNG chạy)
`docker-compose.yml`, `Dockerfile`, `schema.sql` vẫn còn trong repo làm tài liệu tham khảo cấu trúc dữ liệu cũ, nhưng **không dùng để chạy local nữa**. Nếu sau này cần deploy production (Render) lại, phải viết lại Dockerfile để cài extension `mongodb` + chạy `composer install` thay vì `pdo_mysql`, và set biến môi trường `MONGO_URI`/`MONGO_DB_NAME` thay cho `DB_HOST/DB_NAME/DB_USER/DB_PASS` cũ.

## Trạng thái làm việc gần đây
- **2026-09-16**: Chuyển toàn bộ local dev từ Docker → XAMPP (`htdocs`), và toàn bộ DB từ MySQL → MongoDB Atlas. Viết lại `config/database.php` + 9 file `api/*.php` sang dùng `mongodb/mongodb`. Đã test đầy đủ CRUD + approve/hide + bot_sync qua MongoDB thật, hoạt động đúng, tiếng Việt (UTF-8) lưu/đọc chính xác.
- Trước đó (theo git log, thời còn MySQL): `d7ff4c4` update anphatpc v7, `35b4797` update anphatpc v6, `1abf04d` update anphatpc v5, `b4d62c6` update anphatpc v4, `96ef9ca` update anphatpc v3 → giai đoạn hoàn thiện bot crawl An Phát PC (phân loại category, xử lý ảnh, chống trùng lặp). Logic nghiệp vụ này giữ nguyên, chỉ đổi tầng truy vấn DB.

⚠️ Thay đổi MongoDB ở trên **chưa commit vào git** (đang là uncommitted changes trong working tree) — cân nhắc commit sớm để không mất, và nhớ kiểm tra `.gitignore` đã chặn `vendor/` + `config/env.local.php` trước khi `git add`.

## Việc còn thiếu / nên làm (ưu tiên theo `bug.txt` bên FE)
1. Thêm xác thực cho mọi endpoint ghi dữ liệu (approve/hide/delete/update/add/bot_sync) — ví dụ check header `Authorization: Bearer <secret>` khớp biến môi trường `API_SECRET_KEY`.
2. Giới hạn CORS về đúng domain FE thay vì `*`.
3. Không trả `error_detail`/`host_debug` ra client — chỉ `error_log()` phía server, trả message chung chung.
4. Gộp `IMGUR_CLIENT_ID` về 1 biến môi trường dùng chung giữa FE/BE.
5. Cập nhật `Dockerfile`/`docker-compose.yml` cho MongoDB nếu muốn deploy production lại (hiện đang thiết kế cho MySQL cũ, xem mục "Đã ngừng dùng" bên trên).
6. Cân nhắc thêm index MongoDB cho `products.product_name` (bot_sync tra cứu theo tên rất thường xuyên) và `products.status` (get_products lọc theo status) để tối ưu tốc độ khi dữ liệu lớn dần.
