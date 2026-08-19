<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Exceptions\HttpResponseException;
use Core\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testSetHeaderRejectsInvalidHeaderName(): void
    {
        $response = new Response();

        $this->expectException(InvalidArgumentException::class);
        $response->setHeader("Bad\r\nName", 'value');
    }

    public function testJsonResponseThrowsHttpResponseExceptionWithCorrectPayload(): void
    {
        $response = new Response();

        try {
            $response->json(['success' => true, 'message' => 'ok'], 201);
            $this->fail('Expected HttpResponseException was not thrown');
        } catch (HttpResponseException $e) {
            $sentResponse = $e->getResponse();
            $this->assertSame(201, $sentResponse->getStatusCode());
            $expected = json_encode(['success' => true, 'message' => 'ok'], JSON_UNESCAPED_UNICODE);
            $this->assertIsString($expected);
            $this->assertJsonStringEqualsJsonString(
                $expected,
                $sentResponse->getContent()
            );
        }
    }

    public function testNoContentThrowsHttpResponseExceptionWithStatus204(): void
    {
        $response = new Response();

        try {
            $response->noContent();
            $this->fail('Expected HttpResponseException was not thrown');
        } catch (HttpResponseException $e) {
            $sentResponse = $e->getResponse();
            $this->assertSame(204, $sentResponse->getStatusCode());
            $this->assertSame('', $sentResponse->getContent());
        }
    }

    public function testRedirectRejectsOpenRedirectOutsideSameDomain(): void
    {
        $response = new Response();
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(InvalidArgumentException::class);
        $response->redirect('http://evil.com', 302);
    }

    public function testRedirectExternalAllowsAbsoluteUrl(): void
    {
        $response = new Response();
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';

        try {
            $response->redirectExternal('https://accounts.google.com/o/oauth2/v2/auth?test=1');
            $this->fail('Expected HttpResponseException was not thrown');
        } catch (HttpResponseException $e) {
            $sentResponse = $e->getResponse();
            $this->assertSame(302, $sentResponse->getStatusCode());
        }
    }

    public function testDownloadRejectsPathTraversalOutsideAllowedPaths(): void
    {
        $response = new Response();
        $tmpFile = tempnam(sys_get_temp_dir(), 'chortke_test_');
        file_put_contents($tmpFile, 'test');

        $this->expectException(InvalidArgumentException::class);
        $response->download($tmpFile, 'download.txt');
    }

    public function testStatusCodeValidationRejectsBadStatusCode(): void
    {
        $response = new Response();

        $this->expectException(InvalidArgumentException::class);
        $response->setStatusCode(700);
    }
}
