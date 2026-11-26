<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalHttpFeedbackTest extends TestCase
{
    public function test_inertia_conflict_preserves_status_and_public_reason(): void
    {
        config()->set('app.debug', false);
        Route::get('/testing/feedback-conflict', fn () => abort(409, '订单已发货，不可修改。'));
        $this->withHeaders(['X-Inertia'=>'true', 'X-Requested-With'=>'XMLHttpRequest', 'Accept'=>'text/html, application/xhtml+xml', 'X-ERP-Request-ID'=>'feedback-test-409'])
            ->get('/testing/feedback-conflict')->assertStatus(409)
            ->assertHeader('X-ERP-Request-ID','feedback-test-409')
            ->assertJsonPath('erp_feedback',true)->assertJsonPath('message','订单已发货，不可修改。');
    }

    public function test_direct_browser_errors_render_the_feedback_view_without_changing_status(): void
    {
        config()->set('app.debug', false);
        foreach ([403,404,409] as $status) {
            Route::get('/testing/feedback-'.$status, fn () => abort($status));
            $this->get('/testing/feedback-'.$status, ['Accept'=>'text/html'])
                ->assertStatus($status)->assertSee('HttpFeedback');
        }
    }

    public function test_json_contract_and_inertia_location_are_preserved(): void
    {
        Route::get('/testing/feedback-api', fn () => abort(409, '订单状态已变化。'));
        $this->getJson('/testing/feedback-api')->assertStatus(409)->assertJsonPath('message','订单状态已变化。');
        Route::get('/testing/feedback-location', fn () => response('', 409, ['X-Inertia-Location'=>'/dashboard']));
        $this->get('/testing/feedback-location', ['X-Inertia'=>'true', 'Accept'=>'text/html'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location','/dashboard');
    }
}
