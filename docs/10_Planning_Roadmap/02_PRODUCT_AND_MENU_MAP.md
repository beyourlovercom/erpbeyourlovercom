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

Owner-approved Level-2 V1 operating target after M1.4 expansion:

```text
总览
红人库
审批中心             [Manager]
合作机会
合作记录
内容与发布
AffiliateWP
佣金与结算
佣金付款
```

Semantics:

```text
总览 = role-aware KOL Specialist / KOL Manager weekly-monthly operational dashboard
红人库 = ERP KOL master / assigned specialist ownership
审批中心 = KOL / paid-collaboration / commission-payout approvals, Manager only
合作机会 = ERP CRM opportunity truth
合作记录 = formal Collaboration truth
内容与发布 = ERP Content publication truth
AffiliateWP = Influencer + Coupon synchronized operating workspace
佣金与结算 = AffiliateWP Referral commission projection + ERP non-Affiliate settlements
佣金付款 = payout request / approval / manual PayPal / AffiliateWP payout reconciliation history
```

Affiliate-attributed consumer sales remain `order_kind=customer` plus KOL/Affiliate attribution. Physical samples/gifts remain `order_kind=kol_fulfillment`. AffiliateWP remains Affiliate/Referral/Payout provider truth; WooCommerce remains Coupon/customer-order provider truth; ERP becomes the normal KOL operating and approval surface.

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
