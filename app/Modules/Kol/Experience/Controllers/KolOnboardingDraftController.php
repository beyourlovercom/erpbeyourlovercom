<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\SmartInput\OnboardingDraft;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

final class KolOnboardingDraftController
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'smart' => ['required','array:username_hint,social_accounts,recipient_name,country,province_state,city,address_line_1,address_line_2,postal_code,email,phone,recipient_customs_id,coupon_code,product_hint'],
            'smart.username_hint' => ['nullable','string','max:100'],
            'smart.social_accounts' => ['nullable','array','max:10'],
            'smart.social_accounts.*' => ['array:platform,handle,profile_url,followers'],
            'smart.social_accounts.*.platform' => ['required','string','in:twitter,instagram,tiktok,youtube,facebook,other'],
            'smart.social_accounts.*.handle' => ['required','string','max:100'],
            'smart.social_accounts.*.profile_url' => ['required','url:http,https','max:500'],
            'smart.social_accounts.*.followers' => ['nullable','integer','min:0'],
            'smart.recipient_name' => ['nullable','string','max:255'],
            'smart.country' => ['nullable','string','max:128'],
            'smart.province_state' => ['nullable','string','max:128'],
            'smart.city' => ['nullable','string','max:128'],
            'smart.address_line_1' => ['nullable','string','max:255'],
            'smart.address_line_2' => ['nullable','string','max:255'],
            'smart.postal_code' => ['nullable','string','max:32'],
            'smart.email' => ['nullable','email','max:255'],
            'smart.phone' => ['nullable','string','max:64'],
            'smart.recipient_customs_id' => ['nullable','string','max:128'],
            'smart.coupon_code' => ['nullable','string','max:255'],
            'smart.product_hint' => ['nullable','string','max:255'],
            'order' => ['nullable','array:order_date,order_note,currency,items'],
            'order.order_date' => ['nullable','date'],
            'order.order_note' => ['nullable','string','max:5000'],
            'order.currency' => ['nullable','string','max:8'],
            'order.items' => ['nullable','array','max:50'],
            'order.items.*' => ['array:product_summary,sku,product_id,quantity,unit_price'],
            'order.items.*.product_summary' => ['nullable','string','max:500'],
            'order.items.*.sku' => ['nullable','string','max:128'],
            'order.items.*.product_id' => ['nullable','integer','min:1'],
            'order.items.*.quantity' => ['required','integer','min:1'],
            'order.items.*.unit_price' => ['required','numeric','min:0'],
        ]);
        $token = OnboardingDraft::save($request, $data);
        return redirect()->route('kol.library.create', ['onboarding_token'=>$token]);
    }
}
