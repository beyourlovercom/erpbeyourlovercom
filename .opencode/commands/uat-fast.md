---
description: Fast capture a BYL ERP Human UAT finding
agent: uat-recorder-fast
subtask: false
---

将以下内容作为本次 UAT Finding 输入，按 Agent 合同处理：

$ARGUMENTS

运行前置条件：本命令只能在专用 BYL ERP Human UAT 分支或专用 UAT Worktree 中使用。Fast Agent 不执行 Git 状态或分支检查；当前运行位置由 Owner 负责确认。

允许 Owner 在同一消息附加截图；只记录附件名称或描述，不复制附件。
