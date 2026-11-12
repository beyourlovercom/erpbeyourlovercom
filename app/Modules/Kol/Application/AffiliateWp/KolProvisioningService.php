<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class KolProvisioningService
{
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';

    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';

    /** States from which an operator retry is accepted. */
    private const RETRYABLE = ['not_requested', 'pending', 'error', 'conflict', 'readback_mismatch', 'in_progress'];

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    public static function resolveUsername(?string $requested, string $displayName): string
    {
        $username = trim((string) $requested);
        if ($username === '') {
            $username = preg_replace('/[^A-Za-z0-9_.@-]/', '', preg_replace('/\s+/u', '-', trim($displayName)) ?? '') ?? '';
        }
        if ($username === '' || strlen($username) > 60 || ! preg_match('/^[A-Za-z0-9_.@-]+$/D', $username)) {
            throw ValidationException::withMessages([
                'wordpress_username' => 'WordPress Username 必填，最多 60 位，仅支持英文字母、数字及 . _ @ -；请手工填写有效用户名。',
            ]);
        }

        return $username;
    }

    /**
     * Resolve independent Affiliate profile inputs without pretending that an unsupported
     * custom referral slug has actually been saved. AffiliateWP Custom Slugs must be
     * integrated before accepting a value different from the WordPress username.
     *
     * @return array{name:string,slug:string}
     */
    public static function resolveAffiliateProfile(?string $requestedName, ?string $requestedSlug, string $username): array
    {
        $name = trim((string) ($requestedName === null || trim($requestedName) === '' ? $username : $requestedName));
        $slug = trim((string) ($requestedSlug === null || trim($requestedSlug) === '' ? $username : $requestedSlug));
        if ($name === '' || mb_strlen($name) > 120 || $name !== strip_tags($name)) {
            throw ValidationException::withMessages(['affiliate_name' => 'Affiliate Name 必填，最多 120 字符，不可包含 HTML 标签。']);
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $slug) || strlen($slug) > 60) {
            throw ValidationException::withMessages(['affiliate_url_slug' => 'Affiliate URL 只能包含字母、数字和 . _ -，最多 60 位。']);
        }
        // The official AffiliateWP add-on accepts alphabetic or alphanumeric slugs
        // (not separators or numeric-only aliases). Default WP usernames remain valid.
        if ($slug !== $username && ! preg_match('/^[a-z][a-z0-9]*$/D', $slug)) {
            throw ValidationException::withMessages([
                'affiliate_url_slug' => '独立 Affiliate URL 仅允许小写英文字母、数字，必须以字母开头；默认 Username 可保留原格式。',
            ]);
        }

        return ['name' => $name, 'slug' => $slug];
    }

    /**
     * Provision an approved KOL into WordPress User + active AffiliateWP Affiliate.
     *
     * Creates or reuses a stable placeholder row keyed by (connection_key, kol_id),
     * persists the idempotency key before calling the provider, then transitions to
     * active|conflict|error|readback_mismatch based on the verified read-back response.
     */
    public function provision(string $connectionKey, int $kolId, Authenticatable $actor): Model
    {
        if (! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException;
        }

        return $this->provisionInternal($connectionKey, $kolId, $actor, null, true, null, null);
    }

    public function provisionForCreate(string $connectionKey, int $kolId, Authenticatable $actor, string $password, ?string $username = null, ?string $affiliateName = null, ?string $affiliateUrlSlug = null): Model
    {
        if (! $actor->can('kol.operate')) {
            throw new AuthorizationException;
        }
        if (strlen($password) < 6 || strlen($password) > 128 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            throw new DomainException('WordPress password does not satisfy the create-provision policy.');
        }

        return $this->provisionInternal($connectionKey, $kolId, $actor, $password, true, $username, $affiliateName, $affiliateUrlSlug);
    }

    public function provisionForExisting(string $connectionKey, int $kolId, Authenticatable $actor, string $password, ?string $username = null, ?string $affiliateName = null, ?string $affiliateUrlSlug = null): Model
    {
        if (! $actor->can('kol.operate')) {
            throw new AuthorizationException;
        }
        if (strlen($password) < 6 || strlen($password) > 128 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            throw new DomainException('WordPress password does not satisfy the provision policy.');
        }

        return $this->provisionInternal($connectionKey, $kolId, $actor, $password, false, $username, $affiliateName, $affiliateUrlSlug);
    }

    private function provisionInternal(string $connectionKey, int $kolId, Authenticatable $actor, ?string $password, bool $requireApproval, ?string $username, ?string $affiliateName, ?string $affiliateUrlSlug = null): Model
    {
        $kolClass = self::KOL;
        $query = $kolClass::query()->whereKey($kolId)->whereNull('archived_at');
        if ($requireApproval) {
            $query->where('approval_status', 'approved');
        } else {
            $query->where('status', 'active');
        }
        $kol = $query->first();

        if (! $kol) {
            throw new DomainException($requireApproval
                ? 'KOL must be approved and active to provision.'
                : 'KOL must be active to provision a WordPress / Affiliate account.');
        }

        $username = self::resolveUsername($username, (string) $kol->display_name);
        $profile = self::resolveAffiliateProfile($affiliateName, $affiliateUrlSlug, $username);
        $affiliateName = $profile['name'];
        $affiliateUrlSlug = $affiliateUrlSlug !== null ? $profile['slug'] : null;

        [$account, $idempotencyKey] = DB::transaction(function () use ($connectionKey, $kolId, $kol, $username, $affiliateName, $affiliateUrlSlug): array {
            $accountClass = self::ACCOUNT;

            $existing = $accountClass::query()
                ->where('connection_key', $connectionKey)
                ->where('kol_id', $kolId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->provisioning_status === 'active') {
                    throw new DomainException('KOL is already actively provisioned on this connection.');
                }
                // Reject if the row was sourced from an external sync or explicit bind — those already have
                // an authoritative affiliate_id and must not be re-provisioned.
                if ($existing->affiliate_id !== null && $existing->origin !== 'erp_provisioned') {
                    throw new DomainException('KOL already has an externally-sourced Affiliate. Use bind or sync instead of provisioning.');
                }
                if (! in_array($existing->provisioning_status, self::RETRYABLE, true)) {
                    throw new DomainException('KOL provisioning cannot be retried from state: '.$existing->provisioning_status.'.');
                }

                // Preserve the username tied to the original idempotent provider attempt.
                if ($existing->wordpress_user_login !== null
                    && strcasecmp($existing->wordpress_user_login, $username) !== 0) {
                    throw new DomainException('Retry must use the original WordPress Username.');
                }

                // The old ERP did not separately supply Affiliate Name. Preserve its
                // stored name on an idempotent retry when the operator left the
                // new field on the username-derived default. Never rewrite it.
                if ($existing->display_name !== $affiliateName && $affiliateName !== $username) {
                    throw new DomainException('Retry must use the original Affiliate Name.');
                }

                if ($existing->affiliate_url_slug !== null && $affiliateUrlSlug !== null
                    && $existing->affiliate_url_slug !== $affiliateUrlSlug) {
                    throw new DomainException('Retry must use the original Affiliate URL.');
                }
                // Reuse the persisted idempotency key so retries never generate a second Affiliate.
                $key = $existing->provision_idempotency_key ?? Str::uuid()->toString();
                $existing->update([
                    'provision_idempotency_key' => $key,
                    'wordpress_user_login' => $existing->wordpress_user_login ?: $username,
                    'provisioning_status' => 'in_progress',
                ]);

                return [$existing, $key];
            }

            $key = Str::uuid()->toString();
            $account = $accountClass::create([
                'connection_key' => $connectionKey,
                'affiliate_id' => null,
                'kol_id' => $kolId,
                'display_name' => $affiliateName,
                'email' => $kol->contact_email,
                'wordpress_user_login' => $username,
                'affiliate_url_slug' => $affiliateUrlSlug,
                'provider_status' => null,
                'origin' => 'erp_provisioned',
                'sync_status' => 'never_synced',
                'reconciliation_status' => 'present',
                'provisioning_status' => 'in_progress',
                'provision_idempotency_key' => $key,
            ]);

            return [$account, $key];
        });

        $email = trim((string) $kol->contact_email);
        // The persisted row is the authoritative payload for retries.
        $affiliateName = (string) $account->display_name;
        $affiliateUrlSlug = $account->affiliate_url_slug;
        $payload = [
            'erp_kol_id' => $kolId,
            'email' => $email,
            'display_name' => $affiliateName,
            'preferred_username' => $username,
            'requested_affiliate_status' => 'active',
            'payment_email' => $email,
        ];
        if ($affiliateUrlSlug !== null) {
            $payload['affiliate_url_slug'] = $affiliateUrlSlug;
        }
        if ($password !== null) {
            $payload['password'] = $password;
            $payload['existing_account_policy'] = 'reject';
        }

        try {
            $response = $this->gateway->provisionAffiliate($payload, $idempotencyKey);
            unset($payload['password'], $password);
        } catch (RuntimeException $e) {
            // Detect provider-signalled Affiliate ID conflict without importing Infrastructure.
            $errorCode = property_exists($e, 'errorCode') && is_string($e->errorCode)
                ? $e->errorCode
                : 'CONNECTOR_ERROR';
            $finalStatus = $errorCode === 'AFFILIATE_ID_CONFLICT' ? 'conflict' : 'error';

            return $this->finalizeWithError($account, $finalStatus, $errorCode, $e->getMessage());
        }

        return $this->finalizeWithSuccess($account, $response->data);
    }

    private function finalizeWithSuccess(Model $account, mixed $data): Model
    {
        $affiliateId = $this->extractPositiveInt($data, ['affiliate_id']);
        $wordpressUserId = $this->extractPositiveInt($data, ['wp_user_id', 'user_id', 'wordpress_user_id']);
        $wordpressUserEmail = is_array($data)
            ? ($data['user_email'] ?? $data['wordpress_user_email'] ?? null)
            : null;
        $wordpressUserEmail = is_string($wordpressUserEmail) && $wordpressUserEmail !== '' ? $wordpressUserEmail : null;
        $status = is_array($data) ? ($data['affiliate_status'] ?? $data['status'] ?? null) : null;
        $actualUsername = is_array($data) ? ($data['user_login'] ?? $data['wordpress_user_login'] ?? null) : null;
        $requestedUsername = (string) ($account->wordpress_user_login ?? '');
        $usernameVerified = ! is_string($actualUsername) || $actualUsername === ''
            || $requestedUsername === '' || strcasecmp($requestedUsername, $actualUsername) === 0;

        $requestedSlug = $account->affiliate_url_slug;
        $slugVerified = $requestedSlug === null
            || (is_array($data) && ($data['affiliate_url_slug'] ?? null) === $requestedSlug);
        $verified = $affiliateId !== null && $wordpressUserId !== null && $status === 'active'
            && $usernameVerified && $slugVerified;

        if ($verified) {
            try {
                $account->update([
                    'affiliate_id' => $affiliateId,
                    'wordpress_user_id' => $wordpressUserId,
                    'wordpress_user_email' => $wordpressUserEmail,
                    'wordpress_user_login' => $actualUsername ?: $account->wordpress_user_login,
                    'affiliate_referral_url' => is_array($data) && is_string($data['affiliate_referral_url'] ?? null) ? $data['affiliate_referral_url'] : null,
                    'provider_status' => 'active',
                    'provisioning_status' => 'active',
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            } catch (QueryException) {
                // Unique (connection_key, affiliate_id) conflict: a mirror row already claims this ID.
                return $this->finalizeWithError(
                    $account,
                    'conflict',
                    'AFFILIATE_ID_CONFLICT',
                    'Provider affiliate_id conflicts with an existing ERP mirror row.',
                );
            }
        } else {
            $account->update([
                'affiliate_id' => $affiliateId,
                'wordpress_user_id' => $wordpressUserId,
                'wordpress_user_email' => $wordpressUserEmail,
                'provider_status' => is_string($status) ? $status : null,
                'provisioning_status' => 'readback_mismatch',
                'last_error_code' => 'READBACK_MISMATCH',
                'last_error_message' => 'Provider response did not confirm active identity and requested Affiliate URL slug.',
            ]);
        }

        return $account->fresh();
    }

    private function finalizeWithError(Model $account, string $status, string $code, string $message): Model
    {
        $account->update([
            'provisioning_status' => $status,
            'last_error_code' => $code,
            'last_error_message' => mb_substr($message, 0, 500),
        ]);

        return $account->fresh();
    }

    private function extractPositiveInt(mixed $data, array $keys): ?int
    {
        if (! is_array($data)) {
            return null;
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $val = $data[$key];
            if (is_int($val) && $val > 0) {
                return $val;
            }
            if (is_string($val) && ctype_digit($val) && (int) $val > 0) {
                return (int) $val;
            }
        }

        return null;
    }
}
