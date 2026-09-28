<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit;

use Kodhe\Framework\Exceptions\Http\NotFoundException;
use Kodhe\Framework\Http\Controllers\RESTController;
use Kodhe\Framework\Http\JsonResponse;
use Kodhe\Framework\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub used to exercise the RESTController helpers.
 */
class StubRestController extends RESTController
{
    protected string $resourceName = 'post';

    public function index(): JsonResponse
    {
        return $this->respond([['id' => 1], ['id' => 2]], ['total' => 2]);
    }

    public function store(): JsonResponse
    {
        $input = $this->getRequest()->all();

        if (empty($input['title'])) {
            return $this->respondValidation(['title' => ['The title field is required.']]);
        }

        return $this->respondCreated(array_merge(['id' => 3], $input));
    }

    public function show($id): JsonResponse
    {
        if ((string) $id === '404') {
            return $this->respondNotFound($id);
        }

        return $this->respond(['id' => $id, 'title' => 'Hello']);
    }

    public function update($id): JsonResponse
    {
        return $this->respond(['id' => $id, 'title' => $this->getRequest()->input('title', 'updated')]);
    }

    public function destroy($id): JsonResponse
    {
        return $this->respondNoContent();
    }

    // Expose protected helpers for testing
    public function callRespondException(\Throwable $e): JsonResponse
    {
        return $this->respondException($e);
    }

    public function callMethodNotAllowed(array $allowed = []): JsonResponse
    {
        return $this->methodNotAllowed($allowed);
    }

    public function callNotImplemented(string $action): JsonResponse
    {
        return $this->notImplemented($action);
    }

    public function callRespondError(string $message, int $status = 400, string $code = 'BAD_REQUEST', array $data = []): JsonResponse
    {
        return $this->respondError($message, $status, $code, $data);
    }
}

/**
 * Subclass that leaves every endpoint at its default implementation.
 */
class BareRestController extends RESTController
{
}

class RESTControllerTest extends TestCase
{
    protected function createController(array $server = [], array $get = [], array $post = []): StubRestController
    {
        $controller = new StubRestController();
        $controller->setRequest(new Request($get, $post, [], [], $server + ['REQUEST_METHOD' => 'GET']));

        return $controller;
    }

    protected function decode(JsonResponse $response)
    {
        return json_decode($response->getBody(), true);
    }

    public function testIndexReturnsSuccessEnvelopeWithMeta(): void
    {
        $controller = $this->createController();
        $response = $controller->index();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $decoded = $this->decode($response);
        $this->assertTrue($decoded['success']);
        $this->assertCount(2, $decoded['data']);
        $this->assertEquals(['total' => 2], $decoded['meta']);
    }

    public function testStoreUsesRequestInputAndReturns201(): void
    {
        $controller = $this->createController(
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            [],
            ['title' => 'First post']
        );
        $response = $controller->store();

        $this->assertEquals(201, $response->getStatus());
        $decoded = $this->decode($response);
        $this->assertTrue($decoded['success']);
        $this->assertEquals('First post', $decoded['data']['title']);
    }

    public function testStoreReturns422OnValidationError(): void
    {
        $controller = $this->createController(
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            [],
            []
        );
        $response = $controller->store();

        $this->assertEquals(422, $response->getStatus());
        $decoded = $this->decode($response);
        $this->assertFalse($decoded['success']);
        $this->assertEquals('VALIDATION_ERROR', $decoded['error']['code']);
        $this->assertArrayHasKey('title', $decoded['error']['data']['errors']);
    }

    public function testShowReturnsData(): void
    {
        $controller = $this->createController();
        $response = $controller->show(5);

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(5, $this->decode($response)['data']['id']);
    }

    public function testRespondNotFoundBuilds404Envelope(): void
    {
        $controller = $this->createController();
        $response = $controller->show('404');

        $this->assertEquals(404, $response->getStatus());
        $decoded = $this->decode($response);
        $this->assertEquals('NOT_FOUND', $decoded['error']['code']);
        $this->assertStringContainsString('Post [404] not found', $decoded['error']['message']);
    }

    public function testDestroyReturnsNoContent(): void
    {
        $controller = $this->createController();
        $response = $controller->destroy(1);

        $this->assertEquals(204, $response->getStatus());
        $this->assertTrue($this->decode($response)['success']);
    }

