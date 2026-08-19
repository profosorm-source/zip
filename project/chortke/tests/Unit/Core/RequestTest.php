<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Exceptions\PayloadTooLargeException;
use Core\Exceptions\ValidationException;
use Core\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
    }

    public function testMethodOverrideWorksWithXHttpMethodOverride(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/test';

        $request = new Request();

        $this->assertSame('PUT', $request->method());
    }

    public function testParseJsonBodyAndJsonMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/json';

        $request = new Request();
        $this->setRawInput($request, '{"name":"ali","count":2}');

        $this->assertSame(['name' => 'ali', 'count' => 2], $request->body());
        $this->assertSame(['name' => 'ali', 'count' => 2], $request->json());
        $this->assertSame('ali', $request->input('name'));
    }

    public function testInvalidJsonThrowsValidationException(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/json';

        $request = new Request();
        $this->setRawInput($request, '{"name": "ali",}');

        $this->expectException(ValidationException::class);
        $request->body();
    }

    public function testParseFormUrlencodedPutBody(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['REQUEST_URI'] = '/update';

        $request = new Request();
        $this->setRawInput($request, 'id=12&name=ahmad');

        $this->assertSame('12', $request->body('id'));
        $this->assertSame(['id' => '12', 'name' => 'ahmad'], $request->body());
    }

    public function testPayloadTooLargeThrowsPayloadTooLargeException(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/overflow';
        $_SERVER['CONTENT_LENGTH'] = '2097152';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        config_set('request.max_body_bytes', 1000000);

        $this->expectException(PayloadTooLargeException::class);
        new Request();
    }

    public function testAllCombinesQueryAndBody(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/combine';
        $_GET['page'] = '2';
        $_POST['search'] = 'keyword';

        $request = new Request();

        $this->assertSame(['page' => '2', 'search' => 'keyword'], $request->all());
        $this->assertSame('2', $request->get('page'));
        $this->assertSame('keyword', $request->post('search'));
    }

    public function testHeaderAndIpAndSecure(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/info';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $proxies = (array)config('app.trusted_proxies', ['127.0.0.1']);
        $rawProxy = reset($proxies);
        $proxy = is_string($rawProxy) ? $rawProxy : '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = explode('/', $proxy)[0];
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'localhost';

        $request = new Request();

        $this->assertSame('10.0.0.1', $request->ip());
        $this->assertTrue($request->isSecure());
        $this->assertSame('PHPUnit', $request->userAgent());
    }

    public function testHasFileIdentifiesValidUpload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/upload';
        $_FILES['avatar'] = [
            'name' => 'pic.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => sys_get_temp_dir() . '/phpunit_upload.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 123,
        ];

        $request = new Request();

        $this->assertTrue($request->hasFile('avatar'));
        $this->assertSame($_FILES['avatar'], $request->file('avatar'));
        $this->assertSame([$_FILES['avatar']], $request->uploadedFiles('avatar'));
    }

    public function testUploadedFilesNormalizesPhpMultiFileColumns(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/upload';
        $_FILES['attachments'] = [
            'name' => ['one.jpg', 'two.png'],
            'type' => ['image/jpeg', 'image/png'],
            'tmp_name' => ['/tmp/one', '/tmp/two'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [100, 200],
        ];

        $request = new Request();
        $files = $request->uploadedFiles('attachments');

        $this->assertTrue($request->hasFile('attachments'));
        $this->assertCount(2, $files);
        $this->assertSame('one.jpg', $files[0]['name']);
        $this->assertSame('/tmp/two', $files[1]['tmp_name']);
        $this->assertSame(200, $files[1]['size']);
    }

    public function testStrConvertsScalarAndFallsBackOnNonScalar(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/types';
        $_POST['name'] = 'ali';
        $_POST['age'] = 28;
        $_POST['enabled'] = 'on';
        $_POST['nested'] = ['a' => 1];
        $_POST['empty'] = '';

        $request = new Request();

        $this->assertSame('ali', $request->str('name'));
        $this->assertSame('28', $request->str('age'));
        $this->assertSame('on', $request->str('enabled'));
        $this->assertSame('ali', $request->str('name', 'default'));

        // non-scalar array falls back to default
        $this->assertSame('default', $request->str('nested', 'default'));
        // absent key falls back to default
        $this->assertSame('default', $request->str('missing', 'default'));
        // empty string preserved (not defaulted)
        $this->assertSame('', $request->str('empty'));
    }

    public function testIntConvertsNumericAndRejectsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/types';
        $_POST['count'] = '42';
        $_POST['floaty'] = '3.99';
        $_POST['bad'] = '12abc';
        $_POST['arr'] = [1, 2];
        $_POST['neg'] = '-7';

        $request = new Request();

        $this->assertSame(42, $request->int('count'));
        $this->assertSame(3, $request->int('floaty'));      // numeric string → (int) truncates
        $this->assertSame(-7, $request->int('neg'));
        $this->assertSame(0, $request->int('bad'));          // non-numeric → default
        $this->assertSame(0, $request->int('arr'));          // non-scalar → default
        $this->assertSame(99, $request->int('missing', 99)); // absent → provided default
    }

    public function testFloatConvertsNumericAndRejectsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/types';
        $_POST['price'] = '12.75';
        $_POST['intval'] = '5';
        $_POST['bad'] = 'abc';
        $_POST['obj'] = [1];
        $_POST['neg'] = '-1.25';

        $request = new Request();

        $this->assertSame(12.75, $request->float('price'));
        $this->assertSame(5.0, $request->float('intval'));
        $this->assertSame(-1.25, $request->float('neg'));
        $this->assertSame(0.0, $request->float('bad'));
        $this->assertSame(0.0, $request->float('obj'));
        $this->assertSame(7.5, $request->float('missing', 7.5));
    }

    private function setRawInput(Request $request, string $content): void
    {
        $reflection = new \ReflectionClass($request);
        $property = $reflection->getProperty('rawInput');
        $property->setAccessible(true);
        $property->setValue($request, $content);
    }
}
