# ERP Global Grid R5-L2 · 三张物流尾部表 Dev/UAT

Owner 授权：执行 R5-0 51 项真实完成总表、R5-L2 三列表改造，初始工程预算 5–9 有效小时。
仅独立开发、隔离自动化 UAT，**Production 必须由 Owner 再单独授权发布**。

## 路由与原业务保护

| # | URL | 原业务状态（不改变） | 保留操作 |
|---|---|---|---|
| 20 | /logistics/awaiting-tracking | shipment 已发货、实际 shipped_at 非空、tracking_number IS NULL、未复核阻断、运营订单可操作 | 原 update-tracking，物流商/单号由原服务端校验 |
| 21 | /logistics/in-transit | shipment 已发货、有真实 Tracking、未复核阻断、运营订单可操作 | 原复制追踪及 mark-exception（必须说明） |
| 22 | /logistics/exceptions | shipment exception、未复核阻断、运营订单可操作 | 原 pause/redispatch/reship/contact-user/cancel-exception/resolve-exception；敏感操作强制原权限和必填原因 |

- 保留原 LogisticsController 的查询、with eager loading、archive/trash/history 排除和 warehouse scope，不发明新订单状态。
- 三个路由共享服务端 read-only search/pager/sort，关键词查全部授权后端记录，支持 ERP 源单号、外部单号、收件人、Tracking、物流商/渠道以及异常原因。20/50/100 条，后端白名单排序 shipment carrier/tracking/shipped_at；分页不无限加载。
- 使用现成 GlobalGrid + OmsGrid，抽取一个轻量 LogisticsL2Grid.vue，共享列和受控点击菜单；每张表账号独立列偏好；操作列默认在右侧，但用户可取消固定/隐藏/拖动。原 status bar 保留。
- 旧表始终可通过 global_grid=0 一键回退。与当前 GlobalGridPilot 权限门禁一致，生产中非 Owner 不因本 PR 自动启用；Dev/测试用 global_grid=1 开启显式 canary。
- Grid 只展示数据及原操作入口，点击后行下方紧凑复用旧操作表单；不增加第二张大表、不直接发货、不重发 OMS、不改 Woo 状态。
- 对只读账号不显示修改入口，复制追踪无需修改权限；所有原 POST 的 CSRF、角色权限和业务校验仍由原 Laravel controller/service 执行。

## 自动化验收

- R5-0：见 ERP_GLOBAL_GRID_R5_0_51_ROUTE_PROGRESS_20261113.md。不得将仅有代码的旧试点标记 Owner PASS。
- PHP：三队列搜索匹配真实后端记录；old/preview Grid 一致；限流页面大小与 allowlist sort；Tracking 变更、异常、审批/付款门禁和归档过滤不变。
- Vue：无权限新旧模式都只读，operator 通过原按钮/表单，Grid/原表切换，三页面不混动作。
- Chrome：合成 SQLite 的等待单号/运输中/异常三条订单，检查 Grid 表格与原表、搜索分页、列偏好及行动编辑面板；浏览器 UAT 拦截全部 shipment mutation POST，确保 0 真发货。
- 其他模块：R4-8 九视图/30-Tab、同步总表、MorePlus 独立接口回归，不允许回退。
- CI 全部通过后提交独立 PR，保持 OPEN 和 Production OFF，等待 Owner Dev 视觉验收及独立发布授权。

## 已完成的 Dev/UAT 实测结果

- PHP 隔离完整回归：**158 PASS / 1 SKIPPED，3542 assertions**；唯一 SKIPPED 为 MySQL-only 时区测试（SQLite 环境跳过）；包含新增 L2 真实搜索 / old-grid 数据 / 20、50、100 / Sort allowlist / 无权操作 313 assertions。
- Vue/Vitest：**137/137 PASS（7 files）**；包含 3 张表 Grid/原表切换、view-only 不能开放修改、operator 只打开旧受控表单而无自动发货 POST。
- Chromium 全套：**23/23 PASS，E2E_CLEANUP=PASS**；新增隔离三单（待回填、运输中、异常）在新 Grid/原表均正确显示，关键词搜索、20条分页、列设置、编辑表单及原表一键切换；测试屏蔽所有真实物流订单 POST，统计 0 次。
- TypeScript、E2E TS、Vite build、全员 30-Tab 构建保护：PASS。
- Production 实际业务代码：**未修改、未发布**。R5-L2 三张表需 Owner Dev 视觉验收和后续单独发布授权。

