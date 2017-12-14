<?php


use Everest\App\Provider\RouterProvider;
use Everest\Http\Responses\Response;
use Everest\Http\Requests\RequestInterface;
use Everest\Http\Requests\ServerRequest;
use Everest\Http\Requests\Request;
use Everest\Http\Uri;

use Everest\Container\Container;

/**
 * @author  Philipp Steingrebe <philipp@steingrebe.de>
 */
class RouterProviderTest extends \PHPUnit_Framework_TestCase {

	public function getContainer()
	{
		return (new Container)
		->factory('Request', [function(){
	    return new ServerRequest(
	      ServerRequest::HTTP_ALL, 
	      Uri::from('http://steingrebe.de/prefix/test?some=value#hash')
	    );
		}])
		->value('TestValue', 'test-value')
		->provider('Router', new RouterProvider);
	}

	public function testInstanceSetup()
	{
		$container = $this->getContainer();
		$this->assertInstanceOf(RouterProvider::CLASS, $container['Router']);
	}

	public function testRoutingAcceptsDependencies()
	{
		$container = $this->getContainer();
		$called = false;

		$container->config(['RouterProvider', function($router) use (&$called) {
			$router->get('prefix/{id}', ['TestValue', 
				function(RequestInterface $request, $testValue) use (&$called) {
					$called = true;
					$this->assertEquals(['id' => 'test'], $request->getAttribute('parameter'));
					$this->assertEquals('test-value', $testValue);
					return 'Not Empty Result';
				}
			]);
		}]);



		$container['Router']->handle($container->Request);
		$this->assertTrue($called);
	}

	public function testDefaultHandlerAcceptsDependencies()
	{
		$container = $this->getContainer();

		$container->config(['RouterProvider', function($router) {
			$router->otherwise(['Request', 'TestValue', function($request, $testValue){
				$this->assertInstanceOf(RequestInterface::CLASS, $request);
				$this->assertEquals('test-value', $testValue);

				return 'not-empty-result';
			}]);
		}]);

		$response = $container['Router']->handle($container->Request);

		$this->assertInstanceOf(Response::CLASS, $response);
	}

	public function testContextAcceptsDependencies()
	{
		$container = $this->getContainer();

		$container->config(['RouterProvider', function($router) {
			$test = $this;

			$router->context('prefix', ['TestValue', function(Router $router, $testValue){
				$this->assertEquals('test-value', $testValue);
			}]);

		}]);

		$response = $container['Router'];
	}

	public function testDelegates()
	{
		$λ = function(){};

		$app = new Everest\App\App;
		$app->context('some-context', $λ);
		$app->request('/', ServerRequest::HTTP_POST | ServerRequest::HTTP_DELETE, $λ);
		$app->get('/', $λ);
		$app->post('/', $λ);
		$app->put('/', $λ);
		$app->delete('/', $λ);
		$app->any('/', $λ);
		$app->otherwise($λ);
	}

	public function testMiddleware()
	{
		$ok = '';
		$container = $this->getContainer();

		$container->value('B', 'B');
		$container->value('C', 'C');
		$container->factory('Middleware', ['B', function($b) use (&$ok) {
			return function(\Closure $next, Request $request)  use ($b, &$ok) {
				$ok .= $b;
				return $next($request);
			};
		}]);
		
		$container->config(['RouterProvider', function($router) use (&$ok) {
			// Classic middleware
			$router->before(function(\Closure $next, Request $request) use (&$ok){
				$ok .= 'A';
				return $next($request);
			});

			// Predefined middleware
			$router->before('Middleware');

			// Middleware with dependencies
			$router->before(['C', function(\Closure $next, Request $request, string $c)  use (&$ok) {
				$ok .= $c;
				return $next($request);
			}]);

			$router->otherwise(function() use (&$ok) {
				return $ok .= 'D';
			});
		}]);

		$response = $container['Router']->handle($container->Request);

		$this->assertEquals('ABCD', $ok);
	}
}
