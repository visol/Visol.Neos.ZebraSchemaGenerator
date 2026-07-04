<?php

declare(strict_types=1);

namespace Visol\Neos\ZebraSchemaGenerator\Command;

use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Visol\Neos\ZebraSchemaGenerator\Service\SchemaService;

/**
 * CLI Command Controller for generating TypeScript interfaces from Neos node types.
 *
 * @Flow\Scope("singleton")
 */
class SchemaCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var SchemaService
     */
    protected $schemaService;

    /**
     * Generate TypeScript interfaces for Neos content node types
     *
     * @param string|null $package Optional: Filter by specific package (e.g., "Abl.Site").
     *                             If omitted, generates interfaces for all packages.
     * @param bool $forceOverwrite Force overwrite of divergent property types without prompting.
     *                             Configured overrides are always respected.
     */
    public function generateInterfacesCommand(?string $package = null, bool $forceOverwrite = false): void
    {
        $this->outputLine('');
        $this->outputLine('<info>TypeScript Interface Generator</info>');
        $this->outputLine('================================');
        $this->outputLine('');

        $missingSettings = $this->schemaService->validateInterfaceSettings();
        if ($missingSettings !== []) {
            $this->outputLine('<error>Missing required settings:</error>');
            foreach ($missingSettings as $setting) {
                $this->outputLine(sprintf('  - Visol.Neos.ZebraSchemaGenerator.%s', $setting));
            }
            return;
        }

        $this->schemaService->ensureTargetDirectoryExists();
        $this->schemaService->generateBaseTypesFile();
        $this->outputLine("  Generated: _types.ts");

        // Include abstract node types so configured mixins can be generated;
        // all other abstract types are skipped below.
        $nodeTypes = $this->nodeTypeManager->getNodeTypes(true);
        $generatedInterfaces = [];
        $excludedNodeTypes = $this->schemaService->getExcludedNodeTypes();

        foreach ($nodeTypes as $nodeType) {
            $name = $nodeType->getName();
            $isMixin = $this->schemaService->isMixinByNamePattern($name);

            // Abstract types only get an interface when configured as mixins
            if ($nodeType->isAbstract() && !$isMixin) {
                continue;
            }

            // Skip excluded types
            if (in_array($name, $excludedNodeTypes, true)) {
                continue;
            }

            // Skip types matching configured name patterns (Constraints etc.);
            // configured mixin patterns win over exclusion patterns
            if (!$isMixin && $this->schemaService->isExcludedByNamePattern($name)) {
                continue;
            }

            // Filter by package if specified
            if ($package !== null) {
                if (!str_starts_with($name, $package . ':')) {
                    continue;
                }
            }

            // Mixin interfaces describe a partial shape: all properties optional
            $result = $this->schemaService->buildInterfaceContent($nodeType, $isMixin);
            if ($result === null) {
                continue;
            }

            $interfaceName = $result['interfaceName'];
            $generatedContent = $result['content'];

            $finalContent = $generatedContent;
            $hadChanges = false;

            // Detect divergences with existing file
            $divergences = $this->schemaService->detectDivergences($name, $generatedContent);
            if ($divergences !== []) {
                $finalContent = $this->handleDivergences(
                    $name,
                    $interfaceName,
                    $finalContent,
                    $divergences,
                    $forceOverwrite
                );
                $hadChanges = true;
            }

            // Detect extra properties in existing file
            $extraProperties = $this->schemaService->detectExtraProperties($name, $generatedContent);
            if ($extraProperties !== []) {
                $finalContent = $this->handleExtraProperties(
                    $name,
                    $interfaceName,
                    $finalContent,
                    $extraProperties,
                    $forceOverwrite
                );
                $hadChanges = true;
            }

            // Remove any imports that became unused after divergence/extra-property handling
            if ($hadChanges) {
                $finalContent = $this->schemaService->removeUnusedImports($finalContent);
            }

            $this->schemaService->writeInterfaceFile($interfaceName, $finalContent);
            $generatedInterfaces[] = $interfaceName;
            if (!$hadChanges) {
                $this->outputLine("  Generated: {$interfaceName}");
            }
        }

        // Generate barrel index file
        $this->schemaService->generateIndexFile($generatedInterfaces);
        $this->outputLine("  Generated: index.ts");

        $packageInfo = $package !== null ? " for package '{$package}'" : " for all packages";
        $this->outputLine('');
        $this->outputLine(sprintf('<success>Generated %d interfaces%s</success>', count($generatedInterfaces), $packageInfo));
        $this->outputLine(sprintf('<success>The interfaces can be found in %s</success>', $this->schemaService->getTargetPath()));
        $this->outputLine('');
    }

    /**
     * Handle divergences between existing and generated interface content
     *
     * @param array<array{property: string, existing: string, generated: string}> $divergences
     * @return string The final content to write
     */
    protected function handleDivergences(
        string $nodeTypeName,
        string $interfaceName,
        string $generatedContent,
        array $divergences,
        bool $forceOverwrite
    ): string {
        $this->outputLine('');
        $this->outputLine(sprintf('  <comment>Divergence detected in %s:</comment>', $interfaceName));

        foreach ($divergences as $divergence) {
            $this->outputLine(sprintf(
                '    Property "%s": existing = "%s", generated = "%s"',
                $divergence['property'],
                $divergence['existing'],
                $divergence['generated']
            ));
        }

        if ($forceOverwrite) {
            $this->outputLine('    -> Force overwriting all divergent properties');
            return $generatedContent;
        }

        // Read existing file to use as base for selective overwrites
        $existingFilePath = $this->schemaService->getTargetPath() . $interfaceName . '.ts';
        $existingContent = file_get_contents($existingFilePath);
        if ($existingContent === false) {
            return $generatedContent;
        }
        $finalContent = $generatedContent;
        $keptExisting = false;

        foreach ($divergences as $divergence) {
            $property = $divergence['property'];
            $existingType = $divergence['existing'];
            $generatedType = $divergence['generated'];

            $this->outputLine('');
            $this->outputLine(sprintf('    Property "%s":', $property));
            $this->outputLine(sprintf('      Overwrite with: %s', $generatedType));
            $this->outputLine(sprintf('      Keep existing:  %s', $existingType));

            $choice = $this->output->select(
                sprintf('    Choose action for "%s"', $property),
                ['Overwrite', 'Keep existing', 'Add to config (will not ask again)'],
                1
            );

            if ($choice === 'Keep existing' || $choice === 'Add to config (will not ask again)') {
                // Replace the generated type with the existing type in the final content
                $finalContent = $this->replacePropertyType($finalContent, $property, $existingType, $existingContent);
                $keptExisting = true;

                if ($choice === 'Add to config (will not ask again)') {
                    // Extract the clean type name (remove optional marker and array brackets)
                    $cleanType = ltrim($existingType, '?');
                    $isArray = str_ends_with($cleanType, '[]');
                    $cleanType = rtrim($cleanType, '[]');

                    $primitives = ['string', 'boolean', 'number', 'any'];
                    $configType = $isArray ? $cleanType . '[]' : $cleanType;

                    if (in_array($cleanType, $primitives, true)) {
                        // Primitive types don't need an import path
                        $this->schemaService->addCustomPropertyTypeToConfig(
                            $nodeTypeName,
                            $property,
                            $configType,
                            ''
                        );
                        $this->outputLine(sprintf('    -> Added to settings file'));
                    } else {
                        // Try to find the import path from the existing file
                        $importPath = $this->extractImportPathForType($existingContent, $cleanType);

                        // If not found via import, check if it's a sibling interface file
                        if ($importPath === null && file_exists($this->schemaService->getTargetPath() . $cleanType . '.ts')) {
                            $importPath = './' . $cleanType;
                        }

                        if ($importPath !== null) {
                            $this->schemaService->addCustomPropertyTypeToConfig(
                                $nodeTypeName,
                                $property,
                                $configType,
                                $importPath
                            );
                            $this->outputLine(sprintf('    -> Added to settings file'));
                        } else {
                            $this->outputLine(sprintf('    -> Could not determine import path for type "%s"', $cleanType));
                            $this->outputLine(sprintf('    -> Please add manually to the settings file'));
                        }
                    }
                }

                $this->outputLine(sprintf('    -> Keeping existing type for "%s"', $property));
            } else {
                $this->outputLine(sprintf('    -> Overwriting "%s"', $property));
            }
        }

        // If we kept any existing types, we need to also preserve their imports
        if ($keptExisting) {
            $finalContent = $this->mergeImports($finalContent, $existingContent);
        }

        return $finalContent;
    }

    /**
     * Handle extra properties found in existing file but not in NodeType definition
     *
     * @param array<array{property: string, type: string, line: string}> $extraProperties
     * @return string The final content with extra properties preserved or removed
     */
    protected function handleExtraProperties(
        string $nodeTypeName,
        string $interfaceName,
        string $content,
        array $extraProperties,
        bool $forceOverwrite
    ): string {
        $this->outputLine('');
        $this->outputLine(sprintf('  <comment>Extra properties in %s:</comment>', $interfaceName));

        foreach ($extraProperties as $extra) {
            $this->outputLine(sprintf(
                '    Property "%s": type = "%s" (not in NodeType)',
                $extra['property'],
                $extra['type']
            ));
        }

        if ($forceOverwrite) {
            $this->outputLine('    -> Force removing all unconfigured extra properties');
            return $content;
        }

        $existingFilePath = $this->schemaService->getTargetPath() . $interfaceName . '.ts';
        $existingContent = file_get_contents($existingFilePath);
        if ($existingContent === false) {
            return $content;
        }
        $keptExtras = false;

        foreach ($extraProperties as $extra) {
            $property = $extra['property'];
            $type = $extra['type'];
            $line = $extra['line'];

            $this->outputLine('');
            $this->outputLine(sprintf('    Extra property "%s": %s', $property, $type));

            $choice = $this->output->select(
                sprintf('    Choose action for "%s"', $property),
                ['Remove', 'Keep', 'Add to config (will not ask again)'],
                1
            );

            if ($choice === 'Keep' || $choice === 'Add to config (will not ask again)') {
                // Insert the property line before the closing }
                $content = preg_replace('/^(\})$/m', $line . "\n}", $content, 1) ?? $content;
                $keptExtras = true;

                if ($choice === 'Add to config (will not ask again)') {
                    $cleanType = ltrim($type, '?');
                    $isArray = str_ends_with($cleanType, '[]');
                    $cleanBaseType = rtrim($cleanType, '[]');

                    $primitives = ['string', 'boolean', 'number', 'any'];
                    $configType = $isArray ? $cleanBaseType . '[]' : $cleanBaseType;

                    if (in_array($cleanBaseType, $primitives, true)) {
                        $this->schemaService->addExtraPropertyToConfig($nodeTypeName, $property, $configType);
                    } else {
                        $importPath = $this->extractImportPathForType($existingContent, $cleanBaseType);
                        if ($importPath === null && file_exists($this->schemaService->getTargetPath() . $cleanBaseType . '.ts')) {
                            $importPath = './' . $cleanBaseType;
                        }
                        $this->schemaService->addExtraPropertyToConfig(
                            $nodeTypeName,
                            $property,
                            $configType,
                            $importPath ?? ''
                        );
                    }
                    $this->outputLine(sprintf('    -> Added to settings file'));
                }

                $this->outputLine(sprintf('    -> Keeping "%s"', $property));
            } else {
                $this->outputLine(sprintf('    -> Removing "%s"', $property));
            }
        }

        // If we kept extras, merge any needed imports from existing file
        if ($keptExtras) {
            $content = $this->mergeImports($content, $existingContent);
        }

        return $content;
    }

    /**
     * Replace a property's type in the generated content with the existing type
     */
    protected function replacePropertyType(string $content, string $property, string $existingType, string $existingContent): string
    {
        // Find the property line in the existing content to get the full line
        if (preg_match('/^(\s+' . preg_quote($property, '/') . ')(\??): .+;(.*)/m', $existingContent, $existingMatch) === 1) {
            $existingLine = $existingMatch[0];
            // Replace the property line in the generated content
            $content = preg_replace(
                '/^\s+' . preg_quote($property, '/') . '\??: .+;.*/m',
                $existingLine,
                $content
            ) ?? $content;
        }

        return $content;
    }

    /**
     * Merge imports from existing content into the final content.
     * Handles both single-line and multi-line import statements.
     */
    protected function mergeImports(string $finalContent, string $existingContent): string
    {
        // Extract all import statements (single-line and multi-line)
        $importPattern = '/^import\s.+?[\'"][^\'"]+[\'"];?\s*$/ms';

        preg_match_all($importPattern, $existingContent, $existingImports);
        preg_match_all($importPattern, $finalContent, $finalImports);

        $existingImportStatements = $existingImports[0];
        $finalImportStatements = $finalImports[0];

        // Extract type names already imported in the final content
        $finalImportedTypes = $this->extractImportedTypeNames(implode("\n", $finalImportStatements));

        // Find imports in existing whose types are not yet covered by final imports
        $missingImports = [];
        foreach ($existingImportStatements as $existingImport) {
            $existingTypes = $this->extractImportedTypeNames($existingImport);
            // Skip if all types from this import are already imported in final
            $uncoveredTypes = array_diff($existingTypes, $finalImportedTypes);
            if ($uncoveredTypes !== []) {
                $missingImports[] = trim($existingImport);
            }
        }

        if ($missingImports === []) {
            return $finalContent;
        }

        // Add missing imports after the last import in final content
        $lastImportEnd = 0;
        foreach ($finalImportStatements as $importStatement) {
            $pos = strpos($finalContent, $importStatement);
            if ($pos !== false) {
                $lastImportEnd = max($lastImportEnd, $pos + strlen($importStatement));
            }
        }

        if ($lastImportEnd > 0) {
            $missingImportStr = "\n" . implode("\n", $missingImports);
            $finalContent = substr($finalContent, 0, $lastImportEnd) . $missingImportStr . substr($finalContent, $lastImportEnd);
        } else {
            $missingImportStr = implode("\n", $missingImports) . "\n\n";
            $finalContent = $missingImportStr . $finalContent;
        }

        return $finalContent;
    }

    /**
     * Extract type names from import statements
     * e.g., "import { Foo, Bar } from './baz'" => ['Foo', 'Bar']
     * e.g., "import type {\n  Foo,\n  Bar\n} from './baz'" => ['Foo', 'Bar']
     *
     * @return list<string>
     */
    protected function extractImportedTypeNames(string $importContent): array
    {
        $types = [];
        // Match named imports: import [type] { Name1, Name2 } from '...'
        if (preg_match_all('/import\s+(?:type\s+)?\{([^}]+)\}/s', $importContent, $matches) > 0) {
            foreach ($matches[1] as $typeList) {
                $names = preg_split('/\s*,\s*/', trim($typeList));
                if ($names === false) {
                    $names = [];
                }
                foreach ($names as $name) {
                    $name = trim($name);
                    if ($name !== '') {
                        $types[] = $name;
                    }
                }
            }
        }
        return $types;
    }

    /**
     * Extract the import path for a given type name from file content
     */
    protected function extractImportPathForType(string $content, string $typeName): ?string
    {
        // Match: import { TypeName } from 'path' or import { TypeName } from "path"
        if (preg_match('/import\s+\{[^}]*\b' . preg_quote($typeName, '/') . '\b[^}]*\}\s+from\s+[\'"]([^\'"]+)[\'"]/', $content, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * Generate Zebra server components and update nodeTypes.ts configuration
     *
     * Creates React server components for NodeTypes that don't have components yet,
     * and updates the nodeTypes.ts configuration to map all NodeTypes to their components.
     * Only processes NodeTypes from packages in the DistributionPackages folder.
     *
     * @param string|null $package Optional: Filter by specific package (e.g., "Abl.Site").
     *                             If omitted, processes all DistributionPackages.
     * @param string|null $type Optional: Filter by type - "content" or "document".
     *                          If omitted, processes content types only (document not yet implemented).
     * @param bool $force Force regeneration of existing components (default: false).
     */
    public function generateZebraComponentsWithConfigCommand(
        ?string $package = null,
        ?string $type = null,
        bool $force = false
    ): void {
        $this->outputLine('');
        $this->outputLine('<info>Zebra Server Component Generator</info>');
        $this->outputLine('==================================');
        $this->outputLine('');

        $missingSettings = $this->schemaService->validateZebraSettings();
        if ($missingSettings !== []) {
            $this->outputLine('<error>Missing required settings:</error>');
            foreach ($missingSettings as $setting) {
                $this->outputLine(sprintf('  - Visol.Neos.ZebraSchemaGenerator.%s', $setting));
            }
            return;
        }

        // Validate type parameter
        if ($type !== null && !in_array($type, ['content', 'document'], true)) {
            $this->outputLine('<error>Invalid type. Use "content" or "document".</error>');
            return;
        }

        // Get distribution packages
        $distributionPackages = $this->schemaService->getDistributionPackages();
        if ($distributionPackages === []) {
            $this->outputLine('<error>No DistributionPackages found.</error>');
            return;
        }

        // Ensure directories exist
        $componentTargetPath = $this->schemaService->getComponentTargetPath();
        $this->schemaService->ensureDirectoryExists($componentTargetPath . 'content/');
        $this->schemaService->ensureDirectoryExists($componentTargetPath . 'document/');

        // Parse existing nodeTypes.ts to get all categorized entries
        $existingEntries = $this->schemaService->parseExistingNodeTypesConfig();

        // Determine which type we're processing (for preserving other types)
        // When $type is null, we process both types (no preservation of content/document needed)
        // When $type is 'content', preserve document entries
        // When $type is 'document', preserve content entries
        $processedType = $type; // null means "process both types"

        $nodeTypes = $this->nodeTypeManager->getNodeTypes(false);
        $nodeTypeComponentMap = [];
        $generated = 0;
        $migrated = 0;
        $skipped = 0;
        $existing = 0;
        $excludedNodeTypes = $this->schemaService->getExcludedNodeTypes();
        $relativeComponentImportPrefix = $this->schemaService->getRelativeComponentImportPrefix();

        foreach ($nodeTypes as $nodeType) {
            $nodeTypeName = $nodeType->getName();

            // Skip excluded types
            if (in_array($nodeTypeName, $excludedNodeTypes, true)) {
                continue;
            }

            // Skip abstract types matching configured name patterns (Mixins, Constraints)
            if ($this->schemaService->isExcludedByNamePattern($nodeTypeName)) {
                continue;
            }

            // Only process DistributionPackages
            if (!$this->schemaService->isDistributionPackageNodeType($nodeTypeName, $distributionPackages)) {
                continue;
            }

            // Filter by package if specified
            if ($package !== null && !str_starts_with($nodeTypeName, $package . ':')) {
                continue;
            }

            // Determine category
            $category = $this->schemaService->getNodeTypeCategory($nodeTypeName);
            if ($category === null) {
                continue;
            }

            // Filter by type if specified
            if ($type !== null && $category !== $type) {
                continue;
            }

            // Check if interface exists
            if (!$this->schemaService->interfaceExists($nodeTypeName)) {
                $packageName = explode(':', $nodeTypeName)[0];
                $this->outputLine(sprintf(
                    '<error>  ⚠️  Skipping %s - interface not found.</error>',
                    $nodeTypeName
                ));
                $this->outputLine(sprintf(
                    '    <error>Generate it first: ddev app-neos-next-flow visol.neos.zebraschemagenerator:schema:generateinterfaces --package=%s</error>',
                    $packageName
                ));
                $this->outputLine('    <error><options=bold>⚠️  WARNING: The mapping to Zebra will be removed!</></error>');
                $skipped++;
                continue;
            }

            $componentName = $this->schemaService->getComponentName($nodeTypeName);
            $fileName = $this->schemaService->getFileName($nodeTypeName);
            $expectedImportPath = $relativeComponentImportPrefix . "{$category}/{$fileName}";
            $newComponentPath = $componentTargetPath . $category . '/' . $fileName . '.tsx';

            // Check if NodeType already has mapping in nodeTypes.ts (source of truth)
            $existingMapping = $existingEntries[$category][$nodeTypeName] ?? null;

            if ($existingMapping !== null) {
                $existingImportPath = $existingMapping['importPath'];

                // Check if existing mapping uses correct file naming
                if ($existingImportPath !== $expectedImportPath) {
                    // Wrong naming - need to migrate the component file
                    $oldComponentPath = $this->schemaService->resolveImportPath($existingImportPath);

                    if ($oldComponentPath !== null && file_exists($oldComponentPath)) {
                        $this->schemaService->migrateComponent($oldComponentPath, $newComponentPath);
                        $oldFileName = basename($oldComponentPath);
                        $this->outputLine(sprintf('  Migrated: %s -> %s.tsx', $oldFileName, $fileName));
                        $migrated++;
                    } else {
                        // Old file doesn't exist, generate new one
                        $this->schemaService->generateServerComponent($nodeTypeName, $category);
                        $this->outputLine(sprintf('  Generated: %s -> %s', $nodeTypeName, $componentName));
                        $generated++;
                    }
                } elseif ($force) {
                    // Correct naming but force flag - regenerate
                    $this->schemaService->generateServerComponent($nodeTypeName, $category);
                    $this->outputLine(sprintf('  Regenerated: %s -> %s', $nodeTypeName, $componentName));
                    $generated++;
                } else {
                    // Correct naming, no force - skip
                    $this->outputLine(sprintf('  Existing: %s -> %s', $nodeTypeName, $componentName));
                    $existing++;
                }
            } else {
                // No existing mapping - generate new component
                $this->schemaService->generateServerComponent($nodeTypeName, $category);
                $this->outputLine(sprintf('  Generated: %s -> %s', $nodeTypeName, $componentName));
                $generated++;
            }

            // Add to map for nodeTypes.ts
            $nodeTypeComponentMap[$nodeTypeName] = [
                'category' => $category,
                'componentName' => $componentName,
                'fileName' => $fileName,
            ];
        }

        // Update nodeTypes.ts (preserving other categories)
        // Determine which category to preserve based on what we're processing
        // If $processedType is null (both), don't preserve any auto-generated categories
        $hasPreservedEntries = false;
        if ($processedType !== null) {
            $preservedCategory = $processedType === 'content' ? 'document' : 'content';
            $hasPreservedEntries = $existingEntries[$preservedCategory] !== [];
        }
        $hasPreservedEntries = $hasPreservedEntries || $existingEntries['manual'] !== [];

        if ($nodeTypeComponentMap !== [] || $hasPreservedEntries) {
            $this->schemaService->updateNodeTypesConfig($nodeTypeComponentMap, $existingEntries, $processedType);
            $this->outputLine('');
            $this->outputLine('  Updated: nodeTypes.ts');
        }

        // Summary
        $this->outputLine('');
        $this->outputLine(sprintf(
            '<success>Done: %d generated, %d migrated, %d existing, %d skipped</success>',
            $generated,
            $migrated,
            $existing,
            $skipped
        ));
        $this->outputLine(sprintf('<success>The serverComponents can be found in %s</success>', $this->schemaService->getComponentTargetPath()));
        $this->outputLine('');
    }
}
