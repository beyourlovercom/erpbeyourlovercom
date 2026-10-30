# ERP US-SM XLWMS — API 重发货 V1 R5–R9 验收、Dede SOP 与工程关闭记录

**归档日期：2026-10-30**  · **项目：ERP** · **仓库：US-SM 世迈**
**范围：已取消或已发货的原 OMS 单 → 新的独立 OMS 草稿 CREATE → 身份、SKU 和状态只读确认。**
**边界：不是实际仓库出库，不是 Tracking 回写，不触发 Woo / AfterShip。以下 M2 履约闭环另立支线。**

## 1. 真实 Canary（R6–R8：PASS）

| 项目 | 已验证的生产事实 |
| --- | --- |
| ERP 订单 | `007-1027-c41863`，内部 ID 7753 |
| 原 OMS | `OBS0272610290RS`，世迈人工拦截，实时状态码 4（已取消），未产生 Tracking |
| 原 ERP 状态 | `pending_shipment`；Shipment `pending`，US-SM |
| 商品 | `CL279B × 2` |
| OMS 可用库存 | 2026-10-30 只读刷新 V2 已应用快照 ID 5：CL279B 可用 27，锁定 0 |
| 申请 UUID | `dd6751d9-2aac-4a0f-83ef-907c51b11969` |
| 唯一新 OMS | **`OBS0272610300RS`**；`BYL-RESHIP-dd6751d92aac4a0f83ef907c51b11969` |
| CREATE | 经 Owner 单笔授权，仅调用一次真实 CREATE；`isSubmit=0`，独立草稿 |
| 新 OMS 读回 | 状态码 **0**，无 Tracking；按原生 OMS 身份及 SKU 数量精确验证仅一单 |
| 审计 | 单笔 reship 记录 `draft_created`；6 个审计事件，从申请、二次确认到 CREATE、OMS 状态读回 |
| 原单保护 | 原 OMS 仍为状态 4；ERP 原状态、Tracking 与 Woo 未被重发流程覆盖 |
| 生产 CREATE 门禁 | `XLWMS_REISSUE_CREATE_ENABLED=false`、order ID 0、UUID 空；**持续关闭** |

事件序列：`operator_requested` → `operator_approved_reissue` → `single_order_claimed` → `provider_create_started` → `provider_identity_confirmed` → `provider_status_readback`。

**历史说明**：2026-10-29 曾记录“尚无真实可重发候选”，这条结论已被 2026-10-30 的已批准例外流程取代。原 OMS 状态 4 且 ERP 仍待发货，**不是**“ERP 已发货”；严禁人为修改原单发货状态。另一个受保护原 OMS `OBS0272610290RT`（ERP 7766）未用于此次 Canary。

## 2. Dede 操作 SOP（R5）

1. 在原 ERP **海外仓发货/审核 → 世迈 US-SM** 工作台找到目标订单行。若原 ERP 已发货，进入相应已发货视图；若原 OMS 已取消而 ERP 未发货，使用待发货视图。
2. 目标行必须具备原生 OMS 单号；点击 **重新下发仓库**，从预检查看原订单号、原 OMS、SKU×数量、收货信息、渠道 `No_Shipping_Service`、不自动提交仓库。
3. 已发货旧路径必须确认原订单确实已发货；**原 OMS 取消旧路径**必须由世迈 API 实时确认状态 4 和原 OMS 身份，操作提示为 **确认原 OMS 已取消**，不能确认一个不真实的已发货事实。
4. 录入不少于五个字的真实原因 → **提交重发申请**。系统生成独立 UUID 和 `BYL-RESHIP-<uuid32>`。Dede 二次确认只记录审批，**不调用** OMS CREATE。
5. 新 OMS 草稿 CREATE 需要单独 Owner 授权且三重 allowlist 精确匹配 **一笔 ERP order ID + 一条 UUID**。一般运营操作不能自行打开全局 CREATE 门禁。
6. 重新打开这条订单：原 OMS、新 OMS、状态码、Tracking 若存在均显示在同一个工作台。已存在 `draft_created` 时**不得再次点击/调用 CREATE**；只可读回状态或转人工核查。状态码 0 仅表示草稿，**不是已发货**。
7. 遇到不明确响应（`create_uncertain`）、旧 OMS 状态不安全、SKU 失配、库存快照过期、无法读取新 OMS 时，保持门禁 OFF；按 UUID 与 OMS 新单号核对，**禁止盲重试**。

## 3. 安全与测试签收（R9）

- 申请要由权限为 `logistics.operate` 且执行中间件放行的操作者处理；正确区分“已发货”/“原 OMS 已取消”两类确认。后端对于错填确认字段拒绝请求，不产生新申请。
- 原 OMS 的实时状态与身份校验不能仅靠 ERP 缓存。原 OMS 已取消路径限定 pending ERP / pending US-SM Shipment / 无 Tracking / 原生 OMS 身份匹配。
- 每张原单只允许有效重发申请一条，已创建草稿不能重复 CREATE；断网或不确定响应必须停下来查询，不能重试创建。
- UI 自动组件测试覆盖 Dede 原发货申请、二次审批、取消原单准确确认、已创建草稿回看、CREATE gate OFF；PHP 65 项既有隔离回归 + 1 项新增确认字段接口测试全部通过。
- 2026-10-30 在隔离 worktree 验证 **PHP 66 tests / 342 assertions PASS**；Vue **32 tests PASS**；TypeScript `vue-tsc --noEmit` PASS；`git diff --check` PASS。
- **真实 Dede 账号浏览器人工视觉 UAT 没有进行**，不得宣称此项 PASS。已做的是组件级交互自动测试与生产数据库/OMS 实单读回；账号人工体验可作为非阻断运营培训验收。
- 生产版始终保留 CREATE 三重门禁 OFF；若需重新 CREATE 必须新的明确授权（新单已成功创建的订单 7753 **永不重复 CREATE**）。

## 4. 工程范围关闭与交接

**API 重发货 V1 的工程目标已完成**：受控提交补发申请、二次审批、生成一个独立新 OMS 草稿、按原生身份/商品数量读回状态、全程审计、旧 ERP/Woo/Shipment/Tracking 不被覆盖。R5 自动化操作验收、R6 真实预检、R7 真实 CREATE、R8 真实新单读回均有证据。R9 整理 SOP 与正确提示文案。

**剩余事项独立移交 M2（不阻塞 V1 的 CREATE 技术关闭）**：世迈新 OMS 草稿如何受控提交/出库、仓库实际拣货与面单、Tracking 获取/回写 ERP/Woo/AfterShip、重复收入防护和真实补发成本。当前 `OBS0272610300RS` 仍只是一张状态 0 的 OMS 草稿，没有授权出库。

**GitHub**：PR #545 及 #557 已合并；PR #554 包含本 R9 SOP、Dede UX 回归与确认语义收尾。**生产界面确认文案与后端校验更新需单独受控发布及读回后才能宣称生产版完成该微调**；不能把代码合并与生产发布等同。