本次相对于 R5-L2 原预算 5–9 有效工程小时，实际自动化执行窗口会随最终 PR 记录，不将墙钟执行等同人工工时。

最终 Dev 取证 UTC：2026-11-13T08:55:34.330595+00:00
状态：**DEV_TECHNICAL_PASS_OWNER_UAT_PENDING_NOT_MERGED_NOT_DEPLOYED**。

## Owner Dev 视觉验收记录（2026-11-13）

- ① **已发待单号**：Owner 确认视觉 PASS。
- Owner 追加 UI 要求：三张 L2 页面「返回原表」要像已验收国内物流页面一样放在**标题栏右侧**，不可占用标题下方独立工具行。
- Dev 分支三张页面已统一将 Grid 切换按钮内聚到 mvp-page-heading 右侧；移动端同样靠右。
- 单元测试、TypeScript 和 Vite 30-Tab build PASS；Chrome 合成账号的桌面端三页面、手机端右对齐检查 PASS（右边界一致），物流变更 POST 0 次。
- ② **运输中**：Chrome 第 2 个标签已打开并刷新，Owner 视觉验收 **PENDING**。
- ③ **异常订单**：Chrome 第 3 个标签已打开，Owner 视觉验收 **PENDING**。
- Preview 使用美国 Mac mini 的隔离本地 SQLite 临时数据和 127.0.0.1 端口，不触达 Production。
- PR 仍 Draft，不合并 main，不发布 Production。


## Owner P0: 操作列全部展开（2026-11-13）

- 用户明确要求：**禁止任何操作折叠菜单，操作这一列所有可用功能直接显示**。
- 「待回填物流单号」1 个按钮：填写 Tracking；「运输中」2 个按钮：复制追踪、标记异常；
  「异常订单」6 个按钮：暂停订单、重新派件、重新发货、联系用户、取消订单（不退款）、解除异常。
- 只修改 R5-L2 共享列配置，compactActions=false，复用原 GlobalGridCell 的直接按钮渲染。
  直接显示不等于直接执行；有副作用的动作保持原业务审批、原因必填与确认门禁，阅读角色不可执行写入。
- 异常订单取消二级「处理异常」按钮和二次选择菜单，选择具体动作后只打开对应必填原因编辑器。
- 操作列按页面分别给出足够宽度（142/194/560px），不裁切、不折叠，可由用户在列设置中取消固定/隐藏/拖动。
- 本机隔离临时 SQLite、Chrome 验证三张表分别完整显示 1/2/6 按钮，0 物流修改 POST。
  **第二、第三张 Owner 视觉验收仍待确认。Production 禁止合并和发布。**


### Owner P0 最终隔离验收证据

- TypeScript / E2E TypeScript PASS，Vue RoleRealUx 8/8 PASS。
- Dev Vite 构建及全员 30-Tab 保护 PASS。
- Chrome 三页合成数据真实列视图逐项校验：**1、2、6 个直接操作按钮**，所有按钮在 cell 右边界内，无折叠菜单（0）。
- 运输中「标记异常」与异常订单「重新发货」点击仅打开受控表单，原因未填写前确认不可用；提交物流修改 POST 0。
- 完整 Global Grid Chrome 回归 **23/23 PASS（55.5 秒）**，隔离 SQLite 测试库自动清理 E2E_CLEANUP PASS。
- Dev Owner 第 2 张「运输中订单」视觉 PASS 尚未收到；第 3 张亦待验收。**未授权合并 main 或发布 Production。**
