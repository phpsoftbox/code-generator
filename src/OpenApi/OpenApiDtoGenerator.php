<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\OpenApi;

use PhpSoftBox\CodeGenerator\CodeGenerator;
use PhpSoftBox\CodeGenerator\FileWriter;
use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function lcfirst;
use function max;
use function mkdir;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;
use function ucfirst;
use function unlink;

use const SORT_FLAG_CASE;
use const SORT_STRING;

final class OpenApiDtoGenerator
{
    public function __construct(
        private readonly CodeGenerator $codeGenerator = new CodeGenerator(),
    ) {
    }

    public function generate(OpenApiDtoGeneratorOptions $options): OpenApiDtoGeneratorResult
    {
        if ($options->cleanDtoDirectory) {
            $this->clearGeneratedDirectory($options->dtoDirectory, $options->generatedComment);
        }

        $this->ensureDirectory($options->dtoDirectory);

        $schemas        = [];
        $operations     = [];
        $inlineCounters = [];

        foreach ($options->documents as $document) {
            $documentName = $this->safeDocumentName($document->name);
            foreach ($this->documentSchemas($document->openApi) as $schemaName => $schema) {
                $schemas[$this->internalSchemaName($documentName, $schemaName)] = is_array($schema) ? $schema : ['type' => 'object'];
            }

            foreach ($this->collectOperations($document->openApi, $documentName, $schemas, $inlineCounters) as $operation) {
                $operations[] = $operation;
            }
        }

        $classNames       = $this->buildClassNameMap($schemas);
        $schemaNamespaces = $this->buildSchemaNamespaceMap($operations, $schemas);
        $operations       = $this->withOperationClasses($operations, $classNames, $schemaNamespaces, $options);
        $generated        = [];

        foreach ($operations as $operation) {
            $this->addSchemaClass(
                schemaName: $operation->schemaName,
                schemas: $schemas,
                classNames: $classNames,
                schemaNamespaces: $schemaNamespaces,
                generated: $generated,
                options: $options,
            );
        }

        $this->ensureDirectory(dirname($options->responseMapPath));
        FileWriter::writeFile($options->responseMapPath, $this->renderResponseMap($operations, $options));

        return new OpenApiDtoGeneratorResult(
            generatedClasses: count($generated),
            responseMappings: count($operations),
        );
    }

    /**
     * @param array<string, mixed> $openApi
     *
     * @return array<string, mixed>
     */
    private function documentSchemas(array $openApi): array
    {
        $schemas = $openApi['components']['schemas'] ?? [];

        return is_array($schemas) ? $schemas : [];
    }

