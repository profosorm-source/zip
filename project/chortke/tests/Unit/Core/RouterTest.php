<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Container;
use Core\Request;
use Core\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private Container $container;
    private Request $request;
    private Router $router;

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        // Use the real application container. Replacing Container::$instance
        // creates a second container while app() still points to the first one.
        $this->container = \Core\Application::getInstance()->container;
        $this->request = new Request();
        $this->router = new Router($this->request, $this->container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testMatchRouteExtractsIntParameter(): void
    {
        $params = $this->invokePrivateMethod($this->router, 'matchRoute', ['/users/{id}', '/users/42']);

        $this->assertSame(['id' => '42'], $params);
    }

    public function testMatchRouteRejectsPathTraversalSegment(): void
    {
        $result = $this->invokePrivateMethod($this->router, 'matchRoute', ['/files/{path}', '/files/..%2Fsecret']);

        $this->assertFalse($result);
    }

    public function testUriNormalizationInRequestNotRouter(): void
    {
        // URI normalization منتقل شد به Request::parseUri — Router دیگه duplicate نداره
        $this->assertFalse(
            method_exists($this->router, 'normalizeUri') && (new \ReflectionMethod($this->router, 'normalizeUri'))->isPrivate(),
            'Router نباید normalizeUri داشته باشه — از Request::uri() استفاده میکنه'
        );
    }

    public function testExecuteActionClosureResolvesParams(): void
    {
        $result = $this->invokeProtectedMethod($this->router, 'executeAction', [function (string $id) {
            return 'id:' . $id;
        }, ['id' => '100']]);

        $this->assertSame('id:100', $result);
    }

    public function testExecuteActionThrowsForInvalidRouteAction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invokeProtectedMethod($this->router, 'executeAction', ['invalid-action', []]);
    }

    /**
     * @dataProvider invalidControllerActionProvider
     * @param array<mixed> $action
     */
    public function testExecuteActionRejectsInvalidControllerContract(array $action): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invokeProtectedMethod($this->router, 'executeAction', [$action, []]);
    }

    /** @return array<string, array{0: array<mixed>}> */
    public function invalidControllerActionProvider(): array
    {
        return [
            'non-string controller' => [[123, 'index']],
            'missing controller class' => [['Tests\\MissingController', 'index']],
            'empty method' => [[RouterActionFixture::class, '']],
            'private method' => [[RouterActionFixture::class, 'hidden']],
        ];
    }

    public function testRootRouteAndNestedPrefixAreCanonicalized(): void
    {
        $root = $this->router->get('', static fn(): string => 'root');
        $this->assertSame('/', $root->getUri());

        $this->router->group(['prefix' => '/admin/'], function (Router $router): void {
            $route = $router->get('/users', static fn(): string => 'users');
            $this->assertSame('/admin/users', $route->getUri());
        });
    }

    public function testGenerateNotFoundResponseReturns404(): void
    {
        $response = $this->invokePrivateMethod($this->router, 'generateNotFoundResponse', ['/missing', 'GET']);
        $this->assertInstanceOf(\Core\Response::class, $response);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('404', $response->getContent());
    }

    /** @param list<mixed> $args */
    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $methodRef = $reflection->getMethod($method);
        $methodRef->setAccessible(true);

        return $methodRef->invokeArgs($object, $args);
    }

    /** @param list<mixed> $args */
    private function invokeProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        return $this->invokePrivateMethod($object, $method, $args);
    }
}

final class RouterActionFixture
{
    public function __construct() { $this->hidden(); }

    private function hidden(): void
    {
    }
}
