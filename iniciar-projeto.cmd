@echo off
setlocal
cd /d "%~dp0"
if not exist vendor\autoload.php (
  echo Dependencias ausentes. Consulte README.md para instalar.
  pause
  exit /b 1
)
echo printPRO - http://127.0.0.1:8000
echo Mantenha o MySQL do XAMPP ligado. Ctrl+C encerra o servidor.
php artisan serve --host=127.0.0.1 --port=8000
pause
