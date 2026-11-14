@echo off
set "PATH=D:\ai\siteops-vben;%PATH%"
set "CI=true"
cd /d D:\ai\erp-siteops\frontend
call D:\ai\siteops-vben\pnpm.cmd --filter @vben/web-antd typecheck
if errorlevel 1 exit /b 1
call D:\ai\siteops-vben\pnpm.cmd --filter @vben/web-antd build
exit /b %ERRORLEVEL%