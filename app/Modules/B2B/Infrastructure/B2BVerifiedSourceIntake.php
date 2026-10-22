<?php

namespace App\Modules\B2B\Infrastructure;

use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal adapter for future VERIFIED provider events only.
 * No HTTP route, mailbox reader or webhook is registered in M1-M5.
 */
final class B2BVerifiedSourceIntake
{
    private const ALLOWED_CHANNELS = [
        'website_form', 'email', 'whatsapp_business', 'trade_show', 'referral', 'other',
    ];

    public function ingest(
        string $channel,
        string $connection,
        string $externalId,
        B2BOrganization $organization,
        int $ownerId,
        string $title,
    ): B2BCase {
        $channel = trim($channel);
        $connection = trim($connection);
        $externalId = trim($externalId);
        $title = trim($title);
        if (! in_array($channel, self::ALLOWED_CHANNELS, true)
            || $connection === '' || strlen($connection) > 127
            || $externalId === '' || strlen($externalId) > 191
            || $title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Invalid verified source identity');
        }

        if ($organization->archived_at !== null || ! $organization->exists) {
            throw new DomainException('Invalid organization');
        }

        $dedupe = hash('sha256', $channel."\0".$connection."\0".$externalId);

        return DB::transaction(function () use ($channel, $connection, $externalId, $organization, $ownerId, $title, $dedupe): B2BCase {
            // createOrFirst relies on the source_dedupe_key UNIQUE index, handling
            // both serial replays and concurrent insert collisions.
            $case = B2BCase::query()->createOrFirst(
                ['source_dedupe_key' => $dedupe],
                [
                    'organization_id' => $organization->id,
                    'owner_id' => $ownerId,
                    'title' => $title,
                    'source_channel' => $channel,
                    'source_connection_key' => $connection,
                    'source_external_id' => $externalId,
                    'source_site_key' => null,
                    'country_code' => $organization->country_code,
                    'case_status' => 'open',
                    'case_stage' => 'new',
                    'handling_state' => 'waiting',
                    'first_inbound_at' => now(),
                    'last_inbound_at' => now(),
                ],
            );

            if ((int) $case->organization_id !== (int) $organization->getKey()
                || $case->source_channel !== $channel
                || $case->source_connection_key !== $connection
                || $case->source_external_id !== $externalId) {
                // Do not silently transfer a conflicting external identity
                // to an unrelated Company or Case.
                throw new DomainException('Provider source identity conflict');
            }

            if ($case->case_number === null) {
                $case->forceFill([
                    'case_number' => 'CASE-'.now()->format('Ymd').'-'.str_pad((string) $case->id, 6, '0', STR_PAD_LEFT),
                ])->save();

                $case->activities()->create([
                    'actor_user_id' => null,
                    'activity_type' => 'verified_source_ingested',
                    'body_text' => null,
                    'metadata' => [
                        'channel' => $channel,
                        'connection' => $connection,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            return $case;
        });
    }
}
