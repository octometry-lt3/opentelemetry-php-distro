<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandlerImpl;
use Illuminate\Foundation\Http\Kernel as HttpKernelImpl;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Opentelemetry\Proto\Common\V1\KeyValue as OTelProtoKeyValue;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\ComponentTestsPHPUnitExtension;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\MixedMap;
use PHPUnit\Framework\Assert;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class LaravelAutoInstrumentationTest extends ComponentTestCaseBase
{
    private const AUTO_INSTRUMENTATION_NAME = 'laravel';
    private const LARAVEL_INSTRUMENTATION_SCOPE_NAME = 'io.opentelemetry.contrib.php.laravel';
    private const IS_AUTO_INSTRUMENTATION_ENABLED_KEY = 'is_auto_instrumentation_enabled';

    private const ROUTE_URI = '/hello/{name}';
    private const RESPONSE_BODY = 'Hello, world!';
    private const LOG_BODY = 'laravel-log-resource-reproduction';
    private const CORRELATION = 'php-distro-e2e-correlation';
    private const EXPECTED_SERVICE_NAME = 'php-distro-e2e';
    private const EXPECTED_TENANT_ID = 'php-distro-e2e-tenant';

    private static function writeMinimalConfigFiles(string $basePath): void
    {
        $configDir = $basePath . '/config';
        self::assertTrue(is_dir($configDir) || mkdir($configDir, recursive: true));

        file_put_contents($configDir . '/app.php', '<?php return ' . var_export(
            [
                'name' => 'LaravelAutoInstrumentationTest',
                'env' => 'testing',
                'debug' => true,
                'url' => 'http://localhost',
                'timezone' => 'UTC',
                'locale' => 'en',
                'key' => 'base64:' . base64_encode(str_repeat('a', 32)),
                'cipher' => 'AES-256-CBC',
                'providers' => [
                    \Illuminate\Filesystem\FilesystemServiceProvider::class,
                    \Illuminate\View\ViewServiceProvider::class,
                ],
                'aliases' => [],
            ],
            return: true
        ) . ';');

        file_put_contents($configDir . '/logging.php', '<?php return ' . var_export(
            [
                'default' => 'null',
                'channels' => [
                    'null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
                ],
            ],
            return: true
        ) . ';');

        file_put_contents($configDir . '/view.php', '<?php return ' . var_export(
            ['paths' => [], 'compiled' => $basePath . '/storage/framework/views'],
            return: true
        ) . ';');
    }

    private static function buildMinimalApp(string $basePath): Application
    {
        foreach (
            [
                $basePath . '/bootstrap/cache',
                $basePath . '/storage/framework/cache/data',
                $basePath . '/storage/framework/sessions',
                $basePath . '/storage/framework/testing',
                $basePath . '/storage/framework/views',
                $basePath . '/storage/logs',
            ] as $dir
        ) {
            self::assertTrue(is_dir($dir) || mkdir($dir, recursive: true));
        }
        self::writeMinimalConfigFiles($basePath);

        $app = new Application($basePath);
        $app->instance('config', new Repository([
            'app' => require $basePath . '/config/app.php',
            'logging' => require $basePath . '/config/logging.php',
            'view' => require $basePath . '/config/view.php',
        ]));
        Facade::setFacadeApplication($app);

        // Anonymous subclasses: empty middleware stacks, since this smoke test
        // doesn't exercise sessions/CSRF/cookies.
        $app->singleton(HttpKernel::class, static function (Application $app) {
            /** @var Router $router */
            $router = $app->make('router');

            return new class ($app, $router) extends HttpKernelImpl {
                protected $middleware = [];
                protected $middlewareGroups = ['web' => [], 'api' => []];
                protected $middlewareAliases = [];
                protected $routeMiddleware = [];
            };
        });
        $app->singleton(ConsoleKernel::class, static function (Application $app) {
            /** @var Dispatcher $events */
            $events = $app->make('events');

            return new class ($app, $events) extends \Illuminate\Foundation\Console\Kernel {
                protected function commands(): void
                {
                }
            };
        });
        $app->singleton(ExceptionHandler::class, ExceptionHandlerImpl::class);

        /** @var Router $router */
        $router = $app->make('router');
        $router->get(self::ROUTE_URI, function (string $name) {
            return "Hello, {$name}!";
        });

        return $app;
    }

    private static function removeDirRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        self::assertNotFalse($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDirRecursively($path) : unlink($path);
        }
        rmdir($dir);
    }

    public static function appCodeForTestAutoInstrumentation(MixedMap $appCodeRequestArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $isAutoInstrumentationEnabled = $appCodeRequestArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY);
        if ($isAutoInstrumentationEnabled) {
            $laravelInstrumentationFqClassName = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Contrib\\Instrumentation\\Laravel\\LaravelInstrumentation');
            $dbgCtx->add(compact('laravelInstrumentationFqClassName'));
            self::assertTrue(class_exists($laravelInstrumentationFqClassName, autoload: false));
            AssertEx::sameConstValues(constant($laravelInstrumentationFqClassName . '::NAME'), self::AUTO_INSTRUMENTATION_NAME);
        }

        $basePath = sys_get_temp_dir() . '/laravel_smoke_test_' . bin2hex(random_bytes(8));
        try {
            $app = self::buildMinimalApp($basePath);
            Log::withContext(['request.correlation' => self::CORRELATION]);
            Log::info(self::LOG_BODY);

            $globalsClass = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\API\\Globals');
            $cachedInstrumentationClass = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\API\\Instrumentation\\CachedInstrumentation');
            $cachedInstrumentation = new $cachedInstrumentationClass(self::LARAVEL_INSTRUMENTATION_SCOPE_NAME);
            $cachedInstrumentation->logger();
            $loggerProvider = $globalsClass::loggerProvider();
            $traceProvider = $globalsClass::tracerProvider();
            $meterProvider = $globalsClass::meterProvider();
            $loggerProviderFromCache = null;
            $loggersProperty = new \ReflectionProperty($cachedInstrumentationClass, 'loggers');
            foreach ($loggersProperty->getValue($cachedInstrumentation) as $provider => $unused) {
                $loggerProviderFromCache = $provider;
                break;
            }
            $dbgCtx->add([
                'providerEvidence' => [
                    'globalLogger' => self::providerEvidence($loggerProvider),
                    'cachedInstrumentationLogger' => self::providerEvidence($loggerProviderFromCache),
                    'trace' => self::providerEvidence($traceProvider),
                    'meter' => self::providerEvidence($meterProvider),
                ],
            ]);

            /** @var HttpKernel $kernel */
            $kernel = $app->make(HttpKernel::class);
            $request = Request::create('/hello/world', 'GET');
            /** @var \Symfony\Component\HttpFoundation\Response $response */
            $response = $kernel->handle($request);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(self::RESPONSE_BODY, $response->getContent());

            $kernel->terminate($request, $response);
        } finally {
            self::removeDirRecursively($basePath);
        }
    }

    private static function providerEvidence(?object $provider): array
    {
        if ($provider === null) {
            return ['class' => null, 'objectId' => null, 'resourceAttributes' => null];
        }
        $resourceAttributes = null;
        foreach ((new \ReflectionObject($provider))->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($provider);
            if (is_object($value) && method_exists($value, 'getResource')) {
                $resource = $value->getResource();
                $resourceAttributes = $resource->getAttributes()->toArray();
                break;
            }
            if (is_object($value) && method_exists($value, 'getAttributes') && str_ends_with($property->getName(), 'resource')) {
                $resourceAttributes = $value->getAttributes()->toArray();
                break;
            }
        }
        return ['class' => get_class($provider), 'objectId' => spl_object_id($provider), 'resourceAttributes' => $resourceAttributes];
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestAutoInstrumentation(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY)
        );
    }

    private function implTestAutoInstrumentation(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $isAutoInstrumentationEnabled = $testArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY);

        $testCaseHandle = $this->getTestCaseHandle();
        $yamlConfigFile = tempnam(sys_get_temp_dir(), 'laravel_log_resource_') . '.yaml';
        $mockCollectorPort = ComponentTestsPHPUnitExtension::getGlobalTestInfra()->getMockOTelCollector()->getPortForAgent();
        file_put_contents($yamlConfigFile, "file_format: \"1.0-rc.2\"\nresource:\n  attributes:\n    - name: service.name\n      value: \${OTEL_SERVICE_NAME}\n    - name: saas.tenant.id\n      value: \${DEPLOYMENT_TENANT_ID}\n  detection/development:\n    detectors:\n      - distro: {}\nlogger_provider:\n  processors:\n    - batch:\n        exporter:\n          otlp_http:\n            endpoint: http://127.0.0.1:$mockCollectorPort/v1/logs\ntracer_provider:\n  processors:\n    - batch:\n        exporter:\n          otlp_http:\n            endpoint: http://127.0.0.1:$mockCollectorPort/v1/traces\n");

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($isAutoInstrumentationEnabled, $yamlConfigFile): void {
                if (!$isAutoInstrumentationEnabled) {
                    $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::disabled_instrumentations, self::AUTO_INSTRUMENTATION_NAME);
                }
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $yamlConfigFile);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_SERVICE_NAME', self::EXPECTED_SERVICE_NAME);
                $appCodeHostParams->setAdditionalEnvVar('DEPLOYMENT_TENANT_ID', self::EXPECTED_TENANT_ID);
            }
        );
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestAutoInstrumentation']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($testArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($testArgs->cloneAsArray());
            }
        );

        if ($isAutoInstrumentationEnabled) {
            // +1 automatic local root span, +1 Kernel::handle span
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAndLogs(2));
            $dbgCtx->add(compact('agentBackendComms'));

            $rootSpan = $agentBackendComms->singleRootSpan();
            $laravelServerSpan = $agentBackendComms->singleChildSpan($rootSpan->id);

            $expectationsForLaravelServerSpan = (new SpanExpectationsBuilder())
                ->name('GET ' . self::ROUTE_URI)
                ->kind(SpanKind::server)
                ->instrumentationScopeName(self::LARAVEL_INSTRUMENTATION_SCOPE_NAME)
                ->build();
            $expectationsForLaravelServerSpan->assertMatches($laravelServerSpan);
            $logRecord = $agentBackendComms->singleLogRecord();
            self::assertSame(self::LOG_BODY, $logRecord->body);
            $context = $logRecord->attributes->getValue('context');
            Assert::assertIsArray($context);
            $correlation = null;
            foreach ($context as $entry) {
                Assert::assertInstanceOf(OTelProtoKeyValue::class, $entry);
                if ($entry->getKey() !== 'request.correlation') {
                    continue;
                }
                $value = $entry->getValue();
                Assert::assertNotNull($value);
                $correlation = $value->getStringValue();
                break;
            }
            self::assertSame(self::CORRELATION, $correlation);
            self::assertSame(self::LARAVEL_INSTRUMENTATION_SCOPE_NAME, $logRecord->instrumentationScopeName);
            self::assertSame(self::EXPECTED_SERVICE_NAME, self::singleResourceAttribute($agentBackendComms, 'service.name'));
            self::assertSame(self::EXPECTED_TENANT_ID, self::singleResourceAttribute($agentBackendComms, 'saas.tenant.id'));
        } else {
            // +1 automatic local root span only
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));
            $dbgCtx->add(compact('agentBackendComms'));

            self::assertEmpty(iterator_to_array($agentBackendComms->findSpansByInstrumentationScope(self::LARAVEL_INSTRUMENTATION_SCOPE_NAME)));
        }
    }

    private static function singleResourceAttribute($agentBackendComms, string $name): string
    {
        $values = [];
        foreach ($agentBackendComms->resources() as $resource) {
            if ($resource->attributes->tryToGetString($name) !== null) {
                $values[] = $resource->attributes->getString($name);
            }
        }
        Assert::assertNotEmpty($values);
        return $values[0];
    }

    /**
     * @dataProvider dataProviderForTestAutoInstrumentation
     */
    public function testAutoInstrumentation(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestAutoInstrumentation($testArgs);
            }
        );
    }
}