    /**
     * @param array<string, mixed> $openApi
     * @param array<string, mixed> $schemas
     * @param array<string, int> $inlineCounters
     *
     * @return list<OpenApiOperation>
     */
    private function collectOperations(array $openApi, string $documentName, array &$schemas, array &$inlineCounters): array
    {
        $paths = $openApi['paths'] ?? [];
        if (!is_array($paths)) {
            return [];
        }

        $operations = [];
        foreach ($paths as $path => $methods) {
            if (!is_string($path) || !is_array($methods)) {
                continue;
            }

            foreach ($methods as $method => $operation) {
                if (!is_string($method) || !is_array($operation) || !in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $httpMethod = strtoupper($method);
                $schema     = $this->responseSchema($operation);
                $schemaName = $this->responseSchemaName($documentName, $path, $httpMethod, $operation, $schema, $schemas, $inlineCounters);

                $operations[] = new OpenApiOperation(
                    key: $httpMethod . ' ' . $this->normalizePath($path),
                    method: $httpMethod,
                    path: $this->normalizePath($path),
                    class: '',
                    schemaName: $schemaName,
                    documentName: $documentName,
                );
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>|null
     */
    private function responseSchema(array $operation): ?array
    {
        $responses = $operation['responses'] ?? [];
        if (!is_array($responses)) {
            return null;
        }

        $hasSuccessResponse = false;
        foreach ($responses as $status => $response) {
            if (!preg_match('/^2[0-9Xx]{2}$/', (string) $status)) {
                continue;
            }
            $hasSuccessResponse = true;
            if (!is_array($response)) {
                continue;
            }

            $schema = $this->contentSchema($response);
            if ($schema !== null) {
                return $schema;
            }
        }

        // A successful response without JSON (204, file download) must not use an error DTO.
        if ($hasSuccessResponse) {
            return null;
        }

        $defaultResponse = $responses['default'] ?? null;
        if (is_array($defaultResponse)) {
            $schema = $this->contentSchema($defaultResponse);
            if ($schema !== null) {
                return $schema;
            }
        }

        foreach ($responses as $response) {
            if (!is_array($response)) {
                continue;
            }

            $schema = $this->contentSchema($response);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>|null
     */
    private function contentSchema(array $response): ?array
    {
        if (isset($response['$ref']) && is_string($response['$ref'])) {
            return ['$ref' => $response['$ref']];
        }

        $content = $response['content'] ?? [];
        if (!is_array($content)) {
            return null;
        }

        foreach (['application/json', 'application/problem+json'] as $type) {
            $schema = $content[$type]['schema'] ?? null;
            if (is_array($schema)) {
                return $schema;
            }
        }

        foreach ($content as $type => $media) {
            if (!is_string($type) || !str_contains($type, 'json') || !is_array($media)) {
                continue;
            }

            $schema = $media['schema'] ?? null;
            if (is_array($schema)) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed>|null $schema
     * @param array<string, mixed> $schemas
     * @param array<string, int> $inlineCounters
     */
    private function responseSchemaName(
        string $documentName,
        string $path,
        string $method,
        array $operation,
        ?array $schema,
        array &$schemas,
        array &$inlineCounters,
    ): string {
        if (is_array($schema) && isset($schema['$ref']) && is_string($schema['$ref'])) {
            return $this->internalSchemaName($documentName, $this->refName($schema['$ref']));
        }

        $operationId = $operation['operationId'] ?? null;
        $baseName    = is_string($operationId) && trim($operationId) !== ''
            ? $operationId
            : $method . '_' . trim($path, '/');

        $schemaName = $this->classNameFromSchemaName($baseName . '_Response');
        $internal   = $this->internalSchemaName($documentName, $schemaName);
        if (array_key_exists($internal, $schemas)) {
            $counter                   = ($inlineCounters[$internal] ?? 1) + 1;
            $inlineCounters[$internal] = $counter;
            $internal                  = $this->internalSchemaName($documentName, $schemaName . '_' . $counter);
        }

        $schemas[$internal] = is_array($schema) ? $schema : ['type' => 'object'];

        return $internal;
    }

    /**
     * @param array<string, mixed> $schemas
     *
     * @return array<string, string>
     */
    private function buildClassNameMap(array $schemas): array
    {
        $usedByNamespace = [];
        $map             = [];

        foreach (array_keys($schemas) as $schemaName) {
            [$documentName, $localName] = $this->splitInternalSchemaName((string) $schemaName);
            $namespaceKey               = $documentName;
            $base                       = $this->classNameFromSchemaName($localName);
            $name                       = $base;
            $i                          = 2;
            while (isset($usedByNamespace[$namespaceKey][$name])) {
                $name = $base . $i;
                ++$i;
            }

            $usedByNamespace[$namespaceKey][$name] = true;
            $map[(string) $schemaName]             = $name;
        }

        return $map;
    }

    /**
     * @param list<OpenApiOperation> $operations
     * @param array<string, mixed> $schemas
     *
     * @return array<string, string>
     */
    private function buildSchemaNamespaceMap(array $operations, array $schemas): array
    {
        $map = [];
        foreach ($operations as $operation) {
            $this->assignSchemaNamespace(
                schemaName: $operation->schemaName,
                namespace: $this->namespaceSegmentFromPath($operation->documentName, $operation->path),
                schemas: $schemas,
                map: $map,
            );
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $schemas
     * @param array<string, string> $map
     */
    private function assignSchemaNamespace(string $schemaName, string $namespace, array $schemas, array &$map): void
    {
        if (isset($map[$schemaName])) {
            return;
        }

        $map[$schemaName] = $namespace;
        $schema           = $schemas[$schemaName] ?? ['type' => 'object'];
        if (!is_array($schema)) {
            return;
        }

        [$documentName] = $this->splitInternalSchemaName($schemaName);
        foreach ($this->collectRefs($schema, $documentName) as $ref) {
            $this->assignSchemaNamespace($ref, $namespace, $schemas, $map);
        }
    }

    /**
     * @param list<OpenApiOperation> $operations
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     *
     * @return list<OpenApiOperation>
     */
    private function withOperationClasses(
        array $operations,
        array $classNames,
        array $schemaNamespaces,
        OpenApiDtoGeneratorOptions $options,
    ): array {
        return array_map(
            fn (OpenApiOperation $operation): OpenApiOperation => new OpenApiOperation(
                key: $operation->key,
                method: $operation->method,
                path: $operation->path,
                class: $this->schemaFqcn($operation->schemaName, $classNames, $schemaNamespaces, $options),
                schemaName: $operation->schemaName,
                documentName: $operation->documentName,
            ),
            $operations,
        );
    }

    /**
     * @param array<string, mixed> $schemas
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     * @param array<string, bool> $generated
     */
    private function addSchemaClass(
        string $schemaName,
        array $schemas,
        array $classNames,
        array $schemaNamespaces,
        array &$generated,
        OpenApiDtoGeneratorOptions $options,
    ): void {
        if (isset($generated[$schemaName])) {
            return;
        }

        $generated[$schemaName] = true;
        [$documentName]         = $this->splitInternalSchemaName($schemaName);
        $schema                 = $schemas[$schemaName] ?? ['type' => 'object'];
        if (!is_array($schema)) {
            $schema = ['type' => 'object'];
        }

        foreach ($this->collectRefs($schema, $documentName) as $ref) {
            if (isset($schemas[$ref])) {
                $this->addSchemaClass($ref, $schemas, $classNames, $schemaNamespaces, $generated, $options);
            }
        }

        $className       = $classNames[$schemaName] ?? $this->classNameFromSchemaName($schemaName);
        $schemaInfo      = $this->schemaClassInfo($schemaName, $classNames, $schemaNamespaces, $options);
        $schemaSubdir    = str_replace('\\', '/', $schemaInfo['namespaceSegment']);
        $schemaTargetDir = $this->joinPath($options->dtoDirectory, $schemaSubdir);
        $this->ensureDirectory($schemaTargetDir);

        FileWriter::writeFile(
            $this->joinPath($schemaTargetDir, $className . '.php'),
            $this->renderDtoClass(
                className: $className,
                schemaName: $schemaName,
                schema: $this->normalizeObjectSchema($schema),
                classNames: $classNames,
                schemaNamespaces: $schemaNamespaces,
                namespace: $schemaInfo['namespace'],
                options: $options,
            ),
        );
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeObjectSchema(array $schema): array
    {
        $schema = $this->unwrapSchema($schema);
        if (($schema['type'] ?? null) === 'object' || isset($schema['properties'])) {
            return $schema;
        }

        return [
            'type'                    => 'object',
            'x-phpsoftbox-root-array' => ($schema['type'] ?? null) === 'array',
            'properties'              => [
                'value' => $schema,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     */
    private function renderDtoClass(
        string $className,
        string $schemaName,
        array $schema,
        array $classNames,
        array $schemaNamespaces,
        string $namespace,
        OpenApiDtoGeneratorOptions $options,
    ): string {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        $fields = [];
        // `extra` занят параметром конструктора для неизвестных ключей: поле с таким именем получит суффикс.
        $usedProperties = ['extra' => true];
        [$documentName] = $this->splitInternalSchemaName($schemaName);
        foreach ($properties as $jsonName => $propertySchema) {
            if (!is_string($jsonName) || !is_array($propertySchema)) {
                continue;
            }

            $field = $this->fieldDefinition($jsonName, $propertySchema, $classNames, $schemaNamespaces, $namespace, $documentName, $options);
            $base  = $field['property'];
            $i     = 2;
            while (isset($usedProperties[$field['property']])) {
                $field['property'] = $base . $i;
                ++$i;
            }
            $usedProperties[$field['property']] = true;
            $fields[]                           = $field;
        }

        $knownKeys      = array_map(static fn (array $field): string => $field['jsonName'], $fields);
        $valueShortName = $this->shortClassName($options->dtoValueClass);
        $uses           = [
            $options->dtoInterface,
            $options->dtoValueClass,
        ];
        foreach ($fields as $field) {
            $uses = array_merge($uses, $field['uses']);
        }
        $uses = array_values(array_unique($uses));

        $bodyLines   = [];
        $bodyLines[] = '/**';
        foreach ($fields as $field) {
            if ($field['docType'] !== null) {
                $bodyLines[] = ' * @param ' . $field['docType'] . ' $' . $field['property'];
            }
        }
        $bodyLines[] = ' * @param array<string, mixed> $extra';
        $bodyLines[] = ' */';
        $bodyLines[] = 'public function __construct(';
        foreach ($fields as $field) {
            $bodyLines[] = '    public ' . $field['phpType'] . ' $' . $field['property'] . ',';
        }
        $bodyLines[] = '    public array $extra = [],';
        $bodyLines[] = ') {';
        $bodyLines[] = '}';
        $bodyLines[] = '';
        $bodyLines[] = 'public static function fromArray(array $payload): static';
        $bodyLines[] = '{';
        if ($schema['x-phpsoftbox-root-array'] ?? false) {
            $bodyLines[] = '    $payload = [\'value\' => $payload];';
            $bodyLines[] = '';
        }
        $bodyLines[] = '    return new self(';
        foreach ($fields as $field) {
            $bodyLines[] = '        ' . $field['property'] . ': ' . $field['hydrate'] . ',';
        }
        $bodyLines[] = '        extra: ' . $valueShortName . '::extra($payload, ' . $this->renderStringList($knownKeys) . '),';
        $bodyLines[] = '    );';
        $bodyLines[] = '}';

        $code = $this->codeGenerator->renderClass(
            className: $className,
            namespace: $namespace,
            uses: $uses,
            bodyLines: $bodyLines,
            readonly: true,
        );

        $code = str_replace(
            'final readonly class ' . $className . "\n",
            'final readonly class ' . $className . ' implements ' . $this->shortClassName($options->dtoInterface) . "\n",
            $code,
        );

        return str_replace(
            "declare(strict_types=1);\n",
            "declare(strict_types=1);\n\n/**\n * @generated " . $options->generatedComment . "\n */\n",
            $code,
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     *
     * @return array{jsonName: string, property: string, phpType: string, docType: string|null, hydrate: string, uses: list<string>}
     */
    private function fieldDefinition(
        string $jsonName,
        array $schema,
        array $classNames,
        array $schemaNamespaces,
        string $currentNamespace,
        string $documentName,
        OpenApiDtoGeneratorOptions $options,
    ): array {
        $property = $this->propertyName($jsonName);
        $type     = $this->resolvePropertyType($schema, $classNames, $schemaNamespaces, $currentNamespace, $documentName, $options);
        $access   = '$payload[' . $this->phpString($jsonName) . '] ?? null';

        return [
            'jsonName' => $jsonName,
            'property' => $property,
            'phpType'  => $type['phpType'],
            'docType'  => $type['docType'],
            'hydrate'  => sprintf($type['hydrate'], $access),
            'uses'     => $type['uses'],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     *
     * @return array{phpType: string, docType: string|null, hydrate: string, uses: list<string>}
     */
    private function resolvePropertyType(
        array $schema,
        array $classNames,
        array $schemaNamespaces,
        string $currentNamespace,
        string $documentName,
        OpenApiDtoGeneratorOptions $options,
    ): array {
        $schema         = $this->unwrapSchema($schema);
        $valueShortName = $this->shortClassName($options->dtoValueClass);

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $reference = $this->schemaClassReference(
                $this->internalSchemaNameFromRef($schema['$ref'], $documentName),
                $classNames,
                $schemaNamespaces,
                $currentNamespace,
                $options,
            );

            return [
                'phpType' => '?' . $reference['className'],
                'docType' => null,
                'hydrate' => $valueShortName . '::object(%s, ' . $reference['className'] . '::class)',
                'uses'    => $reference['uses'],
            ];
        }

        $type = $schema['type'] ?? null;
        if ($type === 'array') {
            $items = $schema['items'] ?? [];
            $items = is_array($items) ? $this->unwrapSchema($items) : [];
            if (isset($items['$ref']) && is_string($items['$ref'])) {
                $reference = $this->schemaClassReference(
                    $this->internalSchemaNameFromRef($items['$ref'], $documentName),
                    $classNames,
                    $schemaNamespaces,
                    $currentNamespace,
                    $options,
                );

                return [
                    'phpType' => 'array',
                    'docType' => 'list<' . $reference['className'] . '>',
                    'hydrate' => $valueShortName . '::objectList(%s, ' . $reference['className'] . '::class)',
                    'uses'    => $reference['uses'],
                ];
            }

            return [
                'phpType' => 'array',
                'docType' => $this->primitiveListDocType($items),
                'hydrate' => $valueShortName . '::array(%s)',
                'uses'    => [],
            ];
        }

        return match ($type) {
            'integer' => ['phpType' => '?int', 'docType' => null, 'hydrate' => $valueShortName . '::int(%s)', 'uses' => []],
            'number'  => ['phpType' => '?float', 'docType' => null, 'hydrate' => $valueShortName . '::float(%s)', 'uses' => []],
            'boolean' => ['phpType' => '?bool', 'docType' => null, 'hydrate' => $valueShortName . '::bool(%s)', 'uses' => []],
            'string'  => ['phpType' => '?string', 'docType' => null, 'hydrate' => $valueShortName . '::string(%s)', 'uses' => []],
            default   => ['phpType' => 'array', 'docType' => 'array<array-key, mixed>', 'hydrate' => $valueShortName . '::array(%s)', 'uses' => []],
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function unwrapSchema(array $schema): array
    {
        foreach (['allOf', 'oneOf', 'anyOf'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }

            if (count($schema[$key]) === 1 && is_array($schema[$key][0])) {
                return $this->unwrapSchema($schema[$key][0]);
            }

            if ($key === 'allOf') {
                $merged = ['type' => 'object', 'properties' => []];
                foreach ($schema[$key] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $item = $this->unwrapSchema($item);
                    if (isset($item['properties']) && is_array($item['properties'])) {
                        $merged['properties'] = array_merge($merged['properties'], $item['properties']);
                    }
                }

                if ($merged['properties'] !== []) {
                    return $merged;
                }
            }

            foreach ($schema[$key] as $item) {
                if (is_array($item)) {
                    return $this->unwrapSchema($item);
                }
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function primitiveListDocType(array $schema): string
    {
        return match ($schema['type'] ?? null) {
            'integer' => 'list<int>',
            'number'  => 'list<float>',
            'boolean' => 'list<bool>',
            'string'  => 'list<string>',
            default   => 'array<array-key, mixed>',
        };
    }

    /**
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     *
     * @return array{namespaceSegment: string, namespace: string, className: string, fqcn: class-string}
     */
    private function schemaClassInfo(
        string $schemaName,
        array $classNames,
        array $schemaNamespaces,
        OpenApiDtoGeneratorOptions $options,
    ): array {
        $namespaceSegment = $schemaNamespaces[$schemaName] ?? $this->safeDocumentName($this->splitInternalSchemaName($schemaName)[0]);
        $className        = $classNames[$schemaName] ?? $this->classNameFromSchemaName($schemaName);
        $namespace        = rtrim($options->dtoNamespace, '\\') . '\\' . $namespaceSegment;

        return [
            'namespaceSegment' => $namespaceSegment,
            'namespace'        => $namespace,
            'className'        => $className,
            'fqcn'             => $namespace . '\\' . $className,
        ];
    }

    /**
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     *
     * @return class-string
     */
    private function schemaFqcn(
        string $schemaName,
        array $classNames,
        array $schemaNamespaces,
        OpenApiDtoGeneratorOptions $options,
    ): string {
        return $this->schemaClassInfo($schemaName, $classNames, $schemaNamespaces, $options)['fqcn'];
    }

    /**
     * @param array<string, string> $classNames
     * @param array<string, string> $schemaNamespaces
     *
     * @return array{className: string, uses: list<string>}
     */
    private function schemaClassReference(
        string $schemaName,
        array $classNames,
        array $schemaNamespaces,
        string $currentNamespace,
        OpenApiDtoGeneratorOptions $options,
    ): array {
        $info = $this->schemaClassInfo($schemaName, $classNames, $schemaNamespaces, $options);
        if ($info['namespace'] === $currentNamespace) {
            return [
                'className' => $info['className'],
                'uses'      => [],
            ];
        }

        return [
            'className' => $info['className'],
            'uses'      => [$info['fqcn']],
        ];
    }

    private function propertyName(string $jsonName): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $jsonName) === 1) {
            return lcfirst($jsonName);
        }

        $parts = preg_split('/[^A-Za-z0-9]+/', $jsonName) ?: [];
        $name  = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $name .= $name === '' ? strtolower($part) : ucfirst($part);
        }

        if ($name === '') {
            $name = 'value';
        }

        if (preg_match('/^[0-9]/', $name) === 1) {
            $name = 'field' . ucfirst($name);
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function collectRefs(array $schema, string $documentName): array
    {
        $refs = [];
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $refs[] = $this->internalSchemaName($documentName, $this->refName($schema['$ref']));
        }

        foreach ($schema as $value) {
            if (is_array($value)) {
                $refs = array_merge($refs, $this->collectRefs($value, $documentName));
            }
        }

        return array_values(array_unique($refs));
    }

    private function internalSchemaNameFromRef(string $ref, string $documentName): string
    {
        return $this->internalSchemaName($documentName, $this->refName($ref));
    }

    private function refName(string $ref): string
    {
        return basename(str_replace('#/components/schemas/', '', $ref));
    }

    /**
     * @param list<string> $items
     */
    private function renderStringList(array $items): string
    {
        if ($items === []) {
            return '[]';
        }

        return '[' . implode(', ', array_map(fn (string $item): string => $this->phpString($item), $items)) . ']';
    }

    /**
     * @param list<OpenApiOperation> $operations
     */
    private function renderResponseMap(array $operations, OpenApiDtoGeneratorOptions $options): string
    {
        $map = [];
        foreach ($operations as $operation) {
            $map[$operation->key] = $operation->class;
        }
        ksort($map);

        $classes         = array_values(array_unique(array_values($map)));
        $shortNameCounts = [];
        foreach ($classes as $class) {
            $shortNameCounts[$this->shortClassName($class)] = ($shortNameCounts[$this->shortClassName($class)] ?? 0) + 1;
        }

        $imports = [$options->dtoInterface];
        foreach ($classes as $class) {
            if ($shortNameCounts[$this->shortClassName($class)] === 1) {
                $imports[] = $class;
            }
        }
        sort($imports, SORT_STRING | SORT_FLAG_CASE);

        $maxKeyLength = 0;
        foreach (array_keys($map) as $key) {
            $maxKeyLength = max($maxKeyLength, strlen($this->phpString($key)));
        }

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace ' . $options->responseMapNamespace . ';',
            '',
        ];

        foreach ($imports as $class) {
            $lines[] = 'use ' . $class . ';';
        }

        if ($imports !== []) {
            $lines[] = '';
        }

        $lines[] = 'use function preg_match;';
        $lines[] = 'use function preg_quote;';
        $lines[] = 'use function preg_replace;';
        $lines[] = 'use function strtoupper;';
        $lines[] = 'use function trim;';
        $lines[] = '';
        $lines[] = 'final class ' . $options->responseMapClassName;
        $lines[] = '{';
        $lines[] = '    /**';
        $lines[] = '     * @var array<string, class-string<' . $this->shortClassName($options->dtoInterface) . '>>';
        $lines[] = '     */';
        $lines[] = '    private const MAP = [';

        foreach ($map as $key => $class) {
            $keyLiteral = $this->phpString($key);
            $lines[]    = '        '
                . $keyLiteral
                . str_repeat(' ', $maxKeyLength - strlen($keyLiteral) + 1)
                . '=> '
                . $this->responseMapClassReference($class, $shortNameCounts, $options)
                . '::class,';
        }

        $lines[] = '    ];';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @var array<string, class-string<' . $this->shortClassName($options->dtoInterface) . '>>';
        $lines[] = '     */';
        $lines[] = '    private const PATTERN_MAP = [';

        foreach ($map as $key => $class) {
            if (!str_contains($key, '{')) {
                continue;
            }

            $lines[] = '        ' . $this->phpString($this->pathKeyRegex($key)) . ' => '
                . $this->responseMapClassReference($class, $shortNameCounts, $options)
                . '::class,';
        }

        $lines[] = '    ];';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @return class-string<' . $this->shortClassName($options->dtoInterface) . '>|null';
        $lines[] = '     */';
        $lines[] = '    public static function resolve(string $method, string $path): ?string';
        $lines[] = '    {';
        $lines[] = '        $key = strtoupper($method) . \' \' . ' . $options->normalizePathFunctionName . '($path);';
        $lines[] = '';
        $lines[] = '        if (isset(self::MAP[$key])) {';
        $lines[] = '            return self::MAP[$key];';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        foreach (self::PATTERN_MAP as $pattern => $class) {';
        $lines[] = '            if (preg_match($pattern, $key) === 1) {';
        $lines[] = '                return $class;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        return null;';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'function ' . $options->normalizePathFunctionName . '(string $path): string';
        $lines[] = '{';
        $lines[] = '    return \'/\' . trim($path, \'/\');';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'function ' . $options->normalizePatternFunctionName . '(string $path): string';
        $lines[] = '{';
        $lines[] = '    $pattern = preg_quote($path, \'~\');';
        $lines[] = '';
        $lines[] = '    return \'~^\' . preg_replace(\'~\\\\\\\\\\{[^/]+\\\\\\\\\\}~\', \'[^/]+\', $pattern) . \'$~\';';
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, int> $shortNameCounts
     */
    private function responseMapClassReference(string $class, array $shortNameCounts, OpenApiDtoGeneratorOptions $options): string
    {
        $shortName = $this->shortClassName($class);
        if ($shortNameCounts[$shortName] === 1) {
            return $shortName;
        }

        return str_replace(rtrim($options->dtoNamespace, '\\') . '\\', '', $class);
    }

    private function pathKeyRegex(string $key): string
    {
        $pattern = preg_quote($key, '~');

        return '~^' . (preg_replace('~\\\\\{[^/]+\\\\\}~', '[^/]+', $pattern) ?? $pattern) . '$~';
    }

    /**
     * Строковый литерал PHP в одинарных кавычках: экранируются только `\\` и `'`, остальное попадает как есть.
     */
    private function phpString(string $value): string
    {
        return '\'' . str_replace(['\\', '\''], ['\\\\', '\\\''], $value) . '\'';
    }

    private function classNameFromSchemaName(string $schemaName): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $schemaName) ?: [];
        $name  = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $name .= ucfirst($part);
        }

        if ($name === '') {
            $name = 'Response';
        }

        if (preg_match('/^[0-9]/', $name) === 1) {
            $name = 'Dto' . $name;
        }

        return $name;
    }

    private function namespaceSegmentFromPath(string $documentName, string $path): string
    {
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '' && !str_starts_with($segment, '{') && preg_match('/^v[0-9]+$/i', $segment) !== 1,
        ));

        $parts = [$documentName];
        foreach (array_slice($segments, 0, 2) as $segment) {
            $part = $this->pathSegmentToNamespacePart($segment);
            if ($part !== '' && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return implode('\\', $parts);
    }

    private function pathSegmentToNamespacePart(string $segment): string
    {
        return $this->classNameFromSchemaName($segment);
    }

    private function internalSchemaName(string $documentName, string $schemaName): string
    {
        return $documentName . '::' . $schemaName;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitInternalSchemaName(string $schemaName): array
    {
        $parts = explode('::', $schemaName, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return ['Common', $schemaName];
    }

    private function safeDocumentName(string $name): string
    {
        return $this->classNameFromSchemaName($name);
    }

    private function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    private function joinPath(string $directory, string $path): string
    {
        return rtrim($directory, '/\\') . '/' . trim($path, '/\\');
    }

    private function clearGeneratedDirectory(string $dir, string $generatedComment): void
    {
        foreach (glob($this->joinPath($dir, '*.php')) ?: [] as $file) {
            if (is_file($file) && str_contains((string) file_get_contents($file), '@generated ' . $generatedComment)) {
                unlink($file);
            }
        }

        foreach (glob($this->joinPath($dir, '*')) ?: [] as $path) {
            if (is_dir($path)) {
                $this->clearGeneratedDirectory($path, $generatedComment);
            }
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }

    private function shortClassName(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
