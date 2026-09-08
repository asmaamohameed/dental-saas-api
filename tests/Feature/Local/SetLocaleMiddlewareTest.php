<?php

namespace Tests\Feature\Local;

use App\Http\Middleware\SetLocale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * ملحوظة: مش لاقي SetLocale متطبقة على أي route في api.php اللي شفته لحد
 * دلوقتي (الـ alias 'locale' معرّف في bootstrap/app.php بس مش مستخدم على
 * أي route ظاهر) - فالتستات دي بتستدعي الـ middleware مباشرة بدل ما تعتمد
 * على HTTP request كامل، عشان تبقى صحيحة بغض النظر عن كونها متوصلة
 * بـ route فعلي ولا لسه.
 */
class SetLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private SetLocale $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new SetLocale;
    }

    private function passThrough(Request $request): void
    {
        $this->middleware->handle($request, fn ($req) => response('ok'));
    }

    public function test_uses_accept_language_header_when_no_authenticated_user(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'ar-EG,ar;q=0.9,en;q=0.8');

        $this->passThrough($request);

        $this->assertSame('ar', App::getLocale());
    }

    public function test_falls_back_to_config_locale_when_no_header_and_no_user(): void
    {
        $request = Request::create('/', 'GET');

        $this->passThrough($request);

        $this->assertSame(config('app.locale'), App::getLocale());
    }

    public function test_falls_back_to_config_locale_for_unsupported_language(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'fr-FR,fr;q=0.9');

        $this->passThrough($request);

        $this->assertSame(config('app.locale'), App::getLocale());
    }

    public function test_authenticated_users_explicit_locale_overrides_accept_language_header(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'locale' => 'ar']);

        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->setUserResolver(fn () => $user);

        $this->passThrough($request);

        $this->assertSame('ar', App::getLocale());
    }

    public function test_falls_back_to_tenant_locale_when_user_has_no_explicit_locale(): void
    {
        $tenant = Tenant::factory()->create(['locale' => 'ar']);
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'locale' => null]);

        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->setUserResolver(fn () => $user);

        $this->passThrough($request);

        $this->assertSame('ar', App::getLocale());
    }
}
