<script setup lang="ts">
import type { AllocationStockWarning } from '../../lib/allocationStockWarning'
defineProps<{ orderNumber: string; warnings: AllocationStockWarning[]; submitting?: boolean; serverWarning?: string }>()
const emit = defineEmits<{ cancel: []; confirm: [] }>()
</script>
<template>
  <Teleport to="body">
    <div class="stock-exception-shade" @click.self="emit('cancel')">
      <section role="dialog" aria-modal="true" aria-label="缺货分仓确认" class="stock-exception-dialog">
        <header class="stock-exception-heading">
          <span class="stock-exception-symbol" aria-hidden="true">!</span>
          <div><h2>所选仓库库存不足或未知</h2><p>订单 {{ orderNumber }} · 请确认是否仍要分仓</p></div>
        </header>
        <p class="stock-exception-description">当前选定仓库无法根据库存系统证明所需数量充足。你可以先分仓，等补货后再发货，或处理尚未录入库存的特殊 SKU。</p>
        <div v-if="warnings.length" class="stock-exception-list">
          <div v-for="(warning, index) in warnings" :key="index" class="stock-exception-line">
            <strong>{{ warning.sku }}</strong><span>{{ warning.warehouseName }}</span>
            <span>需要 {{ warning.requiredQuantity }} 件 · {{ warning.availableQuantity === null ? '库存未知' : '可用 ' + warning.availableQuantity + ' 件' }}</span>
          </div>
        </div>
        <p v-if="serverWarning" class="stock-exception-server">{{ serverWarning }}</p>
        <p class="stock-exception-note">确认只会提交真实分仓并记录人工确认，不会虚增库存、自动扣减库存或直接标记已发货。发货阶段仍按现有审核规则执行。</p>
        <footer class="stock-exception-actions">
          <button type="button" class="stock-exception-cancel" :disabled="submitting" @click="emit('cancel')">返回修改</button>
          <button type="button" class="stock-exception-confirm" :disabled="submitting" @click="emit('confirm')">{{ submitting ? '提交中…' : '确认仍要分仓' }}</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
<style scoped>
.stock-exception-shade{position:fixed;inset:0;z-index:2200;display:grid;place-items:center;background:rgba(15,23,42,.46);padding:20px}
.stock-exception-dialog{width:min(560px,100%);max-height:90vh;overflow:auto;border-radius:14px;background:#fff;box-shadow:0 24px 72px #0f172a3f;padding:23px 24px;color:#1b2b36;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Segoe UI",sans-serif}
.stock-exception-heading{display:flex;gap:13px;align-items:center}
.stock-exception-symbol{display:grid;place-items:center;flex-shrink:0;width:36px;height:36px;border-radius:50%;background:#fff3e3;color:#ad620f;font-weight:850;font-size:23px}
.stock-exception-heading h2{font-size:19px;margin:0;font-weight:800;color:#28323b}
.stock-exception-heading p{font-size:12px;color:#75808b;margin:4px 0 0}
.stock-exception-description{margin:18px 0 12px;font-size:13px;line-height:1.7;color:#475569}
.stock-exception-list{border:1px solid #ebd8bd;border-radius:9px;overflow:hidden}
.stock-exception-line{display:grid;grid-template-columns:1fr 1.3fr 1.5fr;gap:10px;align-items:center;padding:11px 13px;background:#fffbf6;font-size:12px}
.stock-exception-line+.stock-exception-line{border-top:1px solid #f0e4d5}
.stock-exception-line strong{color:#263645}
.stock-exception-line span{color:#6b4a21}
.stock-exception-note{margin:15px 0 20px;font-size:12px;line-height:1.65;color:#64748b}
.stock-exception-server{font-size:12px;color:#a72828}
.stock-exception-actions{display:flex;justify-content:flex-end;gap:10px}
.stock-exception-actions button{border-radius:7px;border:1px solid #cfd7dd;cursor:pointer;padding:9px 16px;font-weight:700;font-size:13px}
.stock-exception-cancel{background:#fff;color:#475569}
.stock-exception-confirm{background:#a96118;color:white;border-color:#a96118!important}
.stock-exception-actions button:disabled{opacity:.55;cursor:wait}
</style>
