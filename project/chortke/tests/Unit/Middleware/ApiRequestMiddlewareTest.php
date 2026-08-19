<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\ApiRequestMiddleware;
use Core\Request;
use Core\Response;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class ApiRequestMiddlewareTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_unsupported_version_returns_structured_json_without_calling_next(): void
    {
        $request=$this->request('GET','/api/v99/users',['Accept-Version'=>'v99','Accept'=>'application/json']);
        $response=(new ApiRequestMiddleware())->handle($request,function(){ $this->fail('next must not run'); });
        $this->assertSame(400,$response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8',$response->getHeader('Content-Type'));
        $payload=$this->decodeArray($response->getContent());
        $this->assertFalse((bool)$payload['success']);
        $this->assertSame('UNSUPPORTED_API_VERSION',$this->requireArray($payload['error'] ?? null)['code']);
    }

    public function test_state_changing_request_rejects_unsupported_content_type(): void
    {
        $request=$this->request('POST','/api/v1/users',['Content-Type'=>'text/plain','Accept'=>'application/json']);
        $response=(new ApiRequestMiddleware())->handle($request,function(){ $this->fail('next must not run'); });
        $this->assertSame(415,$response->getStatusCode());
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE',$this->requireArray($this->decodeArray($response->getContent())['error'] ?? null)['code']);
    }

    public function test_non_json_accept_header_returns_406(): void
    {
        $request=$this->request('GET','/api/v1/users',['Accept'=>'text/html']);
        $response=(new ApiRequestMiddleware())->handle($request,function(){ $this->fail('next must not run'); });
        $this->assertSame(406,$response->getStatusCode());
        $this->assertSame('NOT_ACCEPTABLE',$this->requireArray($this->decodeArray($response->getContent())['error'] ?? null)['code']);
    }

    /** @dataProvider acceptedContentTypes */
    public function test_supported_requests_reach_next_and_receive_keepalive_headers(string $contentType): void
    {
        $request=$this->request('POST','/api/v2/users',['Content-Type'=>$contentType,'Accept'=>'application/json']);
        $request->shouldReceive('setAttribute')->once()->with('api_version','v2');
        $nextCalled=false;
        $response=(new ApiRequestMiddleware())->handle($request,function()use(&$nextCalled){$nextCalled=true;return (new Response())->setContent('{"ok":true}');});
        $this->assertTrue($nextCalled);
        $this->assertSame('keep-alive',$response->getHeader('Connection'));
        $this->assertSame('timeout=10, max=500',$response->getHeader('Keep-Alive'));
    }

    /** @return list<array{string}> */
    public function acceptedContentTypes(): array
    {
        return [['application/json'],['multipart/form-data; boundary=x'],['application/x-www-form-urlencoded'],['']];
    }

    /** @return array<int|string,mixed> */
    private function decodeArray(string $json): array
    {
        $decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }
    /** @return array<int|string,mixed> */
    private function requireArray(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }

    /** @param array<string,string> $headers
     *  @return Request&\Mockery\MockInterface */
    private function request(string $method,string $uri,array $headers): Request
    {
        $request=m::mock(Request::class);
        $request->shouldReceive('method')->andReturn($method);
        $request->shouldReceive('uri')->andReturn($uri);
        $request->shouldReceive('header')->andReturnUsing(static fn(string $name)=>$headers[$name]??null);
        $request->shouldReceive('setAttribute')->byDefault();
        return $request;
    }
}
