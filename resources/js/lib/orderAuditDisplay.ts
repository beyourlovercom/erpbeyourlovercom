const labels: Record<string, string> = {
  created: '创建订单',
  'order.created': '创建订单',
  'order.inbound_created': '同步并创建订单',
  'order.inbound_updated': '更新同步订单信息',
  'order.inbound_items_conflict_preserved': '同步时保留人工修改的商品',
  'order.updated': '修改订单信息',
  'order.contact_changed': '修改订单联络人',
  'order.note_appended': '添加订单备注',
  'order.workflow_status_changed': '修改工作状态',
  'order.core_status_changed': '修改订单状态',
  'order.warehouse_route_changed': '修改分仓路线',
  'order.auxiliary_status_added': '添加辅助状态',
  'order.auxiliary_status_removed': '移除辅助状态',
  'order.operational_marker.updated': '更新辅助标记',
  'order.operational_archived': '归档订单',
  'order.operational_archive_restored': '恢复归档订单',
  'order.archived_with_evidence': '归档订单并保留记录',
  'order.trashed': '移入回收站',
  'order.restored': '恢复订单',
  'order.erp_identity_repaired': '修复订单编号',
  'order.cancelled_from_exception': '取消异常订单',
  'finance.fixed_cost_materialization.applied': '核算固定费用',
  'finance.payment_fee_materialization.applied': '核算支付手续费',
  'finance.product_cost_materialization.applied': '核算商品成本',
  'finance.multi_shipment_cost_aggregation.applied': '汇总多包裹物流成本',
  'finance.replacement_loss.applied': '核算补发损失',
  'finance.actual_last_mile_materialization.applied': '核算实际尾程费用',
  'finance.overseas_handling_materialization.applied': '核算海外仓操作费',
  'finance.warehouse_cost_materialization.applied': '核算仓储成本',
  'finance.kol_commission_materialization.applied': '核算网红佣金',
  'finance.last_mile_estimate_materialization.applied': '估算尾程费用',
  'finance.cost_materialization.applied': '核算订单成本',
  'finance.financial_evidence_bootstrap.applied': '补充财务凭证',
  'finance.payment_fee_fx_metadata.repaired': '修复支付手续费汇率信息',
  'fulfillment.pending_reallocated': '调整未发货分仓',
  'fulfillment.replacement_created': '创建补发订单',
  'domestic.review_approve': '国内物流审核通过',
  'domestic.review_reject': '国内物流审核驳回',
  'domestic.review_stockout': '国内物流标记缺货',
  'domestic.review_restock': '国内物流恢复有货',
  'domestic.review_return_allocation': '国内物流退回待分仓',
  'oversea.review_approve': '海外仓审核通过',
  'oversea.review_reject': '海外仓审核驳回',
  'oversea.review_stockout': '海外仓标记缺货',
  'oversea.review_restock': '海外仓恢复有货',
  'oversea.review_reopen': '重新开启海外仓审核',
  'oversea.review_reset': '重置海外仓审核',
  'allocation.manual.started': '开始人工分仓',
  'allocation.manual.stage': '暂存人工分仓',
  'allocation.manual.finished': '完成人工分仓',
  'order_item.selected_gift_sku_restored': '恢复礼品 SKU',
  'order_item.warehouse_physical_sku_repaired': '修复仓库实际 SKU',
  'woocommerce.tracking_manual_override': '人工更新网站物流单号',
  'logistics.bulk_return_allocation': '批量退回待分仓',
  'logistics.review_requested': '提交物流审核',
  'logistics.review_decided': '处理物流审核',
  'logistics.customer_historical_shipment_backfill.applied': '补录历史发货信息',
  'logistics.historical_order_date_correction.applied': '修正历史订单日期',
  'logistics.historical_order_materialization.applied': '补录历史订单',
  'logistics.historical_rescue_order_projection.applied': '恢复历史订单记录',
  'logistics.historical_rescue_shipment_attempt_projection.applied': '恢复历史发货记录',
  'logistics.historical_shipment_attempt_projection.applied': '补录历史发货记录',
  'shipment.fulfillment_draft_saved': '保存发货草稿',
  'shipment.marked_shipped': '确认发货',
  'shipment.tracking_updated': '更新物流单号',
  'shipment.exception_marked': '标记物流异常',
  'shipment.exception_resolved': '处理物流异常',
  'shipment.exception_paused': '暂停物流异常处理',
  'shipment.reshipped': '重新发货',
  'shipment.user_contact_recorded': '记录客户联系情况',
  'shipment.completed': '完成物流',
  'shipment.reopened': '重新开启发货',
  'shipment.de_oms_tracking_staged': '暂存德国仓物流单号',
  'shipment.delivery_evidence_ingested': '同步妥投凭证',
}

export function orderAuditAction(action: string): string {
  return Object.hasOwn(labels, action) ? labels[action] : '其他操作（' + action + '）'
}

export function orderAuditActor(event: { actor_id: number | null; actor_name?: string | null }): string {
  if (event.actor_id == null) return '系统自动执行'
  return event.actor_name?.trim() || '未知账号（ID：' + event.actor_id + '）'
}

const formatter = new Intl.DateTimeFormat('sv-SE', {
  timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
})

export function orderAuditTime(value: string | null): string {
  if (!value) return '时间未知'
  const date = new Date(value)
  if (!Number.isFinite(date.getTime())) return '时间未知'
  const parts = Object.fromEntries(formatter.formatToParts(date).map(part => [part.type, part.value]))
  return parts.year + '-' + parts.month + '-' + parts.day + ' ' + parts.hour + ':' + parts.minute + ':' + parts.second
}
