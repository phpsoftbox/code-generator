<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\Tests;

use PhpSoftBox\CodeGenerator\OpenApi\OpenApiDtoGenerator;
use PhpSoftBox\CodeGenerator\OpenApi\OpenApiDtoGeneratorDocument;
use PhpSoftBox\CodeGenerator\OpenApi\OpenApiDtoGeneratorOptions;
use PhpSoftBox\CodeGenerator\Tests\Generated\Partner\Orders\GetOrderResponse;
use PhpSoftBox\CodeGenerator\Tests\Generated\TestResponseDtoMap;
use PhpSoftBox\CodeGenerator\Tests\GeneratedDuplicate\Alpha\Api\Error;
use PhpSoftBox\CodeGenerator\Tests\GeneratedDuplicate\TestDuplicateResponseDtoMap;
use PhpSoftBox\CodeGenerator\Tests\Support\TestDtoInterface;
use PhpSoftBox\CodeGenerator\Tests\Support\TestDtoValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function unlink;

#[CoversClass(OpenApiDtoGenerator::class)]
#[CoversMethod(OpenApiDtoGenerator::class, 'generate')]
final class OpenApiDtoGeneratorTest extends TestCase
{
    private const array OUTPUT_DIRECTORIES = [
        'testGenerateBuildsDtoAndResponseMap'              => 'basic',
        'testGenerateScopesDuplicateSchemaNamesByDocument' => 'duplicate',
    ];

    private string $testDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDirectory = dirname(__DIR__) . '/local/tests/openapi-dto/' . (self::OUTPUT_DIRECTORIES[$this->name()] ?? $this->name());
        $this->clearDirectory($this->testDirectory);

