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

### Mixin interfaces

By default only non-abstract node types get an interface. When integration code reads
mixin properties shared across many node types (e.g. a `spaceBelow` or `containerWidth`
mixin used by wrapper components), configure the mixin name patterns to generate
interfaces for them as well:

```yaml
Visol:
  Neos:
    ZebraSchemaGenerator:
      mixinNodeTypeNamePatterns:
        - ':Mixin.'
```

Matching node types (abstract ones included) get an interface named like any other type
(`Vendor.Site:Mixin.Section` → `VendorSite_MixinSection`) in which **all properties are
optional**, because a mixin interface describes a partial shape and consumers must handle
nodes created before a property existed. Mixin patterns win over
`excludedNodeTypeNamePatterns`, so the default `Mixin` exclusion can stay in place. No
Zebra components are generated for mixins.

## License

MIT
