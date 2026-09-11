<?php

declare(strict_types=1);

namespace Visol\Neos\ZebraSchemaGenerator\Service;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;

/**
 * Service for generating TypeScript interfaces and Zebra components from Neos node types.
 *
 * @Flow\Scope("singleton")
 */
class SchemaService
{
    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="interfacesTargetPath")
     */
    protected ?string $interfacesTargetPath = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="componentTargetPath")
     */
    protected ?string $componentTargetPathSetting = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="nodeTypesConfigPath")
     */
    protected ?string $nodeTypesConfigPathSetting = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="relativeComponentImportPrefix")
     */
    protected ?string $relativeComponentImportPrefixSetting = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="settingsFilePath")
     */
    protected ?string $settingsFilePathSetting = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="configKeyPath")
     */
    protected ?string $configKeyPath = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="customPropertyTypes")
     * @var array<string, array<string, array{typeName: string, importPath?: string}>>|null
     */
    protected ?array $customPropertyTypes = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="extraProperties")
     * @var array<string, array<string, array{typeName: string, importPath?: string}>>|null
     */
    protected ?array $extraProperties = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="excludedNodeTypes")
     * @var list<string>|null
     */
    protected ?array $excludedNodeTypes = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="excludedNodeTypeNamePatterns")
     * @var list<string>|null
     */
    protected ?array $excludedNodeTypeNamePatterns = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="categoryMarkers")
     * @var array<string, string>|null
     */
    protected ?array $categoryMarkers = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="presetTypeMappings")
     * @var array<string, string>|null
     */
    protected ?array $presetTypeMappings = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="referenceTypesAsString")
     * @var list<string>|null
     */
    protected ?array $referenceTypesAsString = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="baseTypeNames")
     * @var list<string>|null
     */
    protected ?array $baseTypeNames = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="baseTypesTemplatePath")
     */
    protected ?string $baseTypesTemplatePath = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="componentTemplatePath")
     */
    protected ?string $componentTemplatePath = null;

    /**
     * @Flow\InjectConfiguration(package="Visol.Neos.ZebraSchemaGenerator", path="serverComponentBasePath")
     */
    protected ?string $serverComponentBasePath = null;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    // =========================================================================
    // Getters
    // =========================================================================

    public function getTargetPath(): string
    {
        return FLOW_PATH_ROOT . $this->interfacesTargetPath;
    }

    public function getComponentTargetPath(): string
    {
        return FLOW_PATH_ROOT . $this->componentTargetPathSetting;
    }

    public function getNodeTypesConfigPath(): string
    {
        return FLOW_PATH_ROOT . $this->nodeTypesConfigPathSetting;
    }

    public function getSettingsFilePath(): string
    {
        return FLOW_PATH_ROOT . $this->settingsFilePathSetting;
    }

    /**
     * @return list<string>
     */
    public function getExcludedNodeTypes(): array
    {
        return $this->excludedNodeTypes ?? [];
    }

    /**
     * @return list<string>
     */
    public function getExcludedNodeTypeNamePatterns(): array
    {
        return $this->excludedNodeTypeNamePatterns ?? [];
    }

    /**
     * Check whether a node type name matches any configured skip pattern (e.g. "Mixin").
     */
    public function isExcludedByNamePattern(string $nodeTypeName): bool
    {
        foreach ($this->getExcludedNodeTypeNamePatterns() as $pattern) {
            if (str_contains($nodeTypeName, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public function getRelativeComponentImportPrefix(): string
    {
        return $this->relativeComponentImportPrefixSetting ?? '';
    }

    /**
     * Type names defined in the base types template; used to detect required imports.
     *
     * @return list<string>
     */
    public function getBaseTypeNames(): array
    {
        return $this->baseTypeNames ?? [];
    }

    /**
     * Validate that all required settings for interface generation are configured.
     *
     * @return list<string> List of missing setting names (empty if all valid)
     */
    public function validateInterfaceSettings(): array
    {
        $missing = [];
        if ($this->interfacesTargetPath === null) {
            $missing[] = 'interfacesTargetPath';
        }
        if ($this->settingsFilePathSetting === null) {
            $missing[] = 'settingsFilePath';
        }
        if ($this->baseTypesTemplatePath === null) {
            $missing[] = 'baseTypesTemplatePath';
        } elseif (@file_get_contents($this->baseTypesTemplatePath) === false) {
            $missing[] = 'baseTypesTemplatePath (file not readable: ' . $this->baseTypesTemplatePath . ')';
        }
        return $missing;
    }

    /**
     * Validate that all required settings for Zebra component generation are configured.
     *
     * @return list<string> List of missing setting names (empty if all valid)
     */
    public function validateZebraSettings(): array
    {
        $missing = $this->validateInterfaceSettings();
        if ($this->componentTargetPathSetting === null) {
            $missing[] = 'componentTargetPath';
        }
        if ($this->nodeTypesConfigPathSetting === null) {
            $missing[] = 'nodeTypesConfigPath';
        }
        if ($this->relativeComponentImportPrefixSetting === null) {
            $missing[] = 'relativeComponentImportPrefix';
        }
        if ($this->componentTemplatePath === null) {
            $missing[] = 'componentTemplatePath';
        } elseif (@file_get_contents($this->componentTemplatePath) === false) {
            $missing[] = 'componentTemplatePath (file not readable: ' . $this->componentTemplatePath . ')';
        }
        if ($this->serverComponentBasePath === null) {
            $missing[] = 'serverComponentBasePath';
        }
        return $missing;
    }

    // =========================================================================
    // Custom Property Type Methods
    // =========================================================================

    /**
     * Get custom property type override for a specific NodeType and property
     *
     * @return array{typeName: string, importPath?: string}|null
     */
    public function getCustomPropertyType(string $nodeTypeName, string $propertyName): ?array
    {
        $override = $this->customPropertyTypes[$nodeTypeName][$propertyName] ?? null;

        if ($override === null) {
            return null;
        }

        return $override;
    }

    /**
     * Add a custom property type override to the configured write-back settings file
     */
    public function addCustomPropertyTypeToConfig(
        string $nodeTypeName,
        string $propertyName,
        string $typeName,
        string $importPath
    ): void {
        $config = $this->readWriteBackConfig();

        $entry = ['typeName' => $typeName];
        if ($importPath !== '') {
            $entry['importPath'] = $importPath;
        }

        $section = &$this->resolveConfigSection($config);
        $section['customPropertyTypes'][$nodeTypeName][$propertyName] = $entry;
        unset($section);

        $this->writeWriteBackConfig($config);
    }

    /**
     * Get configured extra properties for a specific NodeType
     *
     * @return array<string, array{typeName: string, importPath?: string}>
     */
    public function getExtraProperties(string $nodeTypeName): array
    {
        return $this->extraProperties[$nodeTypeName] ?? [];
    }

    /**
     * Detect extra properties in existing file that are not in the generated content
     *
     * @return array<array{property: string, type: string, line: string}>
     */
    public function detectExtraProperties(string $nodeTypeName, string $generatedContent): array
    {
        $interfaceName = $this->getInterfaceName($nodeTypeName);
        $filePath = $this->getTargetPath() . $interfaceName . '.ts';

        if (!file_exists($filePath)) {
            return [];
        }

        $existingContent = file_get_contents($filePath);
        if ($existingContent === false) {
            return [];
        }

        $existingProps = $this->parseInterfaceProperties($existingContent);
        $generatedProps = $this->parseInterfaceProperties($generatedContent);

        $extras = [];
        foreach ($existingProps as $propName => $existingType) {
            if (!isset($generatedProps[$propName])) {
                // Find the full line from existing content
                $line = '';
                if (preg_match('/^(\s+' . preg_quote($propName, '/') . '\??: .+;.*)$/m', $existingContent, $match) === 1) {
                    $line = $match[1];
                }
                $extras[] = [
                    'property' => $propName,
                    'type' => $existingType,
                    'line' => $line,
                ];
            }
        }

        return $extras;
    }

    /**
     * Add an extra property to the configured write-back settings file
     */
    public function addExtraPropertyToConfig(
        string $nodeTypeName,
        string $propertyName,
        string $typeName,
        string $importPath = ''
    ): void {
        $config = $this->readWriteBackConfig();

        $entry = ['typeName' => $typeName];
        if ($importPath !== '') {
            $entry['importPath'] = $importPath;
        }

        $section = &$this->resolveConfigSection($config);
        $section['extraProperties'][$nodeTypeName][$propertyName] = $entry;
        unset($section);

        $this->writeWriteBackConfig($config);
    }

    /**
     * Read the current write-back settings file into an array (empty if missing).
     *
     * @return array<string, mixed>
     */
    protected function readWriteBackConfig(): array
    {
        $config = [];
        if (file_exists($this->getSettingsFilePath())) {
            $content = file_get_contents($this->getSettingsFilePath());
            if ($content !== false) {
                $config = \Symfony\Component\Yaml\Yaml::parse($content) ?? [];
            }
        }
        return $config;
    }

    /**
     * Persist the write-back settings array back to disk.
     *
     * @param array<string, mixed> $config
     */
    protected function writeWriteBackConfig(array $config): void
    {
        $yaml = \Symfony\Component\Yaml\Yaml::dump($config, 6, 2);
        file_put_contents($this->getSettingsFilePath(), $yaml);
    }

    /**
     * Resolve (creating as needed) the nested config section identified by configKeyPath
     * and return it by reference for mutation.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function &resolveConfigSection(array &$config): array
    {
        $keys = explode('.', $this->configKeyPath ?? 'Visol.Neos.ZebraSchemaGenerator');
        $ref = &$config;
        foreach ($keys as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        return $ref;
    }

    /**
     * Detect divergences between an existing interface file and newly generated content.
     * Compares property types line by line.
     *
     * @return array<array{property: string, existing: string, generated: string}>
     */
    public function detectDivergences(string $nodeTypeName, string $generatedContent): array
    {
        $interfaceName = $this->getInterfaceName($nodeTypeName);
        $filePath = $this->getTargetPath() . $interfaceName . '.ts';

        if (!file_exists($filePath)) {
            return [];
        }

        $existingContent = file_get_contents($filePath);
        if ($existingContent === false) {
            return [];
        }

        $existingProps = $this->parseInterfaceProperties($existingContent);
        $generatedProps = $this->parseInterfaceProperties($generatedContent);

        $divergences = [];
        foreach ($generatedProps as $propName => $generatedType) {
            if (isset($existingProps[$propName]) && $existingProps[$propName] !== $generatedType) {
                $divergences[] = [
                    'property' => $propName,
                    'existing' => $existingProps[$propName],
                    'generated' => $generatedType,
                ];
            }
        }

        return $divergences;
    }

    /**
     * Parse property names and their types from a TypeScript interface string
     *
     * @return array<string, string> Property name => type (including optional marker)
     */
    protected function parseInterfaceProperties(string $content): array
    {
        $properties = [];
        // Match: "  propertyName?: Type;" or "  propertyName: Type;" (with optional comment)
        if (preg_match_all('/^\s+(\w+)(\??): (.+?);/m', $content, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $propName = $match[1];
                $optional = $match[2];
                $type = trim($match[3]);
                $properties[$propName] = $optional . $type;
            }
        }

        return $properties;
    }

    // =========================================================================
    // Interface Generation Methods
    // =========================================================================

    /**
     * Ensure target directory exists
     */
    public function ensureTargetDirectoryExists(): void
    {
        if (!is_dir($this->getTargetPath())) {
            if (!mkdir($this->getTargetPath(), 0777, true) && !is_dir($this->getTargetPath())) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->getTargetPath()));
            }
        }
    }

    /**
     * Generate base types file by copying the configured base types template verbatim.
     */
    public function generateBaseTypesFile(): void
    {
        $content = file_get_contents($this->baseTypesTemplatePath ?? '');
        if ($content === false) {
            throw new \RuntimeException(sprintf(
                'Could not read base types template from "%s". Check Settings: baseTypesTemplatePath',
                $this->baseTypesTemplatePath ?? '(not configured)'
            ));
        }

        file_put_contents($this->getTargetPath() . '_types.ts', $content);
    }

    /**
     * Generate interface content for a single node type (without writing to disk)
     *
     * @return array{interfaceName: string, content: string}|null
     */
    public function buildInterfaceContent(NodeType $nodeType): ?array
    {
        $nodeTypeName = $nodeType->getName();
        $interfaceName = $this->getInterfaceName($nodeTypeName);
        $baseTypeNames = $this->getBaseTypeNames();
        $usedNeosTypes = [];
        $customImports = []; // importPath => [typeName, ...]
        $siblingImports = []; // typeName => true (referenced interfaces from sibling files)
        $propertyLines = [];

        $properties = $nodeType->getProperties();
        $hasProperties = false;

        foreach ($properties as $propertyName => $propertyConfig) {
            // Skip internal and search properties
            if (str_starts_with($propertyName, '_') || str_starts_with($propertyName, 'neos_')) {
                continue;
            }

            $hasProperties = true;

            // Check for custom property type override
            $customType = $this->getCustomPropertyType($nodeTypeName, $propertyName);
            if ($customType !== null) {
                $tsType = $customType['typeName'];
                $importPath = $customType['importPath'] ?? '';
                if ($importPath !== '') {
                    $customImports[$importPath][] = $customType['typeName'];
                }
            } else {
                $type = $propertyConfig['type'] ?? 'string';
                $tsType = $this->mapTypeToTypeScript($type, $propertyConfig);

                // Track which base types are used
                $isNeosType = false;
                foreach ($baseTypeNames as $neosType) {
                    if (str_contains($tsType, $neosType)) {
                        if (!in_array($neosType, $usedNeosTypes, true)) {
                            $usedNeosTypes[] = $neosType;
                        }
                        $isNeosType = true;
                    }
                }

                // Track sibling interface references (not primitives, not baseTypes), including
                // the type argument of a generic base type such as NeosReferencedDocument<Sibling>
                $baseType = null;
                if (preg_match('/<(\w+)>/', $tsType, $genericMatch) === 1) {
                    $baseType = $genericMatch[1];
                } elseif (!$isNeosType) {
                    $baseType = rtrim($tsType, '[]');
                }
                if ($baseType !== null) {
                    $primitives = ['string', 'boolean', 'number', 'any'];
                    if (!in_array($baseType, $primitives, true) && !str_contains($baseType, "'") && $baseType !== $interfaceName) {
                        $siblingImports[$baseType] = true;
                    }
                }
            }

            $isOptional = $this->isPropertyOptional($propertyConfig);
            $optionalMark = $isOptional ? '?' : '';

            $comment = $this->generatePropertyComment($propertyConfig);
            $commentStr = $comment !== '' ? " // {$comment}" : '';

            $propertyLines[] = "  {$propertyName}{$optionalMark}: {$tsType};{$commentStr}";
        }

        // Append configured extra properties
        $extraProps = $this->getExtraProperties($nodeTypeName);
        foreach ($extraProps as $extraPropName => $extraPropConfig) {
            $hasProperties = true;
            $extraType = $extraPropConfig['typeName'];
            $extraImportPath = $extraPropConfig['importPath'] ?? '';

            if ($extraImportPath !== '') {
                $customImports[$extraImportPath][] = rtrim($extraType, '[]');
            } else {
                $baseExtraType = rtrim($extraType, '[]');
                $isExtraNeosType = false;
                foreach ($baseTypeNames as $neosType) {
                    if ($baseExtraType === $neosType) {
                        if (!in_array($neosType, $usedNeosTypes, true)) {
                            $usedNeosTypes[] = $neosType;
                        }
                        $isExtraNeosType = true;
                    }
                }
                if (!$isExtraNeosType && !in_array($baseExtraType, ['string', 'boolean', 'number', 'any'], true)) {
                    $siblingImports[$baseExtraType] = true;
                }
            }

            $propertyLines[] = "  {$extraPropName}: {$extraType};";
        }

        // Build file content
        $lines = [];

        // Add base type imports if needed
        if ($usedNeosTypes !== []) {
            sort($usedNeosTypes);
            $imports = implode(', ', $usedNeosTypes);
            $lines[] = "import { {$imports} } from './_types';";
        }

        // Add custom type imports
        foreach ($customImports as $importPath => $typeNames) {
            // Strip array brackets from type names for import statements
            $cleanTypes = array_map(fn($t) => rtrim($t, '[]'), $typeNames);
            $uniqueTypes = array_unique($cleanTypes);
            sort($uniqueTypes);
            $imports = implode(', ', $uniqueTypes);
            $lines[] = "import { {$imports} } from '{$importPath}';";
        }

        // Add sibling interface imports
        if ($siblingImports !== []) {
            $siblingTypes = array_keys($siblingImports);
            sort($siblingTypes);
            foreach ($siblingTypes as $siblingType) {
                $lines[] = "import type { {$siblingType} } from './{$siblingType}';";
            }
        }

        if ($usedNeosTypes !== [] || $customImports !== [] || $siblingImports !== []) {
            $lines[] = "";
        }

        $lines[] = "export interface {$interfaceName} {";

        if ($hasProperties) {
            $lines = array_merge($lines, $propertyLines);
        } else {
            $lines[] = "  // No direct properties - content comes from child nodes";
        }

        $lines[] = "}";

        return [
            'interfaceName' => $interfaceName,
            'content' => implode("\n", $lines) . "\n",
        ];
    }

    /**
     * Write interface content to disk
     */
    public function writeInterfaceFile(string $interfaceName, string $content): void
    {
        $fileName = $this->getTargetPath() . $interfaceName . '.ts';
        file_put_contents($fileName, $content);
    }

    /**
     * Remove import statements whose imported types are not used in the interface body.
     * Handles both full removal and partial removal (when only some types from a multi-type import are unused).
     */
    public function removeUnusedImports(string $content): string
    {
        // Split into import section and body
        $importPattern = '/^import\s.+?[\'"][^\'"]+[\'"];?\s*$/ms';
        preg_match_all($importPattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if ($matches[0] === []) {
            return $content;
        }

        // Extract interface body (everything after the last import + blank line)
        $lastImport = end($matches[0]);
        $bodyStart = $lastImport[1] + strlen($lastImport[0]);
        $body = substr($content, $bodyStart);

        $removals = [];
        $replacements = [];

        foreach ($matches[0] as [$importStatement, $offset]) {
            // Extract type names from this import
            if (preg_match('/import\s+(?:type\s+)?\{([^}]+)\}/s', $importStatement, $typeMatch) !== 1) {
                continue;
            }

            $typeList = preg_split('/\s*,\s*/', trim($typeMatch[1]));
            if ($typeList === false) {
                continue;
            }

            $typeList = array_filter(array_map('trim', $typeList), fn($t) => $t !== '');
            $usedTypes = [];
            $unusedTypes = [];

            foreach ($typeList as $typeName) {
                if (preg_match('/\b' . preg_quote($typeName, '/') . '\b/', $body) === 1) {
                    $usedTypes[] = $typeName;
                } else {
                    $unusedTypes[] = $typeName;
                }
            }

            if ($unusedTypes === []) {
                // All types used, keep as-is
                continue;
            }

            if ($usedTypes === []) {
                // No types used, remove entire import
                $removals[] = $importStatement;
            } else {
                // Partial: rewrite import with only used types
                $importPrefix = str_contains($importStatement, 'import type') ? 'import type' : 'import';
                preg_match('/from\s+([\'"][^\'"]+[\'"])/', $importStatement, $fromMatch);
                $fromClause = $fromMatch[0] ?? '';
                $newImport = $importPrefix . ' { ' . implode(', ', $usedTypes) . ' } ' . $fromClause . ';';
                $replacements[$importStatement] = $newImport;
            }
        }

        // Apply replacements
        foreach ($replacements as $old => $new) {
            $content = str_replace($old, $new, $content);
        }

        // Apply removals
        foreach ($removals as $removal) {
            $content = str_replace($removal . "\n", '', $content);
        }

        // Clean up double blank lines left by removals
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return $content;
    }

    /**
     * Generate barrel index file
     * Scans directory for all existing interface files and includes them all
     *
     * @param list<string> $interfaceNames
     */
    public function generateIndexFile(array $interfaceNames): void
    {
        // Scan directory for all existing interface files
        $allInterfaces = [];
        $files = glob($this->getTargetPath() . '*.ts');
        if ($files === false) {
            $files = [];
        }

        foreach ($files as $file) {
            $filename = basename($file, '.ts');
            // Skip index.ts and _types.ts
            if ($filename !== 'index' && $filename !== '_types') {
                $allInterfaces[] = $filename;
            }
        }

        // Merge with newly generated (in case files haven't been written yet)
        $allInterfaces = array_unique(array_merge($allInterfaces, $interfaceNames));
        sort($allInterfaces);

        $lines = [
            "/**",
            " * Auto-generated barrel file for Neos node type interfaces",
            " * Generated by: ddev app-neos-next-flow visol.neos.zebraschemagenerator:schema:generateinterfaces",
            " */",
            "",
            "// Base types",
            "export * from './_types'",
            "",
            "// Node type interfaces",
        ];

        foreach ($allInterfaces as $name) {
            $lines[] = "export * from './{$name}'";
        }

        $content = implode("\n", $lines) . "\n";
        file_put_contents($this->getTargetPath() . 'index.ts', $content);
    }

    /**
     * Transform node type name to interface name
     * "Abl.Site:Content.Accordion" -> "AblSite_ContentAccordion"
     */
    public function getInterfaceName(string $nodeTypeName): string
    {
        $name = str_replace('.', '', $nodeTypeName);
        return str_replace(':', '_', $name);
    }

    /**
     * Map Neos property type to TypeScript type
     *
     * @param array<string, mixed> $propertyConfig
     */
    protected function mapTypeToTypeScript(string $type, array $propertyConfig): string
    {
        // First check for presets (these override explicit types)
        $preset = $propertyConfig['options']['preset'] ?? null;
        if (is_string($preset)) {
            foreach ($this->presetTypeMappings ?? [] as $presetSubstring => $mappedType) {
                if (str_contains($preset, $presetSubstring)) {
                    return $mappedType;
                }
            }
        }

        // Handle SelectBoxEditor with defined options
        if ($this->hasSelectBoxEditor($propertyConfig)) {
            $options = $this->getSelectBoxOptions($propertyConfig);
            if ($options !== []) {
                return $this->generateUnionType($options);
            }
        }

        return match ($type) {
            'boolean' => 'boolean',
            'string' => 'string',
            'integer', 'int', 'float' => 'number',
            'DateTime' => 'NeosDateTime',

            // Images
            'Neos\Media\Domain\Model\ImageInterface',
            'Neos\Media\Domain\Model\Image' => 'NeosImageData',

            // Assets - check if constrained to image types
            'Neos\Media\Domain\Model\Asset' => $this->isImageAsset($propertyConfig) ? 'NeosImageData' : 'NeosAssetData',

            // References
            'reference' => $this->getReferencedType($propertyConfig, false),
            'references' => $this->getReferencedType($propertyConfig, true),

            // Arrays
            'array' => $this->getArrayType($propertyConfig),
            'array<Neos\Media\Domain\Model\Asset>' => 'NeosAssetData[]',
            'array<Neos\Media\Domain\Model\Image>' => 'NeosImageData[]',

            default => 'any',
        };
    }

    /**
     * Check if an Asset property is constrained to image types
     *
     * @param array<string, mixed> $propertyConfig
     */
    protected function isImageAsset(array $propertyConfig): bool
    {
        $mediaTypes = $propertyConfig['ui']['inspector']['editorOptions']['constraints']['mediaTypes'] ?? [];

        if (!is_array($mediaTypes) || $mediaTypes === []) {
            return false;
        }

        // Check if all allowed types are image types
        foreach ($mediaTypes as $mediaType) {
            if (!str_starts_with($mediaType, 'image/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if property uses SelectBoxEditor
     *
     * @param array<string, mixed> $propertyConfig
     */
    protected function hasSelectBoxEditor(array $propertyConfig): bool
    {
        return ($propertyConfig['ui']['inspector']['editor'] ?? '') === 'Neos.Neos/Inspector/Editors/SelectBoxEditor';
    }

    /**
     * Extract SelectBox options from config
     *
     * @param array<string, mixed> $propertyConfig
     * @return list<string>
     */
    protected function getSelectBoxOptions(array $propertyConfig): array
    {
        $values = $propertyConfig['ui']['inspector']['editorOptions']['values'] ?? [];
        /** @var list<string> */
        return array_keys($values);
    }

    /**
     * Generate TypeScript union type from options
     *
     * @param list<string> $options
     */
    protected function generateUnionType(array $options): string
    {
        $quotedOptions = array_map(fn($opt) => "'{$opt}'", $options);
        return implode(' | ', $quotedOptions);
    }

    /**
     * Determine type for reference/references properties
     *
     * @param array<string, mixed> $propertyConfig
     */
    protected function getReferencedType(array $propertyConfig, bool $isArray): string
    {
        $editorOptions = $propertyConfig['ui']['inspector']['editorOptions'] ?? [];

        // Check for reference target node types configured to map to plain strings
        // (e.g. taxonomy identifiers), keeping this package free of taxonomy dependencies.
        $dataSourceData = $editorOptions['dataSourceAdditionalData'] ?? [];
        $nodeTypes = $dataSourceData['nodeTypes'] ?? [];

        foreach ($this->referenceTypesAsString ?? [] as $referenceType) {
            if (in_array($referenceType, $nodeTypes, true)) {
                return $isArray ? 'string[]' : 'string';
            }
        }

        // Check for specific node type references
        $allowedNodeTypes = $editorOptions['nodeTypes'] ?? [];
        if ($allowedNodeTypes !== []) {
            // For node references, API returns the node properties
            // Use the first allowed type to generate interface reference
            $targetNodeTypeName = $allowedNodeTypes[0];
            $targetType = $this->isInterfaceGenerated($targetNodeTypeName)
                ? $this->inferTargetInterface($allowedNodeTypes)
                : null;

            // For document references, the ContentApi adds _identifier, _nodeType and _nodeUri
            if ($this->isDocumentNodeType($targetNodeTypeName)) {
                $targetType = $targetType !== null ? "NeosReferencedDocument<{$targetType}>" : 'NeosReferencedDocument';
            }

            // No interface is generated for the target (abstract or excluded), so importing it would break
            $targetType ??= 'NeosNodeReference';

            return $isArray ? "{$targetType}[]" : $targetType;
        }

        // Default: generic node reference
        return $isArray ? 'NeosNodeReference[]' : 'NeosNodeReference';
    }

    /**
     * Whether an interface file is generated for the node type (see generateInterfacesCommand):
     * it exists, is not abstract and is not excluded by name or pattern.
     */
    protected function isInterfaceGenerated(string $nodeTypeName): bool
    {
        if (!$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
            return false;
        }

        return !$this->nodeTypeManager->getNodeType($nodeTypeName)->isAbstract()
            && !in_array($nodeTypeName, $this->getExcludedNodeTypes(), true)
            && !$this->isExcludedByNamePattern($nodeTypeName);
    }

    /**
     * Whether the node type is a document, for which the ContentApi resolves a URI on references
     */
    protected function isDocumentNodeType(string $nodeTypeName): bool
    {
        return $this->nodeTypeManager->hasNodeType($nodeTypeName)
            && $this->nodeTypeManager->getNodeType($nodeTypeName)->isOfType('Neos.Neos:Document');
    }

    /**
     * Infer target interface name from allowed node types
     *
     * @param list<string> $allowedNodeTypes
     */
    protected function inferTargetInterface(array $allowedNodeTypes): string
    {
        if ($allowedNodeTypes === []) {
            return 'NeosNodeReference';
        }

        // Use first allowed type
        $firstType = $allowedNodeTypes[0];
        return $this->getInterfaceName($firstType);
    }

    /**
     * Determine type for array properties
     *
     * @param array<string, mixed> $propertyConfig
     */
    protected function getArrayType(array $propertyConfig): string
    {
        $type = $propertyConfig['type'] ?? 'array';

        if (preg_match('/^array<(.+)>$/', $type, $matches) === 1) {
            $itemType = $matches[1];

            return match ($itemType) {
                'Neos\Media\Domain\Model\Asset' => 'NeosAssetData[]',
                'Neos\Media\Domain\Model\Image' => 'NeosImageData[]',
                default => 'any[]',
            };
        }

        return 'any[]';
    }

    /**
     * Check if property is optional
     *
     * @param array<string, mixed> $propertyConfig
     */
    protected function isPropertyOptional(array $propertyConfig): bool
    {
        // Check for NotEmptyValidator = required = NOT optional
        $validation = $propertyConfig['validation'] ?? [];
        if (isset($validation['Neos.Neos/Validation/NotEmptyValidator'])) {
            return false;
        }

        // Has meaningful default value = always has a value = NOT optional
        if (array_key_exists('defaultValue', $propertyConfig)) {
            $default = $propertyConfig['defaultValue'];

            // Empty/null-like defaults mean the property can be empty = optional
            if ($default === '' || $default === null || $default === []) {
                return true;
            }

            // Has a real default value = NOT optional
            return false;
        }

        // No default and no required validator = can be empty = optional
        return true;
    }

    /**
     * Generate comment for property (default value, format hints)
     *
     * @param array<string, mixed> $propertyConfig
     */
    protected function generatePropertyComment(array $propertyConfig): string
    {
        $parts = [];

        // Add default value (skip empty strings and multiline values)
        if (isset($propertyConfig['defaultValue'])) {
            $default = $propertyConfig['defaultValue'];
            $formattedDefault = $this->formatDefaultValue($default);

            // Skip empty or multiline defaults
            if ($formattedDefault !== '' && !str_contains($formattedDefault, "\n")) {
                $parts[] = 'default: ' . $formattedDefault;
            }
        }

        // Add regex pattern hint if present
        $validation = $propertyConfig['validation'] ?? [];
        if (isset($validation['Neos.Neos/Validation/RegularExpressionValidator'])) {
            $regex = $validation['Neos.Neos/Validation/RegularExpressionValidator']['regularExpression'] ?? null;
            if (is_string($regex) && $regex !== '') {
                $parts[] = "format: {$regex}";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Format default value for comment
     */
    protected function formatDefaultValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            $encoded = json_encode($value);
            return $encoded !== false ? $encoded : '[]';
        }
        return (string) $value;
    }

    // =========================================================================
    // Zebra Component Generation Methods
    // =========================================================================

    /**
     * Ensure directory exists
     */
    public function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0777, true) && !is_dir($path)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $path));
            }
        }
    }

    /**
     * Get list of packages in DistributionPackages directory
     *
     * @return list<string>
     */
    public function getDistributionPackages(): array
    {
        $distributionPackagesPath = FLOW_PATH_ROOT . 'DistributionPackages/';
        $packages = [];

        if (is_dir($distributionPackagesPath)) {
            $dirs = scandir($distributionPackagesPath);
            if ($dirs !== false) {
                foreach ($dirs as $dir) {
                    if ($dir !== '.' && $dir !== '..' && is_dir($distributionPackagesPath . $dir)) {
                        $packages[] = $dir;
                    }
                }
            }
        }

        return $packages;
    }

    /**
     * Check if NodeType belongs to a DistributionPackage
     *
     * @param list<string> $distributionPackages
     */
    public function isDistributionPackageNodeType(string $nodeTypeName, array $distributionPackages): bool
    {
        $parts = explode(':', $nodeTypeName);
        if (count($parts) !== 2) {
            return false;
        }
        $packageName = $parts[0];

        return in_array($packageName, $distributionPackages, true);
    }

    /**
     * Get NodeType category (e.g. content or document) based on configured markers
     */
    public function getNodeTypeCategory(string $nodeTypeName): ?string
    {
        foreach ($this->categoryMarkers ?? [] as $category => $marker) {
            if (str_contains($nodeTypeName, $marker)) {
                return $category;
            }
        }
        return null;
    }

    /**
     * Check if TypeScript interface exists for NodeType
     */
    public function interfaceExists(string $nodeTypeName): bool
    {
        $interfaceName = $this->getInterfaceName($nodeTypeName);
        $interfaceFile = $this->getTargetPath() . $interfaceName . '.ts';
        return file_exists($interfaceFile);
    }

    /**
     * Resolve an import path from nodeTypes.ts to an absolute file path
     * e.g., "../../components/serverComponents/content/Text" -> "/path/to/next/src/components/serverComponents/content/Text.tsx"
     */
    public function resolveImportPath(string $importPath): ?string
    {
        // Derive base components directory from componentTargetPath
        // e.g., ".../next/src/components/serverComponents/" -> ".../next/src/components/"
        $componentsBase = dirname($this->getComponentTargetPath()) . '/';

        // Handle relative paths: ../../components/serverComponents/content/Text
        if (preg_match('/\.\.\/\.\.\/components\/(.+)$/', $importPath, $matches) === 1) {
            return $componentsBase . $matches[1] . '.tsx';
        }

        // Handle @/ alias paths: @/components/serverComponents/content/Text
        if (preg_match('/@\/components\/(.+)$/', $importPath, $matches) === 1) {
            return $componentsBase . $matches[1] . '.tsx';
        }

        return null;
    }

    /**
     * Transform NodeType name to component class name
     * "Abl.Site:Content.AccordionItem" -> "ContentAccordionItem"
     */
    public function getComponentName(string $nodeTypeName): string
    {
        $interfaceName = $this->getInterfaceName($nodeTypeName);
        // "AblSite_ContentAccordionItem" -> "ContentAccordionItem"
        $parts = explode('_', $interfaceName, 2);
        return $parts[1] ?? $interfaceName;
    }

    /**
     * Transform NodeType name to file name (without extension)
     * "Abl.Site:Content.AccordionItem" -> "AblSite_ContentAccordionItem"
     */
    public function getFileName(string $nodeTypeName): string
    {
        return $this->getInterfaceName($nodeTypeName);
    }

    /**
     * Generate component template
     */
    public function getComponentTemplate(
        string $nodeTypeName,
        string $interfaceName,
        string $componentName,
        string $category
    ): string {
        $fileName = $this->getFileName($nodeTypeName);
        $relativePath = $category . '/' . $fileName;

        $placeholders = [
            '{{componentName}}' => $componentName,
            '{{interfaceName}}' => $interfaceName,
            '{{category}}' => $category,
            '{{fileName}}' => $fileName,
            '{{relativePath}}' => $relativePath,
            '{{serverComponentBasePath}}' => $this->serverComponentBasePath ?? '',
        ];

        $templateContent = file_get_contents($this->componentTemplatePath ?? '');
        if ($templateContent === false) {
            throw new \RuntimeException(sprintf(
                'Could not read component template from "%s". Check Settings: componentTemplatePath',
                $this->componentTemplatePath ?? '(not configured)'
            ));
        }

        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $templateContent
        );
    }

    /**
     * Generate a new server component file
     */
    public function generateServerComponent(string $nodeTypeName, string $category): void
    {
        $interfaceName = $this->getInterfaceName($nodeTypeName);
        $componentName = $this->getComponentName($nodeTypeName);
        $fileName = $this->getFileName($nodeTypeName);

        $template = $this->getComponentTemplate($nodeTypeName, $interfaceName, $componentName, $category);

        $targetDir = $this->getComponentTargetPath() . $category . '/';
        $this->ensureDirectoryExists($targetDir);

        file_put_contents($targetDir . $fileName . '.tsx', $template);
    }

    /**
     * Migrate component from old path to new path
     */
    public function migrateComponent(string $oldPath, string $newPath): void
    {
        if (file_exists($oldPath) && $oldPath !== $newPath) {
            rename($oldPath, $newPath);
        }
    }

    /**
     * Parse existing nodeTypes.ts and categorize all entries
     * Returns array with 'content', 'document', and 'manual' categories
     *
     * @return array{content: array<string, array{componentName: string, importPath: string}>, document: array<string, array{componentName: string, importPath: string}>, manual: array<string, array{componentName: string, importPath: string}>}
     */
    public function parseExistingNodeTypesConfig(): array
    {
        $result = [
            'content' => [],
            'document' => [],
            'manual' => [],
        ];

        if (!file_exists($this->getNodeTypesConfigPath())) {
            return $result;
        }

        $content = file_get_contents($this->getNodeTypesConfigPath());
        if ($content === false) {
            return $result;
        }

        // Get list of distribution packages to identify auto-generated entries
        $distributionPackages = $this->getDistributionPackages();

        // Parse import statements: import ComponentName from "path";
        if (preg_match_all('/import\s+(\w+)\s+from\s+["\']([^"\']+)["\'];?/', $content, $importMatches, PREG_SET_ORDER) > 0) {
            $imports = [];
            foreach ($importMatches as $match) {
                $imports[$match[1]] = $match[2];
            }
        } else {
            $imports = [];
        }

        // Parse nodeType mappings: 'NodeType.Name:Type': ComponentName,
        if (preg_match_all("/['\"]([^'\"]+)['\"]\s*:\s*(\w+)\s*,?/", $content, $mappingMatches, PREG_SET_ORDER) > 0) {
            foreach ($mappingMatches as $match) {
                $nodeTypeName = $match[1];
                $componentName = $match[2];

                if (!isset($imports[$componentName])) {
                    continue;
                }

                $entryData = [
                    'componentName' => $componentName,
                    'importPath' => $imports[$componentName],
                ];

                // Check if this is from a DistributionPackage
                $isDistributionPackage = $this->isDistributionPackageNodeType($nodeTypeName, $distributionPackages);

                if (!$isDistributionPackage) {
                    // Manual entry (not from DistributionPackage)
                    $result['manual'][$nodeTypeName] = $entryData;
                } else {
                    // Categorize by type
                    $category = $this->getNodeTypeCategory($nodeTypeName);
                    if ($category === 'content') {
                        $result['content'][$nodeTypeName] = $entryData;
                    } elseif ($category === 'document') {
                        $result['document'][$nodeTypeName] = $entryData;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Update nodeTypes.ts configuration file
     *
     * @param array<string, array{category: string, componentName: string, fileName: string}> $newEntries New/updated entries for the processed type
     * @param array<string, array<string, array{componentName: string, importPath: string}>> $preservedEntries Categorized entries to preserve (content, document, manual)
     * @param string|null $processedType The type being processed ('content' or 'document')
     */
    public function updateNodeTypesConfig(array $newEntries, array $preservedEntries, ?string $processedType = null): void
    {
        $imports = [];
        $documentTypes = [];
        $contentTypes = [];
        $manualMappings = [];
        $manualImports = [];

        // Add new/updated entries for the processed type
        foreach ($newEntries as $nodeTypeName => $componentInfo) {
            $category = $componentInfo['category'];
            $componentName = $componentInfo['componentName'];
            $fileName = $componentInfo['fileName'];

            $importPath = $this->getRelativeComponentImportPrefix() . "{$category}/{$fileName}";
            $imports[$componentName] = $importPath;

            if ($category === 'document') {
                $documentTypes[$nodeTypeName] = $componentName;
            } else {
                $contentTypes[$nodeTypeName] = $componentName;
            }
        }

        // Preserve document entries when NOT processing document
        // (i.e., when processing content only, preserve document entries)
        // When $processedType is null (both), don't preserve - we're regenerating everything
        if ($processedType === 'content' && isset($preservedEntries['document']) && $preservedEntries['document'] !== []) {
            foreach ($preservedEntries['document'] as $nodeTypeName => $entryInfo) {
                $componentName = $entryInfo['componentName'];
                $importPath = $entryInfo['importPath'];

                if (!isset($imports[$componentName])) {
                    $imports[$componentName] = $importPath;
                }
                $documentTypes[$nodeTypeName] = $componentName;
            }
        }

        // Preserve content entries when NOT processing content
        // (i.e., when processing document only, preserve content entries)
        // When $processedType is null (both), don't preserve - we're regenerating everything
        if ($processedType === 'document' && isset($preservedEntries['content']) && $preservedEntries['content'] !== []) {
            foreach ($preservedEntries['content'] as $nodeTypeName => $entryInfo) {
                $componentName = $entryInfo['componentName'];
                $importPath = $entryInfo['importPath'];

                if (!isset($imports[$componentName])) {
                    $imports[$componentName] = $importPath;
                }
                $contentTypes[$nodeTypeName] = $componentName;
            }
        }

        // Always preserve manual entries
        if (isset($preservedEntries['manual']) && $preservedEntries['manual'] !== []) {
            foreach ($preservedEntries['manual'] as $nodeTypeName => $entryInfo) {
                $componentName = $entryInfo['componentName'];
                $importPath = $entryInfo['importPath'];

                if (!isset($imports[$componentName])) {
                    $manualImports[$componentName] = $importPath;
                }
                $manualMappings[$nodeTypeName] = $componentName;
            }
        }

        // Build file content
        $lines = [
            "/**",
            " * Auto-generated Zebra nodeTypes configuration",
            " * Generated by: ddev app-neos-next-flow visol.neos.zebraschemagenerator:schema:generatezebracomponentswithconfig",
            " * Entries from other categories and manual entries are preserved between regenerations.",
            " */",
            "",
            "import { initNodeTypes } from '@networkteam/zebra/server';",
            "",
        ];

        // Add auto-generated imports (sorted)
        if ($imports !== []) {
            $lines[] = "// Component imports";
            ksort($imports);
            foreach ($imports as $componentName => $importPath) {
                $lines[] = "import {$componentName} from \"{$importPath}\";";
            }
            $lines[] = "";
        }

        // Add manual imports
        if ($manualImports !== []) {
            $lines[] = "// Manual component imports (preserved)";
            ksort($manualImports);
            foreach ($manualImports as $componentName => $importPath) {
                $lines[] = "import {$componentName} from \"{$importPath}\";";
            }
            $lines[] = "";
        }

        // Build initNodeTypes call
        $lines[] = "initNodeTypes(";
        $lines[] = "    {";

        $hasContent = false;

        // Documents
        if ($documentTypes !== []) {
            $lines[] = "        // Documents";
            ksort($documentTypes);
            foreach ($documentTypes as $nodeTypeName => $componentName) {
                $lines[] = "        '{$nodeTypeName}': {$componentName},";
            }
            $hasContent = true;
        }

        // Content
        if ($contentTypes !== []) {
            if ($hasContent) {
                $lines[] = "";
            }
            $lines[] = "        // Content";
            ksort($contentTypes);
            foreach ($contentTypes as $nodeTypeName => $componentName) {
                $lines[] = "        '{$nodeTypeName}': {$componentName},";
            }
            $hasContent = true;
        }

        // Manual entries
        if ($manualMappings !== []) {
            if ($hasContent) {
                $lines[] = "";
            }
            $lines[] = "        // Manual entries (preserved)";
            ksort($manualMappings);
            foreach ($manualMappings as $nodeTypeName => $componentName) {
                $lines[] = "        '{$nodeTypeName}': {$componentName},";
            }
        }

        $lines[] = "    }";
        $lines[] = ");";

        $content = implode("\n", $lines) . "\n";

        $this->ensureDirectoryExists(dirname($this->getNodeTypesConfigPath()) . '/');
        file_put_contents($this->getNodeTypesConfigPath(), $content);
    }
}
