# world-extractor-net

Reads the persistent level of a Satisfactory install and writes:

- **`--out`** `default-world.json`: the default world's resource node actors (nodes, geysers,
  fracking cores/satellites with type, purity and location), in the format consumed by
  [`world-data-generator`](https://github.com/SatisfactoryTools/world-data-generator)
  (and upstream [Konsl/satisfactory-world-generator](https://github.com/Konsl/satisfactory-world-generator)).
  Adapted from upstream's MIT-licensed `scripts/extract.cs` + `scripts/convert.js`.
- **`--collectibles-out`** `collectibles.json`: every item pickup (power slugs, somersloops,
  mercer spheres, berries, nuts, mushrooms, loose items), crash site and creature spawner,
  read from the level's `BP_WorldScannableDataGenerator` (the list behind the in-game object
  scanner). Each entry has `name` (the actor name saves use, e.g. `BP_Crystal_mk29`),
  `class`, `location`, for spawners `creature`, and for loose item pickups `item` + `amount`.
  Pickups' contents come from the world-partition streaming cells (~30 s): the item struct
  itself cannot be decoded without mappings, so the pickup's mesh is matched to the item
  descriptor whose `mConveyorMesh` it is (699 of 703 identified in 1.2.4.1).
- **`--map-out`** a directory with the in-game map (`MapTest/SlicedMap/Map_{column}-{row}`
  slices) stitched and cut into a 256px WebP tile pyramid (`tiles/{z}/{x}/{y}.webp`), plus
  `map.json`: a content `id` (hash of the pixels + bounds), zoom range and the world rectangle
  the image covers, read from the level's `BP_MinimapCaptureActor` (centre + `OrthoWidth`).
  Image x grows with world X, image y with world Y. `icons/` holds 64px icons the item data
  lacks, listed in `map.json` `icons`: `hardDrive`, `hostile`, `friendly`, `item` and one per
  creature class (`Char_Hog_C`, ...) from the creature descriptors.

Structured after `extractor-net/` (same CUE4Parse version and provider setup).

## Usage

```
dotnet run -- --install-dir ../data/steam/stable --out default-world.json
              [--collectibles-out collectibles.json] [--map-out map/]
              [--engine-candidates GAME_UE5_6,GAME_UE5_5] [--game-version 1.2.3.1]
```

The game version is read from `Engine/Binaries/Win64/*-Win64-Shipping.version` (same source
as the orchestrator's `GameVersion.php`); `--game-version` overrides it.

The orchestrator runs it on every new build (`extraction.world_dll`) and publishes the result
content-addressed into the API data dir; `bin/extractor world` re-runs it for an existing install.

## Notes

- Verified 2026-10-26 against 1.2.4.1 (CL 513885, UE 5.6): the nodes match the known-good
  `default-world.json` exactly (459 nodes, 31 geysers, 17 cores / 118 satellites); 7653 pickups,
  118 drop pods, 2277 creature spawners; 8192px map, zoom 0-5, ~10 MB of tiles. Runs in ~4 s.
- Works without oodle (the downloaded library needs glibc 2.38) and without the shipped `.usmap`
  (this CUE4Parse build cannot parse it); the level's actor properties are tagged, so neither is
  needed today. A future engine bump may change that.
- Peak memory is ~1.3 GB (the stitched 8192² map plus its halved levels).