        if (!is_dir($this->testDirectory) && !mkdir($this->testDirectory, 0775, true) && !is_dir($this->testDirectory)) {
            self::fail('Could not create test output directory: ' . $this->testDirectory);
        }
    }

    /**
     * Проверяет генерацию DTO и response-map для inline response schema и path-параметра.
     *
     * @see OpenApiDtoGenerator::generate()
     */
    #[Test]
    public function testGenerateBuildsDtoAndResponseMap(): void
    {
        $dir = $this->testDirectory();

        $result = new OpenApiDtoGenerator()->generate(new OpenApiDtoGeneratorOptions(
            documents: [
                new OpenApiDtoGeneratorDocument('partner', [
                    'openapi' => '3.0.1',
                    'paths'   => [
                        '/v2/orders/{orderId}' => [
                            'get' => [
                                'operationId' => 'getOrder',
                                'responses'   => [
                                    '200' => [
                                        'content' => [
                                            'application/json' => [
                                                'schema' => [
                                                    'type'       => 'object',
                                                    'properties' => [
                                                        'orderId' => ['type' => 'integer'],
                                                        'status'  => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
            ],
            dtoDirectory: $dir . '/Dto',
            dtoNamespace: 'PhpSoftBox\\CodeGenerator\\Tests\\Generated',
            dtoInterface: TestDtoInterface::class,
            dtoValueClass: TestDtoValue::class,
            responseMapPath: $dir . '/Dto/TestResponseDtoMap.php',
            responseMapNamespace: 'PhpSoftBox\\CodeGenerator\\Tests\\Generated',
            responseMapClassName: 'TestResponseDtoMap',
            normalizePathFunctionName: 'normalizeTestPath',
            normalizePatternFunctionName: 'normalizeTestPathPattern',
            generatedComment: 'Test OpenAPI DTO',
        ));

        self::assertSame(1, $result->generatedClasses);
        self::assertSame(1, $result->responseMappings);

        $matches = [
            ...(glob($dir . '/Dto/*/GetOrderResponse.php') ?: []),
            ...(glob($dir . '/Dto/*/*/GetOrderResponse.php') ?: []),
            ...(glob($dir . '/Dto/*/*/*/GetOrderResponse.php') ?: []),
        ];
        self::assertNotSame([], $matches);

        $dtoFile = $matches[0];
        self::assertStringContainsString('public ?int $orderId', (string) file_get_contents($dtoFile));

        require_once $dtoFile;
        require_once $dir . '/Dto/TestResponseDtoMap.php';

        $dtoClass = TestResponseDtoMap::resolve('GET', '/v2/orders/123');
        self::assertSame(GetOrderResponse::class, $dtoClass);

        $dto = $dtoClass::fromArray(['orderId' => 123, 'status' => 'PROCESSING']);
        self::assertSame(123, $dto->orderId);
        self::assertSame('PROCESSING', $dto->status);
    }

    /**
     * Проверяет, что одинаковые schema names из разных OpenAPI-документов не конфликтуют.
     *
     * @see OpenApiDtoGenerator::generate()
     */
    #[Test]
    public function testGenerateScopesDuplicateSchemaNamesByDocument(): void
    {
        $dir = $this->testDirectory();

        $result = new OpenApiDtoGenerator()->generate(new OpenApiDtoGeneratorOptions(
            documents: [
                new OpenApiDtoGeneratorDocument('alpha', $this->documentWithErrorResponse('/api/alpha', 'alphaCode')),
                new OpenApiDtoGeneratorDocument('beta', $this->documentWithErrorResponse('/api/beta', 'betaCode')),
            ],
            dtoDirectory: $dir . '/Dto',
            dtoNamespace: 'PhpSoftBox\\CodeGenerator\\Tests\\GeneratedDuplicate',
            dtoInterface: TestDtoInterface::class,
            dtoValueClass: TestDtoValue::class,
            responseMapPath: $dir . '/Dto/TestDuplicateResponseDtoMap.php',
            responseMapNamespace: 'PhpSoftBox\\CodeGenerator\\Tests\\GeneratedDuplicate',
            responseMapClassName: 'TestDuplicateResponseDtoMap',
            normalizePathFunctionName: 'normalizeTestDuplicatePath',
            normalizePatternFunctionName: 'normalizeTestDuplicatePathPattern',
            generatedComment: 'Test OpenAPI DTO',
        ));

        self::assertSame(2, $result->generatedClasses);
        self::assertSame(2, $result->responseMappings);
        self::assertFileExists($dir . '/Dto/Alpha/Api/Error.php');
        self::assertFileExists($dir . '/Dto/Beta/Api/Error.php');

        require_once $dir . '/Dto/Alpha/Api/Error.php';
        require_once $dir . '/Dto/Beta/Api/Error.php';
        require_once $dir . '/Dto/TestDuplicateResponseDtoMap.php';

        self::assertSame(
            Error::class,
            TestDuplicateResponseDtoMap::resolve('GET', '/api/alpha'),
        );
        self::assertSame(
            GeneratedDuplicate\Beta\Api\Error::class,
            TestDuplicateResponseDtoMap::resolve('GET', '/api/beta'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function documentWithErrorResponse(string $path, string $propertyName): array
    {
        return [
            'openapi'    => '3.0.1',
            'components' => [
                'schemas' => [
                    'Error' => [
                        'type'       => 'object',
                        'properties' => [
                            $propertyName => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                $path => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => '#/components/schemas/Error',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Корневой JSON-массив попадает в value DTO целиком, а не ищется в несуществующем поле JSON.
     *
     * @see OpenApiDtoGenerator::generate()
     */
    #[Test]
    public function rootArrayUsesActualPayload(): void
    {
        $dtoClass = $this->generateResponseDto('RootArray', [
            '200' => ['content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer']]]]],
        ]);

        $dto = $dtoClass::fromArray([1, 2, 3]);

        self::assertSame([1, 2, 3], $dto->value);
        self::assertSame([], $dto->extra);
        self::assertSame([], $dtoClass::fromArray([])->value);
    }

    /**
     * Не оборачивает обычный JSON-объект, который действительно содержит поле value.
     *
     * @see OpenApiDtoGenerator::generate()
     */
    #[Test]
    public function objectValueFieldKeepsItsMeaning(): void
    {
        $dtoClass = $this->generateResponseDto('ObjectValue', [
            '200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'array', 'items' => ['type' => 'integer']]]]]]],
        ]);

        $dto = $dtoClass::fromArray(['value' => [1, 2], 'future' => true]);

        self::assertSame([1, 2], $dto->value);
        self::assertSame(['future' => true], $dto->extra);
    }

    /**
     * Для успешного ответа без JSON не выбирает DTO ошибки из 400 или default.
     *
     * @see OpenApiDtoGenerator::generate()
     */
    #[Test]
    #[DataProvider('nonJsonSuccessResponses')]
    public function successWithoutJsonDoesNotUseErrorSchema(string $case, array $response): void
    {
        $dtoClass = $this->generateResponseDto($case, $response + [
            '400'     => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string']]]]]],
            'default' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string']]]]]],
        ]);

        self::assertSame(['extra' => []], (array) $dtoClass::fromArray([]));
    }

    /**
     * При отсутствии описанных 2xx сохраняет приоритет default перед явно описанной ошибкой.
     *
     * @see OpenApiDtoGenerator::generate()
     */
    #[Test]
    public function defaultResponseKeepsFallbackPriority(): void
    {
        $dtoClass = $this->generateResponseDto('DefaultOnly', [
            '400'     => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string']]]]]],
            'default' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['result' => ['type' => 'string']]]]]],
        ]);

        self::assertSame('fallback', $dtoClass::fromArray(['result' => 'fallback'])->result);
    }

    /** @return iterable<string, array{string, array}> */
    public static function nonJsonSuccessResponses(): iterable
    {
        yield '204' => ['NoContent', ['204' => ['description' => 'No content']]];
        yield 'binary download' => ['Binary', ['200' => ['content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]]];
        yield 'other 2xx' => ['Partial', ['206' => ['description' => 'Partial content']]];
    }

    /** @return class-string<TestDtoInterface> */
    private function generateResponseDto(string $case, array $responses): string
    {
        $dir       = $this->testDirectory();
        $namespace = 'PhpSoftBox\\CodeGenerator\\Tests\\Generated' . $case;
        new OpenApiDtoGenerator()->generate(new OpenApiDtoGeneratorOptions(
            documents: [new OpenApiDtoGeneratorDocument('example', ['paths' => ['/response' => ['get' => ['operationId' => 'read', 'responses' => $responses]]]])],
            dtoDirectory: $dir . '/Dto',
            dtoNamespace: $namespace,
            dtoInterface: TestDtoInterface::class,
            dtoValueClass: TestDtoValue::class,
            responseMapPath: $dir . '/Map.php',
            responseMapNamespace: $namespace,
            responseMapClassName: 'Map',
            normalizePathFunctionName: 'normalize' . $case . 'Path',
            normalizePatternFunctionName: 'normalize' . $case . 'Pattern',
            generatedComment: 'Test OpenAPI DTO',
        ));
        require $dir . '/Dto/Example/Response/ReadResponse.php';

        return $namespace . '\\Example\\Response\\ReadResponse';
    }

    private function testDirectory(): string
    {
        return $this->testDirectory;
    }

    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->clearDirectory($path);
                rmdir($path);

                continue;
            }

            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
