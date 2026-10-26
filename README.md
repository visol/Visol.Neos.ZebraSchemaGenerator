# Visol.Neos.ZebraSchemaGenerator

Generate TypeScript interfaces and [Zebra](https://github.com/networkteam/zebra) server
components from [Neos CMS](https://www.neos.io/) node type definitions.

This is an **experimental**, configurable extraction of a generator originally built for a
specific project. Everything project-specific (taxonomy mapping, preset→type mappings, base
`_types`, excluded node types, category markers, target paths, …) is driven by YAML config, so
the package ships generic defaults and the consuming site package overrides only its specifics.

## Installation

```bash
composer require visol/neos-zebra-schema-generator
```

## Usage

```bash
# Generate TypeScript interfaces from node type definitions
./flow visol.neos.zebraschemagenerator:schema:generateinterfaces [--package=Your.Site]

# Generate Zebra server components (+ node type config)
./flow visol.neos.zebraschemagenerator:schema:generatezebracomponentswithconfig [--package=Your.Site]
```

`--package` filters generation to a single package; omit it to process all distribution packages.

## Configuration

All behaviour is configured under the `Visol.Neos.ZebraSchemaGenerator` settings namespace. The
package's own `Configuration/Settings.yaml` documents the generic defaults; override the
project-specific keys (`interfacesTargetPath`, `componentTargetPath`, `excludedNodeTypes`,
`referenceTypesAsString`, `customPropertyTypes`, `extraProperties`, …) in your site package.

### Code style

The generated files follow no particular code style. Set `formatCommand` to run the project's
formatter over every written file, e.g. Biome or Prettier:

```yaml
Visol:
  Neos:
    ZebraSchemaGenerator:
      formatCommand: 'cd ../frontend && npx biome check --write {files}'
```

A rerun still compares the generated properties with the formatted files, so divergences and
extra properties are detected the same way.

## License

MIT
