# XLWMS 草稿箱 API 校验修复 — 2026-10-30

目标：世迈 US-SM 所有正常单笔、批量与 Reship 固定 No_Shipping_Service + isSubmit=0，目标 OMS 草稿－待提交，再由物流专员上传面单、人工提交。

旧缺口：API CREATE 返回 success=true 即登记草稿成功；未立即核实 OMS 当前状态。实际新 OMS 可能从草稿流转到仓库处理中或已取消，ERP 仍误显成功。

修复：CREATE 后立即只读查询 OMS 原生单号、仓库、thirdOrderNo、platformOrderNo 和状态；只有码 0 允许标记草稿成功。状态 2/4、身份错误、查询失败均保留 OMS 编号和异常错误码、阻止重发；ERP、Shipment、Tracking、Woo 不因为 CREATE 自动改已发货。

外部 OMS 设置：自动提交已获取面单和无需面单的出库单必须保持 OFF。ERP isSubmit=0 无法阻止 OMS 后来按租户设置自动流转或人工提交。因此本修复不能被解读为 OMS 开关的远程锁定。

回归：正常单笔、批量、Reship，模拟状态 0/2、查单失败，防重复及身份校验。共 113 tests / 853 assertions PASS。

无第三张真实 OMS 单；不动 ERP 7753 历史旧新 OMS；生产环境未经 Owner pass 不发布。真实草稿落箱的现场验收需要另外批准的新单 Canary。