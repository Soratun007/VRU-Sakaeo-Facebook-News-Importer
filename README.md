# VRU Sakaeo Facebook News Importer

ปลั๊กอิน WordPress สำหรับนำเข้าข่าวจาก Facebook Page ของ VRU Sakaeo โดยเลือกดึงจากโพสต์รายเดือน หรือวางลิงก์โพสต์เอง แล้วสร้างเป็นข่าว WordPress พร้อมรูปภาพ, featured image, gallery 3 คอลัมน์, log การนำเข้า และระบบกันโพสต์ซ้ำ

## การติดตั้ง

1. อัปโหลดไฟล์ `vru-sakaeo-facebook-news-importer.zip` ที่ WordPress Admin > Plugins > Add New > Upload Plugin
2. Activate ปลั๊กอิน `VRU Sakaeo Facebook News Importer`
3. ตั้งค่า secret ใน `wp-config.php` หรือ environment variable ของโฮสต์
4. ไปที่เมนู `นำเข้าข่าว Facebook > ตั้งค่า` เพื่อตรวจสถานะ secret และ token diagnostics
5. ใช้แท็บ `เลือกจากโพสต์รายเดือน` เป็นวิธีหลัก หรือใช้แท็บ `นำเข้าจากลิงก์` เมื่อมีลิงก์เฉพาะ

ตัวอย่างสำหรับ `wp-config.php`:

```php
define( 'VRU_FB_PAGE_ID', '1630299557250465' );
define( 'VRU_FB_PAGE_ACCESS_TOKEN', 'PAGE_OR_SYSTEM_USER_TOKEN_HERE' );
define( 'VRU_FB_APP_SECRET', 'APP_SECRET_HERE' );
define( 'VRU_FB_APP_ID', 'APP_ID_HERE' ); // optional สำหรับ token diagnostics
```

ให้ใส่บล็อกนี้เหนือบรรทัด `/* That's all, stop editing! */` และอย่าใส่ token ในหน้าตั้งค่าปลั๊กอินหรือฐานข้อมูล WordPress

## Facebook Token

ปลั๊กอิน v2.1 รองรับค่า `VRU_FB_PAGE_ACCESS_TOKEN` ได้ 2 แบบ:

- Page access token โดยตรง
- System User token ที่สามารถดึง Page access token ของ `VRU_FB_PAGE_ID` ได้

สำหรับเพจแบบ New Page Experience นั้น Meta อาจปฏิเสธการเรียก `PAGE_ID/posts` ด้วย System User token โดยตรงและแจ้งว่า “ต้องใช้ Page access token” ปลั๊กอินจึงพยายามดึง Page token จาก token ต้นทางก่อนผ่าน `/{PAGE_ID}?fields=id,name,access_token` และ fallback ผ่าน `/me/accounts` แล้วจึงใช้ Page token ที่ได้เรียก `/posts`

หากหน้า diagnostics แสดง `Token type = SYSTEM_USER` แต่ `Page token derivation = unavailable` ให้ตรวจใน Business Settings ว่า System User ถูก assign asset เป็นเพจ VRU Sakaeo แล้ว และตอน Generate Token ควรมี permission ที่เกี่ยวข้อง เช่น `pages_show_list`, `pages_read_engagement`, `pages_read_user_content` และในบางกรณีของ Business/System User ต้องมี `business_management` เพื่อดึง Page token ได้

Graph API Explorer token เหมาะสำหรับทดสอบระยะสั้น ไม่ควรใช้เป็น token งานจริงระยะยาว งานจริงควรใช้ Page access token ที่จัดการอายุ token แล้ว หรือ System User token ที่ผูกกับ Business/App/Page ถูกต้องและไม่หมดอายุ

## ความปลอดภัย

- ไม่เก็บ token ใน `wp_options`
- ไม่แสดง token ใน HTML ของหน้า admin
- ไม่ส่ง token ใน query string ไป Meta Graph API
- ใช้ `Authorization: Bearer ...` header
- ใช้ `appsecret_proof`
- ไม่เก็บ raw API error ที่มี token
- log มี `user_id` เพื่อ audit trail
- จำกัดจำนวน URL ต่อรอบ และตรวจ domain/MIME/ขนาดไฟล์ภาพก่อนนำเข้า Media Library

หากเคยเผย token จริงใน screenshot, เอกสาร, log, chat หรือปลั๊กอินเวอร์ชันก่อนหน้า ให้ rotate token ใหม่ก่อนใช้งานจริง

## การใช้งานแบบเลือกจากโพสต์รายเดือน

1. เปิด `นำเข้าข่าว Facebook > เลือกจากโพสต์รายเดือน`
2. เลือกเดือน
3. กด `ดึงโพสต์จากเพจ`
4. ติ๊กโพสต์ที่ต้องการนำเข้า
5. กด `นำเข้าและเผยแพร่โพสต์ที่เลือก`

วิธีนี้เหมาะกับลิงก์ Facebook แบบใหม่ เช่น `pfbid...` เพราะระบบใช้ข้อมูลจาก Graph API ของเพจโดยตรงแทนการเดา post ID จาก URL

## สิ่งที่ระบบทำ

- ดึงโพสต์จาก `PAGE_ID/posts` ตามเดือน
- แสดงตารางโพสต์พร้อม checkbox, วันที่, ตัวอย่างข้อความ, จำนวนรูป และสถานะเคยนำเข้าแล้วหรือยัง
- สร้าง WordPress post สถานะ `publish`
- ตั้งหัวข้อจากบรรทัดแรกของโพสต์ โดยตัดตามจำนวนตัวอักษรเพื่อรองรับภาษาไทย
- ตั้งรูปแรกเป็น Featured Image
- แสดงรูปทั้งหมดเป็น WordPress gallery 3 คอลัมน์ ขนาด medium และลิงก์ไปไฟล์สื่อ
- กันนำเข้าซ้ำด้วย post meta `_vru_fb_post_id`
- ตรวจว่าโพสต์มาจาก Page ID ที่ตั้งค่าไว้เท่านั้น
- เก็บ log พร้อมลิงก์ดูข่าวและลิงก์แก้ไขข่าว

## หมายเหตุ

ปลั๊กอินใช้ข้อความต้นฉบับจาก Facebook เป็นเนื้อข่าว ยังไม่ใช้ AI เรียบเรียงใหม่
