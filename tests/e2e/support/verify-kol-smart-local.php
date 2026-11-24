<?php
declare(strict_types=1);
$root=realpath((string)getenv('BYL_E2E_RUN_ROOT'));
$db=realpath((string)getenv('DB_DATABASE'));
if (getenv('APP_ENV')!=='testing' || getenv('BYL_E2E_DISPOSABLE')!=='true' || getenv('DB_CONNECTION')!=='sqlite' || !$root || !$db || $db!==$root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'e2e.sqlite') {
    throw new RuntimeException('Readback requires the exact disposable database.');
}
$pdo=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA query_only=ON');
$stmt=$pdo->prepare("SELECT k.id,k.approval_status,
 (SELECT COUNT(*) FROM kol_affiliate_accounts a WHERE a.kol_id=k.id AND a.provider_status='active' AND a.provisioning_status='active') affiliates,
 (SELECT COUNT(*) FROM kol_affiliate_coupons c WHERE c.kol_id=k.id AND c.provisioning_status='active') coupons,
 (SELECT COUNT(*) FROM orders o WHERE o.kol_id=k.id) orders
 FROM kols k WHERE k.display_name=?");
foreach (['demo_browser_onboarding'=>1,'demo_browser_noship'=>0] as $name=>$orders) {
    $stmt->execute([$name]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1 || $rows[0]['approval_status']!=='approved' || (int)$rows[0]['affiliates']!==1 || (int)$rows[0]['coupons']!==1 || (int)$rows[0]['orders']!==$orders) throw new RuntimeException('Synthetic profile readback mismatch.');
    if ($orders===1) {
        $query=$pdo->prepare("SELECT COUNT(*) FROM orders o JOIN order_addresses a ON a.order_id=o.id JOIN order_items i ON i.order_id=o.id JOIN products p ON p.id=i.product_id WHERE o.kol_id=? AND o.order_kind='kol_fulfillment' AND a.recipient_name='Synthetic Recipient' AND a.country='US' AND a.postal_code='01234' AND p.sku='E2E-SKU-0001' AND i.quantity=1");
        $query->execute([$rows[0]['id']]);
        if ((int)$query->fetchColumn()!==1) throw new RuntimeException('Synthetic linked order address/SKU readback mismatch.');
    }
}
echo "DISPOSABLE_DB_READBACK_PASS profiles=2 active_affiliates=2 active_coupons=2 linked_orders=1 no_order_profile=1\n";
