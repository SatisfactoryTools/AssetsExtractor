# world-extractor-net

Extracts `default-world.json` — the default world's resource node actors (nodes, geysers,
fracking cores/satellites with type, purity and location) — from a Satisfactory install,
in the format consumed by
[`world-data-generator`](https://github.com/SatisfactoryTools/world-data-generator)
(and upstream [Konsl/satisfactory-world-generator](https://github.com/Konsl/satisfactory-world-generator)).

Adapted from upstream's MIT-licensed `scripts/extract.cs` + `scripts/convert.js`, restructured
after `extractor-net/` (same CUE4Parse version and provider setup, CLI args instead of
hardcoded paths, no texture extraction).

## Status: UNTESTED

Written 2026-07-10 but never run — the current `default-world.json` used by
`world-data-generator` was obtained externally, so this tool hasn't been exercised yet.
Before relying on it:

- run it once against the current install and diff the output against the known-good
  `default-world.json` (field order aside, they should match; the known-good file for game
  version `++FactoryGame+rel-main-1.2.0-CL-491125` has 459 resource nodes, 31 geysers,
  17 fracking cores);
- open questions in that first run: whether the level package needs the oodle library
  (`TryInitOodle` downloads it via CUE4Parse's `OodleHelper`, API untested) and whether the
  shipped `.usmap` parses with CUE4Parse 1.2.2.202607 (actor properties are unversioned, so
  mappings are likely required — `extractor-net` only decodes textures and tolerates a missing
  usmap, this tool probably cannot).

It is also not wired into the orchestrator pipeline; regeneration on game update is manual
for now.

## Usage

```
dotnet run -- --install-dir ../data/steam/stable --out default-world.json
              [--engine-candidates GAME_UE5_6,GAME_UE5_5] [--game-version 1.2.3.1]
```

The game version is read from `Engine/Binaries/Win64/*-Win64-Shipping.version` (same source
as the orchestrator's `GameVersion.php`); `--game-version` overrides it.