    public function testHandleDispatchesByVerb(): void
    {
        // GET collection -> index
        $controller = $this->createController(['REQUEST_METHOD' => 'GET']);
        $this->assertEquals(200, $controller->handle()->getStatus());

        // GET single -> show
        $response = $controller->handle(9);
        $this->assertEquals(9, $this->decode($response)['data']['id']);

        // POST -> store
        $postController = $this->createController(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            [],
            ['title' => 'x']
        );
        $this->assertEquals(201, $postController->handle()->getStatus());

        // DELETE -> destroy
        $deleteController = $this->createController(['REQUEST_METHOD' => 'DELETE']);
        $this->assertEquals(204, $deleteController->handle(1)->getStatus());
    }

    public function testHandleRejectsUnknownVerb(): void
    {
        $controller = $this->createController(['REQUEST_METHOD' => 'TRACE']);
        $response = $controller->handle();

        $this->assertEquals(405, $response->getStatus());
        $this->assertEquals('METHOD_NOT_ALLOWED', $this->decode($response)['error']['code']);
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $controller = $this->createController(
            ['REQUEST_METHOD' => 'PATCH'],
            [],
            ['title' => 'patched']
        );
        // StubRestController::update() reads input('title'); in CLI tests the
        // raw body is unavailable, so the default ('updated') is returned.
        // This asserts that the PATCH verb reaches update() at all (instead of
        // producing a 405/501 from dispatching to a non-existent method).
        $response = $controller->patch(3);

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('updated', $this->decode($response)['data']['title']);
    }

    public function testUnimplementedEndpointsReturn501(): void
    {
        $bare = new BareRestController();
        $bare->setRequest(new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']));

        foreach (['index', 'store', 'show', 'update', 'destroy'] as $action) {
            $response = $action === 'index' || $action === 'store' ? $bare->{$action}() : $bare->{$action}(1);
            $this->assertEquals(501, $response->getStatus(), "{$action} should be 501");
            $this->assertEquals('NOT_IMPLEMENTED', $this->decode($response)['error']['code']);
        }
    }

    public function testRespondExceptionWithBaseException(): void
    {
        $controller = $this->createController();
        $response = $controller->callRespondException(NotFoundException::resource('post', 12));

        $this->assertEquals(404, $response->getStatus());
        $decoded = $this->decode($response);
        $this->assertEquals('NOT_FOUND', $decoded['error']['code']);
    }

    public function testRespondExceptionWithGenericThrowable(): void
    {
        $controller = $this->createController();
        $response = $controller->callRespondException(new \RuntimeException('boom'));

        $this->assertEquals(500, $response->getStatus());
        $decoded = $this->decode($response);
        $this->assertEquals('INTERNAL_ERROR', $decoded['error']['code']);
        $this->assertEquals('boom', $decoded['error']['message']);
    }

    public function testMethodNotAllowedSetsAllowHeader(): void
    {
        $controller = $this->createController();
        $response = $controller->callMethodNotAllowed(['GET', 'POST']);

        $this->assertEquals(405, $response->getStatus());
        $this->assertEquals('GET, POST', $response->getHeader('Allow'));
    }

    public function testNotImplementedMessageContainsClassAndAction(): void
    {
        $controller = $this->createController();
        $response = $controller->callNotImplemented('export');

        $this->assertEquals(501, $response->getStatus());
        $this->assertStringContainsString('export', $this->decode($response)['error']['message']);
        $this->assertStringContainsString(StubRestController::class, $this->decode($response)['error']['message']);
    }

    public function testRespondErrorCustomCodeAndData(): void
    {
        $controller = $this->createController();
        $response = $controller->callRespondError('Conflict detected', 409, 'CONFLICT', ['field' => 'slug']);

        $this->assertEquals(409, $response->getStatus());
        $decoded = $this->decode($response);
        $this->assertEquals('CONFLICT', $decoded['error']['code']);
        $this->assertEquals('slug', $decoded['error']['data']['field']);
    }

    public function testContentTypeIsAlwaysJson(): void
    {
        $controller = $this->createController();

        foreach ([$controller->index(), $controller->show(1), $controller->destroy(1)] as $response) {
            $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));
        }
    }
}
