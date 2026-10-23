<?php

namespace Tests\Feature\Backoffice;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OpenApiContractTest extends TestCase
{
    public function test_openapi_version_and_all_public_mobile_api_routes_are_documented(): void
    {
        $source = file_get_contents(base_path('docs/api/openapi-v1.json'));
        $spec = json_decode($source, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('/', $spec['servers'][0]['url']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['sanctumBearer']['scheme']);

        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[] = strtoupper($method).' '.ltrim($path, '/');
            }
        }

        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'api.v1.')) {
                foreach (array_filter($route->methods(), fn (string $method): bool => $method !== 'HEAD') as $method) {
                    $actual[] = $method.' '.$route->uri();
                }
            }
        }

        sort($actual);
        sort($documented);

        $this->assertSame($actual, $documented,
            'Keep docs/api/openapi-v1.json in sync with published REST API routes.');
    }

    public function test_public_venue_filters_and_access_flags_are_documented(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/api/v1/venues']['get'];
        $parameters = collect($operation['parameters'])->keyBy('name');

        foreach (['limit', 'cursor', 'type', 'operational_status', 'access', 'search'] as $name) {
            $this->assertTrue($parameters->has($name), "Missing venue filter: {$name}");
        }

        $this->assertSame(
            ['free', 'paid', 'approval'],
            $parameters->get('access')['schema']['enum'],
        );
        $this->assertSame(
            ['active', 'temporarily_closed'],
            $parameters->get('operational_status')['schema']['enum'],
        );

        $venueFields = $spec['components']['schemas']['Venue']['properties'];
        $this->assertSame(['boolean', 'null'], $venueFields['requires_payment']['type']);
        $this->assertSame('boolean', $venueFields['requires_booking_approval']['type']);
        $this->assertSame('boolean', $venueFields['has_free_access']['type']);
        $this->assertContains('has_free_access', $spec['components']['schemas']['Venue']['required']);
    }

    public function test_login_request_and_me_bearer_access_are_described(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $spec['paths']['/api/v1/auth/login']['post']['security']);
        $this->assertSame([['sanctumBearer' => []]], $spec['paths']['/api/v1/me']['get']['security']);
        $this->assertSame([['sanctumBearer' => []]], $spec['paths']['/api/v1/auth/logout']['post']['security']);
        $this->assertArrayHasKey('password', $spec['components']['schemas']['LoginRequest']['properties']);
        $this->assertTrue($spec['components']['schemas']['LoginRequest']['properties']['password']['writeOnly']);
        $this->assertArrayNotHasKey('/api/graphql', $spec['paths']);
        $this->assertArrayNotHasKey('/api/get_venues', $spec['paths']);

        $details = $spec['paths']['/api/v1/venues/{venue}']['get'];
        $this->assertSame('getPublicVenue', $details['operationId']);
        $this->assertSame('venue', $details['parameters'][0]['name']);
        $this->assertTrue($details['parameters'][0]['required']);
        $this->assertArrayHasKey('404', $details['responses']);
        $this->assertSame(
            '#/components/schemas/VenueDetailResponse',
            $details['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/Venue',
            $spec['components']['schemas']['VenueDetailResponse']['properties']['data']['$ref'],
        );
    }
}
