<?php

namespace App\Tests;

use App\Command\WebServerCommand;
use React\EventLoop\LoopInterface;
use React\Http\{Response, Server};
use RingCentral\Psr7\ServerRequest;
use Symfony\Bundle\FrameworkBundle\{Test\KernelTestCase, Console\Application};
use Symfony\Component\Console\{Exception\RuntimeException, Tester\CommandTester};

class CommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        parent::setUp();
    }

    private function getMethod(object $class, string $methodName): \ReflectionMethod
    {
        if (!$class instanceof \ReflectionObject) {
            $class = new \ReflectionObject($class);
        }

        try {
            $method = $class->getMethod($methodName);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException($e->getMessage());
        }
        $method->setAccessible(true);

        return $method;
    }

    public function testRequestHeadersMethod(): void
    {
        $service = self::$container->get(WebServerCommand::class);
        $method = $this->getMethod($service, 'getHeaders');

        $result = $method->invokeArgs($service, [[]]);
        $this->assertArrayHasKey('Content-type', $result);
        $this->assertContains('application/json', $result);

        $anotherResult = $method->invokeArgs($service, [['Accept' => ['application/ld+json']]]);
        $this->assertContains('application/ld+json', $anotherResult);
    }

    public function testGetContentMethodWithExistingFile(): void
    {
        $content = 'Test content';
        \file_put_contents(sprintf('%s/var/test-content.json', self::$container->getParameter('kernel.project_dir')), $content);
        $service = self::$container->get(WebServerCommand::class);
        $this->getMethod($service, 'checkDir')
            ->invokeArgs($service, ['var']);

        $request = new ServerRequest('GET', 'test/content');
        $getContent = $this->getMethod($service, 'getContent');
        $result = $getContent->invokeArgs($service, [$request]);
        $this->assertEquals($content, $result);
    }

    public function testGetContentMethodWithNotExistingFile(): void
    {
        $service = self::$container->get(WebServerCommand::class);
        $this->getMethod($service, 'checkDir')
            ->invokeArgs($service, ['var']);

        $request = new ServerRequest('GET', 'test/content/not/exists.json');
        $getContent = $this->getMethod($service, 'getContent');
        $result = $getContent->invokeArgs($service, [$request]);
        $this->assertNull($result);
    }

    public function testCheckDirMethodWithNotExistsDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $service = self::$container->get(WebServerCommand::class);
        $this->getMethod($service, 'checkDir')
            ->invokeArgs($service, ['/directory/is/not/exists']);
    }

    public function testProcessRequestMethodWithNormalResponse(): void
    {
        $content = \json_encode(['foo' => 'bar', 'valid' => true], JSON_THROW_ON_ERROR);
        \file_put_contents(sprintf('%s/var/test-content.json', self::$container->getParameter('kernel.project_dir')), $content);
        $service = self::$container->get(WebServerCommand::class);
        $this->getMethod($service, 'checkDir')
            ->invokeArgs($service, ['var']);

        $request = new ServerRequest('GET', 'test/content');
        $processRequest = $this->getMethod($service, 'processRequest');

        /** @var Response $response */
        $response = $processRequest->invokeArgs($service, [$request]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($content, $response->getBody()->getContents());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testProcessMethodWithNotFoundResponse(): void
    {
        $service = self::$container->get(WebServerCommand::class);
        $this->getMethod($service, 'checkDir')
            ->invokeArgs($service, ['var']);
        $request = new ServerRequest('GET', 'test/content/does/not/exists');
        $processRequest = $this->getMethod($service, 'processRequest');
        /** @var Response $response */
        $response = $processRequest->invokeArgs($service, [$request]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testMakeSocketMethod(): void
    {
        $service = self::$container->get(WebServerCommand::class);
        $makeSocket = $this->getMethod($service, 'makeSocket');
        $this->assertInstanceOf(\React\Socket\Server::class, $makeSocket->invokeArgs($service, ['0.0.0.0', 8888]));
    }

    public function testMakeServerMethod(): void
    {
        $service = self::$container->get(WebServerCommand::class);
        $makeServer = $this->getMethod($service, 'makeServer');
        $this->assertInstanceOf(Server::class, $makeServer->invoke($service));
    }

    public function testGetLoopInstance(): void
    {
        $loop = self::$container->get(LoopInterface::class);
        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testCommandExecuteMethod(): void
    {
        $service = self::$container->get(WebServerCommand::class);
        try {
            $reflectionLoop = (new \ReflectionObject($service))->getProperty('loop');
        } catch (\ReflectionException $e) {
            throw new \RuntimeException($e->getMessage());
        }
        $reflectionLoop->setAccessible(true);
        /** @var LoopInterface $loop */
        $loop = $reflectionLoop->getValue($service);
        $loop->addTimer(.1, static function () use ($loop) { $loop->stop(); });

        $app = new Application(self::$kernel);
        $command = $app->find('app:web-server');
        $tester = new CommandTester($command);
        $tester->execute([], ['verbosity' => 256]);
        $this->assertSame(0, $tester->getStatusCode());
    }
}
