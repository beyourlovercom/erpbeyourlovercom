<?php

namespace App\Modules\B2B\Experience\Controllers;

use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class B2BCompanyController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $query = B2BOrganization::query()->whereNull('archived_at');
        if (! $request->user()->hasPermission('b2b.view_all')) {
            $userId = $request->user()->getAuthIdentifier();
            $query->where(function ($visible) use ($userId): void {
                $visible->where('owner_id', $userId)
                    ->orWhereHas('cases', fn ($cases) => $cases->where('owner_id', $userId));
            });
        }
        if ($search !== '') {
            $query->where('display_name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
        }

        return Inertia::render('B2B/Companies/Index', [
            'organizations' => $query->withCount('contacts')
                ->orderByDesc('updated_at')->paginate(30)->withQueryString()
                ->through(fn (B2BOrganization $company): array => [
                    'id' => $company->id,
                    'name' => $company->display_name,
                    'country_code' => $company->country_code,
                    'stage' => $company->stage,
                    'contacts_count' => $company->contacts_count,
                ]),
            'search' => $search,
            'canOperate' => $request->user()->hasPermission('b2b.operate'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('B2B/Companies/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'country_code' => ['nullable', 'regex:/^[A-Za-z]{2}$/'],
            'website_url' => ['nullable', 'url:http,https', 'max:500'],
            'contact_name' => ['nullable', 'required_with:contact_email', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
        ]);

        $organization = DB::transaction(function () use ($data, $request): B2BOrganization {
            $organization = B2BOrganization::create([
                'display_name' => trim($data['display_name']),
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'website_url' => $data['website_url'] ?? null,
                'owner_id' => $request->user()->getAuthIdentifier(),
                'stage' => 'prospect',
            ]);
            if (! empty($data['contact_name'])) {
                $organization->contacts()->create([
                    'name' => trim($data['contact_name']),
                    'email_normalized' => isset($data['contact_email']) ? strtolower(trim($data['contact_email'])) : null,
                    'phone' => $data['contact_phone'] ?? null,
                    'is_primary' => true,
                ]);
            }

            return $organization;
        });

        return redirect()->route('b2b.companies.show', $organization->id)->with('success', '企业客户已创建');
    }

    public function show(Request $request, B2BOrganization $organization): Response
    {
        $this->assertCanSee($request, $organization);
        $canManageCompany = $request->user()->hasPermission('b2b.view_all')
            || (int) $organization->owner_id === (int) $request->user()->getAuthIdentifier();
        // A salesperson assigned to ONE Case may read that company but must
        // not learn unrelated colleagues' business contacts in the same company.
        $contacts = $organization->contacts()->orderByDesc('is_primary')->orderBy('id');
        if (! $canManageCompany) {
            $allowedContactIds = $organization->cases()
                ->where('owner_id', $request->user()->getAuthIdentifier())
                ->whereNotNull('primary_contact_id')
                ->pluck('primary_contact_id');
            $contacts->whereIn('id', $allowedContactIds);
        }

        return Inertia::render('B2B/Companies/Show', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->display_name,
                'country_code' => $organization->country_code,
                'website_url' => $organization->website_url,
                'stage' => $organization->stage,
                'contacts' => $contacts
                    ->get(['id', 'name', 'job_title', 'email_normalized', 'phone', 'is_primary'])->toArray(),
            ],
            'canOperate' => $request->user()->hasPermission('b2b.operate') && $canManageCompany,
        ]);
    }

    public function storeContact(Request $request, B2BOrganization $organization): RedirectResponse
    {
        $this->assertCanSee($request, $organization);
        // Assigned-case access grants company read access, not the right to change
        // another salesperson's entire company/contact directory.
        if (! $request->user()->hasPermission('b2b.view_all')) {
            abort_unless((int) $organization->owner_id === (int) $request->user()->getAuthIdentifier(), 403);
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('b2b_contacts', 'email_normalized')->where('organization_id', $organization->id)],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);
        $organization->contacts()->create([
            'name' => trim($data['name']),
            'job_title' => $data['job_title'] ?? null,
            'email_normalized' => isset($data['email']) ? strtolower(trim($data['email'])) : null,
            'phone' => $data['phone'] ?? null,
            'is_primary' => $organization->contacts()->doesntExist(),
        ]);

        return back()->with('success', '联系人已添加');
    }

    private function assertCanSee(Request $request, B2BOrganization $organization): void
    {
        abort_if($organization->archived_at !== null, 404);
        if (! $request->user()->hasPermission('b2b.view_all')) {
            $actorId = $request->user()->getAuthIdentifier();
            $isOwner = (int) $organization->owner_id === (int) $actorId;
            $hasAssignedCase = $organization->cases()->where('owner_id', $actorId)->exists();
            abort_unless($isOwner || $hasAssignedCase, 404);
        }
    }
}
