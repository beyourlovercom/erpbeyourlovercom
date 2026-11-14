@echo off
set "PATH=D:\ai\siteops-vben;%PATH%"
set "CI=true"
cd /d D:\ai\erp-siteops\frontend
call D:\ai\siteops-vben\pnpm.cmd install --offline --frozen-lockfile
exit /b %ERRORLEVEL%