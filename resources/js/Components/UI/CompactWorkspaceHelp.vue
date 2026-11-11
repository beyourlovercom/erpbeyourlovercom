<template>
  <footer class="compact-workspace-help" aria-label="页面操作帮助">
    <slot />
  </footer>
</template>

<style scoped>
.compact-workspace-help{
  margin-top:auto;padding:10px 2px 4px;
  font-size:11px;line-height:1.5;color:#64748b;
  border-top:1px solid #edf1f4;
}
@media(max-width:800px){.compact-workspace-help{font-size:11px;line-height:1.6}}
</style>
