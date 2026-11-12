# mod-extractor-net

Dumps blueprint classes of Satisfactory mods (or of base-game packages) for the orchestrator's
`mod:import` and `mod:selftest`: each class with its parent class, whether it is abstract, and the
values its class default object sets, written the way the game's `Docs` file writes them
(instanced subobjects such as schematic unlocks become lists of objects). Data tables in the mods
(and base-game tables picked with `--base-tables`) are dumped as rows.

## Usage

```
dotnet run -- --install-dir <game install> --out classes.json
              [--mods-file mods.json]            # [{"reference": "Easy_Recipes", "paksDir": ".../Content/Paks"}]
              [--packages-file packages.json]    # ["/Game/FactoryGame/Recipes/Constructor/Recipe_IronPlate"]
              [--class-names-file names.json]    # ["Desc_IronPlate_C"], found by package name
              [--base-tables <regex>]            # e.g. ^DT_ResourceSinkPoints
              [--engine-candidates GAME_UE5_6,GAME_UE5_5,...] [--aes-key 0x...]
```

## Notes

- Mod paks are mounted next to the game's, so IoStore mods resolve their references to base-game
  classes. Mods cooked for an older engine (legacy `.pak` only) are read with their own provider and
  the first engine version that parses them; their references are stored by name.
- Parents that live in a mod's C++ code cannot be resolved (the IoStore script import is only a
  hash); such classes come out with `"super": null, "superResolved": false`.
- String-table texts are read from the `en-US` `.locres` files. No `.usmap` is needed.
