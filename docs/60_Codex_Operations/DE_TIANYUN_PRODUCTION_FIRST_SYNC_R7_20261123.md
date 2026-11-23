# 德国天运 Hannover Production 首次真实库存同步 — R7

## 当前边界
- 来源：PR #831 Production 已发布；原有德国仓库存 49 条，不等于 OMS 已同步
- Keychain 已保存 DE AppKey / AppSecret，但 Production `warehouse_openapi_connections` 原来未配置
- 严禁通过远程工具、环境变量、日志、管道、命令参数传输 AppKey/AppSecret
- 同步页面可经独立 `XLWMS_DE_PRODUCTION_READ_ENABLED=true` 开启 Production **只读** OMS 查询和快照；仍需 `sync.operate`，数据仅显示给 `sync.view`
- Production 网页按钮永远不会执行库存写入，甚至当 CLI apply gate 开启时仍为只读
- 正式库存写入仅由可信本机交互 Terminal 的 `xlwms:sync-inventory --warehouse=DE --apply` 执行，并要求双重独立开关、首次精确快照 SHA-256 和现有 V2 SKU/批次约束
- `XLWMS_DE_PRODUCTION_APPLY_ENABLED` 默认 false，任何时段不作为自动同步开关或调度
- ERP 商品不能自动创建；别名须由 Product OS 人工验证；无本仓台账的目录 SKU 不自动清零

## 操作顺序
1. 在美国 Mac mini 的可信 Terminal 运行 `php artisan xlwms:configure-inventory-api --warehouse=DE --configure`：授权身份 yes，仓库代码 Hannover，隐藏输入 AppKey/AppSecret；确认控制台显示 encrypted credential saved。Keychain 两项仅由本机操作者粘贴
2. 读取 `php artisan xlwms:configure-inventory-api --warehouse=DE --check`，必须 PRESENT
3. 在 Production 执行 `php artisan xlwms:sync-inventory --warehouse=DE --dry-run`；API 只读获取新快照，核对签名结果、SKU 数、OMS 总/可用/锁定数量、checksum。此时不写 ERP 仓库库存
4. 逐 SKU 对照 Production Product OS 已核实主 SKU/别名、德国仓库存身份、批次/预留、数量差。对 SKU 映射错误、异常库存、超过变更限制者保留阻断，绝不猜测映射或把无台账商品设为 0
5. 必须在新快照精确审核后才可配置 `XLWMS_DE_INVENTORY_FIRST_APPLY_APPROVED_CHECKSUM` 为该 checksum，并短暂开启 `XLWMS_DE_PRODUCTION_APPLY_ENABLED=true`。运行 CLI `--apply` 时在本机 TTY 人工确认；不得自动批量推送订单
6. 读回验证 ERP 库存和逐 SKU 成功/异常/未匹配记录，恢复 Production apply flag 为 false，保留审计记录；必要时采取既有备份/回滚流程，库存差异需业务核对而非盲目覆盖

## 2026-11-23 已取得的初步证据（非最新 OMS 查询）
- Dev 旧快照：34 个本仓库存 SKU + 30 个仅 OMS 产品目录 SKU
- 对正式 ERP 的只读比较：20 个可验证且已有 DE 库存记录，6 个可验证但无 DE 库存身份，8 个未核实商品身份；必须用新的 Production OMS 读取后重算
- 特殊待核：X001S1UJMH 旧快照 OMS=0，ERP DE=456；不得凭过期数据清零
- 未经 Production TTY 配置完成，未调用 Production OMS 真实库存 API；所有先前 UI 演示与合成测试均不是上线真实库存写入证明

## 安全和回归
- 原 XLWMS US-SM 流程及 OMS CREATE 全部保持原有约束
- 默认关闭 Production read 和 apply 开关，仓库/用户权限不匹配返回拒绝
- Dev 既有 apply 行为不改变；Production 仅新增只读页面与独立 CLI 限制
- 真实订单和 Woo 状态不能由本次仓库库存同步修改
