<?php

namespace App\Modules\Sync\Infrastructure\WooCommerce;

use App\Modules\Sync\Application\Contracts\WooCommerceCustomerNoteGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWooCommerceCustomerNoteGateway implements WooCommerceCustomerNoteGateway
{
    private const string UserAgent = 'Mozilla/5.0 (compatible; BYL-ERP-WooCommerce-Inbound/1.0; +https://www.beyourlover.com/)';

    public function notifyPackage(int $wooOrderId, string $carrier, string $tracking): array
    {
        $base = rtrim((string) config('services.woocommerce.url'), '/');
        if (! in_array(parse_url($base, PHP_URL_SCHEME), ['https', 'http'], true)
            || trim((string) config('services.woocommerce.consumer_key')) === ''
            || trim((string) config('services.woocommerce.consumer_secret')) === ''
            || $wooOrderId < 1) {
            throw new RuntimeException('Customer Note requires a configured WooCommerce order.');
        }
        $carrier = $this->clean($carrier);
        $tracking = $this->clean($tracking);
        if ($carrier === '' || $tracking === '') {
            throw new RuntimeException('Package notification requires carrier and Tracking.');
        }

        // Stable text makes a timed-out POST safely retryable by reading the
        // *remote customer notes* first. Do not expose ERP/child IDs or SKUs.
        $note = "Shipping update: a package from your order is on its way.\n"
            ."Courier: {$carrier}\n"
            ."Tracking number: {$tracking}\n"
            .'Your order may arrive in separate packages. You can view all shipment updates in your order details.';
        $uri = $base.'/wp-json/wc/v3/orders/'.$wooOrderId.'/notes';
        $client = $this->client();
        if (($found = $this->findNotice($client, $uri, $note)) !== null) {
            return ['created' => false, 'verified' => true, 'note_id' => $found];
        }

        // Only the status already verified as completed by the caller may
        // trigger an actual customer email. Fail closed on remote status drift.
        $order = $client->get($base.'/wp-json/wc/v3/orders/'.$wooOrderId)->throw()->json();
        if (! is_array($order) || ($order['status'] ?? null) !== 'completed') {
            throw new RuntimeException('Woo mother is not completed; Customer Note not sent.');
        }

        $response = $client->post($uri, ['note' => $note, 'customer_note' => true])->throw()->json();
        $id = (int) ($response['id'] ?? 0);
        if ($id < 1 || ! ($response['customer_note'] ?? false)) {
            throw new RuntimeException('WooCommerce did not confirm a customer-visible note.');
        }
        $read = $client->get($uri.'/'.$id)->throw()->json();
        if (! is_array($read) || (int) ($read['id'] ?? 0) !== $id
            || ! ($read['customer_note'] ?? false)
            || $this->normalized((string) ($read['note'] ?? '')) !== $this->normalized($note)) {
            throw new RuntimeException('WooCommerce Customer Note remote readback failed.');
        }

        return ['created' => true, 'verified' => true, 'note_id' => $id];
    }

    private function findNotice(PendingRequest $client, string $uri, string $expected): ?int
    {
        // Paged remote scan is the source of truth even after an ERP timeout.
        // If history is unexpectedly very large, block rather than risk a
        // duplicate customer email.
        for ($page = 1; $page <= 5; $page++) {
            $rows = $client->get($uri, [
                'per_page' => 100, 'page' => $page,
            ])->throw()->json();
            if (! is_array($rows)) {
                throw new RuntimeException('WooCommerce notes response is invalid.');
            }
            foreach ($rows as $row) {
                if (is_array($row) && ($row['customer_note'] ?? false)
                    && (int) ($row['id'] ?? 0) > 0
                    && $this->normalized((string) ($row['note'] ?? '')) === $this->normalized($expected)) {
                    return (int) $row['id'];
                }
            }
            if (count($rows) < 100) {
                return null;
            }
        }
        throw new RuntimeException('WooCommerce notes history too large for safe deduplication.');
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value);
    }

    private function normalized(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['User-Agent' => self::UserAgent])
            ->withBasicAuth(
                (string) config('services.woocommerce.consumer_key'),
                (string) config('services.woocommerce.consumer_secret'),
            )->timeout(max(1, (int) config('services.woocommerce.timeout', 15)));
    }
}
