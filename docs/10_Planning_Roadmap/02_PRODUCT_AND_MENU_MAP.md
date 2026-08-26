---
type: product-map
project: BYL ERP
version: 1.1
updated: 2026-08-24
---

# Product and Menu Map

## Primary navigation

The governed Level-1 menu baseline remains unchanged by this planning contract. Product work must preserve the approved Level-1 navigation authority.

Current long-term functional map includes:

```text
仪表盘
物流
同步
客户
网红
库存
外采
产品
指南
会计
设置
```

## Sync

```text
同步总览
连接管理
运行历史
失败与重试
冲突
字段映射
Webhook
同步设置
```

## Finance / Accounting

```text
订单分析
国家分析
产品收益
KOL ROI
库存建议
费用与成本设置
```

Existing MVP1 provides the Order-level Finance/Cost foundation. Future profitability expansion must use the canonical `orders.id` and the frozen Canonical Order / Finance / KOL data contract rather than create a separate accounting-order truth.

## KOL

Owner-approved Level-2 target:

```text
所有网红
网红订单
佣金明细
网红统计
关系维护
```

Semantics:

```text
所有网红
= ERP KOL master / assigned specialist ownership

网红订单
= order_kind = kol_fulfillment
= products / samples / gifts / replacements sent to KOLs

佣金明细
= AffiliateWP referral/commission projection bound to canonical customer Orders

网红统计
= orders / revenue / profit / margin / commission / SKU / country derived from canonical Orders

关系维护
= ERP-owned KOL CRM activities, relationship stage and next follow-up
```

Affiliate-attributed consumer sales remain `order_kind=customer` plus KOL/Affiliate attribution. They are not KOL fulfillment Orders.

Full AffiliateWP connector / KOL CRM runtime implementation is not automatically authorized by the menu target. `ERP-ORDER-FULFILLMENT-M1-001` may expose only the real KOL fulfillment Order surface required by its bounded scope; broader KOL Level-2 activation requires explicit implementation scope.

## SEO

```text
SEO总览
内容库
博客文章
页面
发布中心
Google Docs
关键词与搜索
页面表现
技术SEO
同步状态
```

## Canonical Order projection rule

Customer, KOL fulfillment, Logistics and Finance surfaces are projections over one canonical `orders.id`. No menu entry may imply or create a duplicate order database.
