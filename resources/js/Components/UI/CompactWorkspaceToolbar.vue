<template>
  <header class="compact-workspace-toolbar" aria-label="页面导航与操作">
    <div class="compact-workspace-navigation"><slot name="navigation" /></div>
    <div class="compact-workspace-actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped>
.compact-workspace-toolbar{display:flex;align-items:center;gap:12px;min-width:0;border-bottom:1px solid #e2e8f0}
.compact-workspace-navigation{flex:1 1 auto;min-width:0}
.compact-workspace-actions{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end}
@media(max-width:800px){
  .compact-workspace-toolbar{flex-wrap:wrap;gap:6px}
  .compact-workspace-navigation{order:2;flex:1 0 100%}
  .compact-workspace-actions{order:1;margin-left:auto}
}
</style>
