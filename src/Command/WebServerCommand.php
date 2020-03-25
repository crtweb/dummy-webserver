<?php

namespace App\Command;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Http\{Response, Server};
use React\Socket\Server as SocketServer;
use Symfony\Component\Console\{
    Command\Command,
    Exception\RuntimeException,
    Input\InputArgument,
    Input\InputInterface,
    Input\InputOption,
    Output\OutputInterface,
    Style\SymfonyStyle
};
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * WebServer runs as daemon and response with file-content.
 */
class WebServerCommand extends Command
{
    protected static $defaultName = 'app:web-server';

    private SymfonyStyle $io;
    private ContainerInterface $container;
    private SluggerInterface $slugger;
    private ?string $dataDir = null;
    private LoggerInterface $logger;

    /**
     * WebServerCommand constructor.
     *
     * @param ContainerInterface $container
     * @param SluggerInterface   $slugger
     * @param LoggerInterface    $logger
     * @param string|null        $name
     */
    public function __construct(ContainerInterface $container, SluggerInterface $slugger, LoggerInterface $logger, string $name = null)
    {
        parent::__construct($name);
        $this->container = $container;
        $this->slugger = $slugger;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Simple web-server')
            ->addArgument('data-dir', InputArgument::OPTIONAL, 'Directory with files for responses', 'responses')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port for listening', 8080)
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Interface for listening', '0.0.0.0')
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info(\sprintf('Server started at %s', \date_create()->format(\DateTime::ATOM)));
        $this->io = new SymfonyStyle($input, $output);
        $this->checkDir((string) $input->getArgument('data-dir'));

        $server = new Server(function (ServerRequestInterface $request) {
            return $this->processRequest($request);
        });

        $loop = Factory::create();
        $socket = new SocketServer(sprintf('%s:%d', (string) $input->getOption('host'), (int) $input->getOption('port')), $loop);
        $server->listen($socket);
        $this->registerSignals($loop);

        $loop->run();
        $this->logger->info(\sprintf('Server stopped at %s', \date_create()->format(\DateTime::ATOM)));

        return 0;
    }

    /**
     * @param LoopInterface $loop
     */
    private function registerSignals(LoopInterface $loop): void
    {
        \pcntl_signal(SIGTERM, static function () use ($loop) {
            $loop->stop();
        });
        \pcntl_signal(SIGINT, static function () use ($loop) {
            $loop->stop();
        });
    }

    /**
     * @param string $path
     */
    private function checkDir(string $path): void
    {
        if (\strpos($path, '/') !== 0) {
            $path = \vsprintf('%s/%s', [
                $this->container->getParameter('kernel.project_dir'),
                $path,
            ]);
        }
        $this->dataDir = $path;
        if (!\is_dir($this->dataDir) || !\is_readable($this->dataDir)) {
            throw new RuntimeException(\sprintf('Cannot read %s, existing', $this->dataDir));
        }
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return Response
     */
    private function processRequest(ServerRequestInterface $request): Response
    {
        $this->logger->info('Request received', [
            'path' => $request->getUri()->getPath(),
            'query' => $request->getQueryParams(),
            'headers' => $request->getHeaders(),
            'parameters' => $request->getServerParams(),
        ]);

        $headers = $this->getHeaders($request->getHeaders());
        $content = $this->getContent($request);
        $status = 404;
        if ($content !== null) {
            $status = 200;
            $headers['Content-Length'] = \mb_strlen($content);

            $this->logger->info('Sending response', $headers);
        }
        if ($content === null) {
            $content = \json_encode(['result' => \sprintf("File %s not found\n", $this->urlToPath($request->getUri()->getPath()))
                . "You must name your mock-files as strings from your request url, with change all '/' symbols to dashes\n", ], JSON_THROW_ON_ERROR, 512);

            $this->logger->warning('Sending \'Not found\' response');
        }

        return new Response($status, $headers, $content);
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function urlToPath(string $url): string
    {
        $parts = \pathinfo($url);
        $path = \sprintf('%s-%s', ($parts['dirname'] ?? ''), ($parts['filename'] ?? ''));
        $ext = empty($parts['extension']) ? 'json' : $parts['extension'];

        return \sprintf('%s/%s.%s', $this->dataDir ?? '', $this->slugger->slug($path), $ext);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return string|null
     */
    private function getContent(ServerRequestInterface $request): ?string
    {
        $filename = $this->urlToPath($request->getUri()->getPath());
        if (!\is_readable($filename) || !\is_file($filename)) {
            $this->logger->error('File not found', [
                'url' => $request->getUri()->getPath(),
                'path' => $filename,
            ]);

            return null;
        }

        return \file_get_contents($filename);
    }

    /**
     * @param array $requestHeaders
     *
     * @return array
     */
    private function getHeaders(array $requestHeaders): array
    {
        $default = [
            'Content-type' => 'application/json',
            'X-Powered-By' => 'CREATIVE test http server',
        ];
        \array_walk($requestHeaders, static function ($value, $key) use (&$default) {
            if ((\strtolower($key) === 'accept') && ($value[0] ??= 'application/json') !== '*/*') {
                $default['Content-type'] = $value[0];
            }
        });

        return $default;
    }
}
