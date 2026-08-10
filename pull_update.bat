@echo off
chcp 65001 >nul
echo ===================================
echo   Queue Display — Pull Update
echo ===================================
echo.

cd /d "%~dp0"

where git >nul 2>&1
if errorlevel 1 (
    echo [ERROR] ไม่พบ Git — กรุณาติดตั้ง Git ก่อน
    pause
    exit /b 1
)

echo [1/2] กำลัง pull จาก GitHub...
git pull origin main

if errorlevel 1 (
    echo.
    echo [ERROR] Pull ไม่สำเร็จ — ตรวจสอบ internet หรือ credentials
    pause
    exit /b 1
)

echo.
echo [2/2] เสร็จสิ้น! ไฟล์อัปเดตแล้ว
echo.
echo หมายเหตุ: settings.local.php ไม่ถูกเขียนทับ (gitignore)
echo.
pause
