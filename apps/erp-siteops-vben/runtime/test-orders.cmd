@echo off
set "PATH=D:\ai\siteops-vben;%PATH%"
set "CI=true"
cd /d D:\ai\erp-siteops\frontend
call D:\ai\siteops-vben\pnpm.cmd exec vitest run apps/web-antd/src/api/erp-orders.test.ts
exit /b %ERRORLEVEL%