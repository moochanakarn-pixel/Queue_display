@echo off
setlocal EnableDelayedExpansion

:: URL ของจอ Queue Display (แก้ถ้า port หรือ path ต่างกัน)
set "URL=http://localhost/queue_display/"

:: ค้นหา Chrome จาก registry ก่อน (รองรับทุก path ที่ติดตั้ง)
set "CHROME="

for /f "usebackq skip=2 tokens=1,2,*" %%a in (
    `reg query "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe" /ve 2^>nul`
) do if /i "%%a"=="(Default)" set "CHROME=%%c"

if not defined CHROME (
    for /f "usebackq skip=2 tokens=1,2,*" %%a in (
        `reg query "HKLM\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe" /ve 2^>nul`
    ) do if /i "%%a"=="(Default)" set "CHROME=%%c"
)

if not defined CHROME (
    for /f "usebackq skip=2 tokens=1,2,*" %%a in (
        `reg query "HKCU\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe" /ve 2^>nul`
    ) do if /i "%%a"=="(Default)" set "CHROME=%%c"
)

:: Fallback: ตรวจ path ทั่วไปถ้า registry หาไม่เจอ
if not defined CHROME (
    for %%P in (
        "%ProgramFiles%\Google\Chrome\Application\chrome.exe"
        "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
        "%LocalAppData%\Google\Chrome\Application\chrome.exe"
        "%ProgramFiles%\Chromium\Application\chrome.exe"
        "%ProgramFiles(x86)%\Chromium\Application\chrome.exe"
    ) do (
        if not defined CHROME if exist "%%~P" set "CHROME=%%~P"
    )
)

if not defined CHROME (
    echo.
    echo [!] ไม่พบ Google Chrome บนเครื่องนี้
    echo     กรุณาติดตั้ง Chrome แล้วลองใหม่
    echo     หรือเปิด %URL% ในเบราว์เซอร์ด้วยตัวเอง
    echo     (เสียงแจ้งเตือนอาจต้องคลิกหน้าจอก่อนถึงจะได้ยิน)
    echo.
    pause
    exit /b 1
)

echo [OK] พบ Chrome: %CHROME%
echo [OK] เปิด: %URL%

start "" "%CHROME%" ^
    --autoplay-policy=no-user-gesture-required ^
    --app="%URL%"

endlocal
