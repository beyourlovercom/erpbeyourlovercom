<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializePaymentFeesCommand extends Command
{
    protected $signature = 'finance:materialize-payment-fees
        {--order= : Materialize one order ID}
        {--limit=10000 : Maximum operational orders to inspect}
        {--apply : Persist payment_fee only; dry-run is the default}';

    protected $description = 'Preview or locally apply Owner-confirmed 3.5% provider-order-total payment fees.';

    public function handle(CostMaterializationService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! app()->environment(['local', 'testing'])) {
            $this->error('The --apply option is restricted to local and testing environments.');
            return self::FAILURE;
        }

        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()->orderBy('id');
        if ($this->option('order') !== null) {
            $query->whereKey($this->option('order'));
        } else {
            $query->where('order_kind', 'customer')
                ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'))
                ->limit(max(1, min(10000, (int) $this->option('limit'))));
        }

        $summary = ['mode' => $apply ? 'apply' : 'dry_run', 'seen' => 0, 'applied' => 0, 'noop' => 0, 'blocked' => []];
        foreach ($query->get() as $order) {
            $result = $apply ? $service->applyPaymentFeeGate($order) : $service->previewPaymentFeeGate($order);
            $summary['seen']++;
            if ($apply) {
                $summary['applied'] += count($result['applied']);
                $summary['noop'] += count($result['noop']);
                foreach ($result['blocked'] as $row) $this->countBlocked($summary, $row);
                continue;
            }
            $candidate = $result['candidates'][0];
            $status = (string) ($candidate['materialization_status'] ?? 'blocked');
            if ($status === 'apply') $summary['applied']++;
            elseif ($status === 'noop') $summary['noop']++;
            else $this->countBlocked($summary, $candidate);
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $row */
    private function countBlocked(array &$summary, array $row): void
    {
        $blocker = (string) ($row['blocker'] ?? 'blocked');
        $summary['blocked'][$blocker] = ($summary['blocked'][$blocker] ?? 0) + 1;
    }
}
