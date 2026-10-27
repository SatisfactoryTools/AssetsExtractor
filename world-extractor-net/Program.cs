using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;
using CUE4Parse.Compression;
using CUE4Parse.Encryption.Aes;
using CUE4Parse.FileProvider;
using CUE4Parse.MappingsProvider.Usmap;
using CUE4Parse.UE4.Assets.Exports;
using CUE4Parse.UE4.Assets.Exports.Texture;
using CUE4Parse.UE4.Assets.Objects;
using CUE4Parse.UE4.Objects.Core.Math;
using CUE4Parse.UE4.Objects.Core.Misc;
using CUE4Parse.UE4.Objects.Engine;
using CUE4Parse.UE4.Objects.UObject;
using CUE4Parse.UE4.Versions;
using CUE4Parse_Conversion.Textures;
using SkiaSharp;

// World extractor for Satisfactory Tools.
//
// Reads the persistent level of a Satisfactory install and writes:
//   --out               default-world.json: resource nodes, geysers and fracking cores/satellites
//                       (type, purity, location), in the format consumed by world-data-generator /
//                       Konsl/satisfactory-world-generator (adapted from that repo's MIT-licensed
//                       scripts/extract.cs + scripts/convert.js);
//   --collectibles-out  collectibles.json: every item pickup (power slugs, somersloops, mercer spheres,
//                       berries, ...), crash site and creature spawner the game's object scanner knows,
//                       read from the level's BP_WorldScannableDataGenerator;
//   --map-out           a directory with the in-game map as a 256px WebP tile pyramid
//                       (tiles/{z}/{x}/{y}.webp) plus map.json (content id, zoom range and the world
//                       rectangle the image covers, read from the level's minimap capture actor).
//
// Usage:
//   WorldExtractor --install-dir <game install> --out <default-world.json>
//                  [--collectibles-out <collectibles.json>] [--map-out <dir>]
//                  [--engine-candidates GAME_UE5_6,GAME_UE5_5] [--game-version 1.2.3.1]

var opts = Args.Parse(args);
string installDir = opts.Require("install-dir");
string outPath = opts.Require("out");
string? collectiblesPath = opts.Get("collectibles-out");
string? mapDir = opts.Get("map-out");

string paksDir = Path.Combine(installDir, "FactoryGame", "Content", "Paks");
string usmapPath = Path.Combine(installDir, "CommunityResources", "FactoryGame.usmap");

// --- game + engine version from the loose .version file in the install ---
var (versionFileGameVersion, versionFileEngine) = ReadVersionFile(installDir);
string? gameVersion = opts.Get("game-version") ?? versionFileGameVersion;
if (gameVersion is null) throw new Exception("Could not read game version from install; pass --game-version.");

string engineList = opts.Get("engine-candidates")
    ?? (versionFileEngine is not null ? $"{versionFileEngine}," : "") + "GAME_UE5_6,GAME_UE5_5,GAME_UE5_4";
EGame[] candidates = engineList.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
    .Select(s => Enum.TryParse<EGame>(s, out var g) ? (EGame?)g : null)
    .Where(g => g.HasValue).Select(g => g!.Value).Distinct().ToArray();

TryInitOodle();

const string levelPath = "FactoryGame/Content/FactoryGame/Map/GameLevel01/Persistent_Level.umap.PersistentLevel";

var (provider, level, engine) = OpenLevel(paksDir, usmapPath, candidates);
Console.WriteLine($"Engine: {engine} | mounted files: {provider.Files.Count} | game version: {gameVersion}");

var resourceNodes = new List<ResourceNode>();
var geysers = new List<GeyserNode>();
var cores = new List<FrackingCore>();
var coresByName = new Dictionary<string, FrackingCore>();
var satellites = new List<(string Core, string Resource, GeyserNode Satellite)>();
UObject? scannableGenerator = null;
UObject? minimapCapture = null;
int skipped = 0;

foreach (var actorIndex in level.Actors)
{
    UObject? actor;
    try { actor = actorIndex.Load(); }
    catch { skipped++; continue; }
    if (actor is null) continue;

    switch (actor.ExportType)
    {
        case "BP_ResourceNode_C":
            resourceNodes.Add(new ResourceNode(
                actor.Name, Location(actor), ResourceClass(actor), Purity(actor)));
            break;
        case "BP_ResourceNodeGeyser_C":
            geysers.Add(new GeyserNode(actor.Name, Location(actor), Purity(actor)));
            break;
        case "BP_FrackingCore_C":
        {
            var core = new FrackingCore(actor.Name, Location(actor));
            cores.Add(core);
            coresByName[core.Name] = core;
            break;
        }
        case "BP_FrackingSatellite_C":
            satellites.Add((actor.Get<FPackageIndex>("mCore").Name, ResourceClass(actor),
                new GeyserNode(actor.Name, Location(actor), Purity(actor))));
            break;
        case "BP_WorldScannableDataGenerator_C":
            scannableGenerator = actor;
            break;
        case "BP_MinimapCaptureActor_C":
            minimapCapture = actor;
            break;
    }
}

// Satellites may precede their core in the actor list, so they are attached after the scan.
foreach (var (coreName, resource, satellite) in satellites)
{
    if (!coresByName.TryGetValue(coreName, out var core))
        throw new Exception($"Satellite {satellite.Name} references unknown core {coreName}");
    if (core.Resource is not null && core.Resource != resource)
        throw new Exception($"Core {coreName} has satellites with different resources ({core.Resource} vs {resource})");
    core.Resource = resource;
    core.Satellites.Add(satellite);
}

if (skipped > 0) Console.Error.WriteLine($"WARN: {skipped} level actors failed to load and were skipped.");
if (resourceNodes.Count == 0) throw new Exception("No resource nodes found — extraction failed.");
if (cores.Any(c => c.Resource is null)) throw new Exception("Fracking core without satellites/resource found.");

var world = new World(gameVersion, resourceNodes, geysers, cores);
File.WriteAllText(outPath, JsonSerializer.Serialize(world, Json.Options));
Console.WriteLine(
    $"World: {resourceNodes.Count} resource nodes, {geysers.Count} geysers, " +
    $"{cores.Count} fracking cores ({cores.Sum(c => c.Satellites.Count)} satellites) -> {outPath}");

if (collectiblesPath is not null)
{
    if (scannableGenerator is null) throw new Exception("BP_WorldScannableDataGenerator_C not found in the level.");
    var contents = PickupContents.Read(provider);
    var collectibles = new Collectibles(
        gameVersion,
        ScannableEntries(scannableGenerator, "mItemPickups", contents),
        ScannableEntries(scannableGenerator, "mDropPods", contents),
        ScannableEntries(scannableGenerator, "mCreatureSpawners", contents));
    if (collectibles.Pickups.Count == 0 || collectibles.DropPods.Count == 0)
        throw new Exception("Scannable data has no pickups or drop pods — the level format probably changed.");
    File.WriteAllText(collectiblesPath, JsonSerializer.Serialize(collectibles, Json.Options));
    Console.WriteLine(
        $"Collectibles: {collectibles.Pickups.Count} pickups ({collectibles.Pickups.Count(p => p.Item is not null)} with a known item), " +
        $"{collectibles.DropPods.Count} drop pods, {collectibles.CreatureSpawners.Count} creature spawners -> {collectiblesPath}");
}

if (mapDir is not null)
{
    if (minimapCapture is null) throw new Exception("BP_MinimapCaptureActor_C not found in the level.");
    var map = MapTiler.Write(provider, minimapCapture, gameVersion, mapDir, MapIcons.Collect(provider));
    Console.WriteLine($"Map: {map.Id} ({map.Size}px, zoom {map.MinZoom}-{map.MaxZoom}, {map.Icons.Count} icons) -> {mapDir}");
}

return 0;

// ---- helpers ----

static float[] Location(UObject actor)
{
    var loc = actor.Get<FPackageIndex>("mBoxComponent").Load()!.Get<FVector>("RelativeLocation");
    return [loc.X, loc.Y, loc.Z];
}

static string ResourceClass(UObject actor) => actor.Get<FPackageIndex>("mResourceClass").Name;

static string Purity(UObject actor) => actor.GetOrDefault<FName>("mPurity", new FName("RP_Normal")).ToString();

static List<ScannableEntry> ScannableEntries(UObject generator, string property, Dictionary<string, PickupContent> contents)
{
    var entries = new List<ScannableEntry>();
    foreach (var entry in generator.GetOrDefault<FStructFallback[]>(property, []))
    {
        // Saves refer to these actors by the last segment of their path ("PersistentLevel.BP_Crystal3_3").
        string name = entry.Get<FSoftObjectPath>("Actor").SubPathString.Split('.').Last();
        var location = entry.Get<FVector>("ActorLocation");
        // A soft class path ("/Game/.../Char_Hog.Char_Hog_C"); the class name is the part after the last dot.
        string? creature = entry.TryGetValue(out FSoftObjectPath creatureClass, "CreatureClass")
                           && creatureClass.AssetPathName.Text is { Length: > 0 } creaturePath
            ? creaturePath.Split('.').Last()
            : null;
        var content = contents.GetValueOrDefault(name);
        entries.Add(new ScannableEntry(
            name,
            entry.Get<FPackageIndex>("ActorClass").Name,
            creature,
            content?.Item,
            content?.Amount,
            [MathF.Round(location.X, 1), MathF.Round(location.Y, 1), MathF.Round(location.Z, 1)]));
    }
    return entries;
}

static (string?, string?) ReadVersionFile(string installDir)
{
    var dir = Path.Combine(installDir, "Engine", "Binaries", "Win64");
    if (!Directory.Exists(dir)) return (null, null);
    foreach (var file in Directory.EnumerateFiles(dir, "*-Win64-Shipping.version")
                 .OrderByDescending(f => f.Contains("FactoryGame")))
    {
        try
        {
            var doc = JsonDocument.Parse(File.ReadAllText(file));
            string? version = doc.RootElement.TryGetProperty("GameVersion", out var v) ? v.GetString() : null;
            string? engine = doc.RootElement.TryGetProperty("MajorVersion", out var maj)
                             && doc.RootElement.TryGetProperty("MinorVersion", out var min)
                ? $"GAME_UE{maj.GetInt32()}_{min.GetInt32()}"
                : null;
            if (version is not null) return (version, engine);
        }
        catch { /* try next candidate */ }
    }
    return (null, null);
}

static void TryInitOodle()
{
    string lib = Path.Combine(AppContext.BaseDirectory, OodleHelper.OodleFileName);
    try
    {
        if (!File.Exists(lib)) OodleHelper.DownloadOodleDll(ref lib);
        if (File.Exists(lib)) OodleHelper.Initialize(lib);
    }
    catch (Exception e)
    {
        Console.Error.WriteLine($"WARN: oodle unavailable ({e.Message}) — continuing without it.");
    }
}

static (IFileProvider, ULevel, EGame) OpenLevel(string paksDir, string usmapPath, EGame[] candidates)
{
    FileUsmapTypeMappingsProvider? mappings = null;
    if (File.Exists(usmapPath))
    {
        try { mappings = new FileUsmapTypeMappingsProvider(usmapPath); }
        catch (Exception e)
        {
            Console.Error.WriteLine($"WARN: usmap unparseable ({e.GetType().Name}) — continuing without mappings.");
        }
    }

    Exception? last = null;
    foreach (var game in candidates)
    {
        try
        {
            var provider = new DefaultFileProvider(
                paksDir, SearchOption.AllDirectories, new VersionContainer(game),
                StringComparer.OrdinalIgnoreCase);
            if (mappings != null) provider.MappingsContainer = mappings;
            provider.Initialize();
            provider.SubmitKey(new FGuid(), new FAesKey(new byte[32]));
            var level = provider.LoadPackageObject<ULevel>(levelPath);
            if (level.Actors.Length == 0) throw new Exception("level has no actors");
            return (provider, level, game);
        }
        catch (Exception e)
        {
            last = e;
        }
    }

    throw new Exception($"No candidate engine version could load the level. Last error: {last}");
}

// ---- map tiles ----

static class MapTiler
{
    private const int TileSize = 256;
    private const int WebpQuality = 80;
    private const string SlicesDir = "FactoryGame/Content/FactoryGame/Interface/UI/Assets/MapTest/SlicedMap/";
    private static readonly Regex SliceName = new(@"/Map_(\d+)-(\d+)\.uasset$", RegexOptions.IgnoreCase);

    private const int IconSize = 64;

    public static MapInfo Write(IFileProvider provider, UObject captureActor, string gameVersion, string outDir, Dictionary<string, UTexture2D> iconTextures)
    {
        var bounds = CaptureBounds(captureActor);
        using var full = StitchSlices(provider);
        if (full.Width != full.Height || full.Width % TileSize != 0 || !IsPowerOfTwo(full.Width / TileSize))
            throw new Exception($"Unexpected map size {full.Width}x{full.Height}.");

        int maxZoom = (int)Math.Log2(full.Width / TileSize);
        var icons = EncodeIcons(iconTextures);
        string id = ContentId(full, bounds, icons);

        string iconsDir = Path.Combine(outDir, "icons");
        if (Directory.Exists(iconsDir)) Directory.Delete(iconsDir, true);
        Directory.CreateDirectory(iconsDir);
        foreach (var (key, png) in icons)
            File.WriteAllBytes(Path.Combine(iconsDir, $"{key}.png"), png);

        string tilesDir = Path.Combine(outDir, "tiles");
        if (Directory.Exists(tilesDir)) Directory.Delete(tilesDir, true);

        // Each level is halved from the one above, so zooming out never resamples the full image again.
        SKBitmap current = full;
        for (int z = maxZoom; z >= 0; z--)
        {
            if (z < maxZoom)
            {
                var half = new SKBitmap(new SKImageInfo(current.Width / 2, current.Height / 2, SKColorType.Rgba8888, SKAlphaType.Premul));
                current.ScalePixels(half, SKFilterQuality.High);
                if (current != full) current.Dispose();
                current = half;
            }
            WriteLevel(current, z, tilesDir);
        }
        if (current != full) current.Dispose();

        var info = new MapInfo(id, gameVersion, full.Width, TileSize, 0, maxZoom, "webp", bounds,
            icons.Keys.Order().ToDictionary(key => key, key => $"icons/{key}.png"));
        File.WriteAllText(Path.Combine(outDir, "map.json"), JsonSerializer.Serialize(info, Json.Options));
        return info;
    }

    private static void WriteLevel(SKBitmap level, int z, string tilesDir)
    {
        int count = level.Width / TileSize;
        Parallel.For(0, count * count, i =>
        {
            int x = i % count, y = i / count;
            using var tile = new SKBitmap();
            if (!level.ExtractSubset(tile, SKRectI.Create(x * TileSize, y * TileSize, TileSize, TileSize)))
                throw new Exception($"Could not cut tile {z}/{x}/{y}.");
            using var image = SKImage.FromBitmap(tile);
            using var data = image.Encode(SKEncodedImageFormat.Webp, WebpQuality)
                ?? throw new Exception("WebP encoding is not available in this SkiaSharp build.");
            string dir = Path.Combine(tilesDir, z.ToString(), x.ToString());
            Directory.CreateDirectory(dir);
            File.WriteAllBytes(Path.Combine(dir, $"{y}.webp"), data.ToArray());
        });
    }

    /** The map ships as a grid of slices named Map_{column}-{row}. */
    private static SKBitmap StitchSlices(IFileProvider provider)
    {
        var slices = provider.Files.Keys
            .Where(k => k.StartsWith(SlicesDir, StringComparison.OrdinalIgnoreCase))
            .Select(k => (Path: k, Match: SliceName.Match(k)))
            .Where(s => s.Match.Success)
            .Select(s => (s.Path, Column: int.Parse(s.Match.Groups[1].Value), Row: int.Parse(s.Match.Groups[2].Value)))
            .ToList();
        if (slices.Count == 0) throw new Exception($"No map slices found under {SlicesDir}.");

        int columns = slices.Max(s => s.Column) + 1, rows = slices.Max(s => s.Row) + 1;
        if (slices.Count != columns * rows) throw new Exception($"Map slice grid {columns}x{rows} is incomplete ({slices.Count} slices).");

        SKBitmap? full = null;
        int sliceSize = 0;
        foreach (var slice in slices)
        {
            string objectPath = Path.ChangeExtension(slice.Path, null) + "." + Path.GetFileNameWithoutExtension(slice.Path);
            var texture = provider.LoadPackageObject<UTexture2D>(objectPath);
            using var bitmap = (texture.Decode() ?? throw new Exception($"Could not decode {objectPath}.")).ToSkBitmap();
            if (bitmap.Width != bitmap.Height) throw new Exception($"Map slice {objectPath} is not square.");
            if (full is null)
            {
                sliceSize = bitmap.Width;
                full = new SKBitmap(new SKImageInfo(columns * sliceSize, rows * sliceSize, SKColorType.Rgba8888, SKAlphaType.Premul));
            }
            else if (bitmap.Width != sliceSize)
            {
                throw new Exception("Map slices differ in size.");
            }
            using var canvas = new SKCanvas(full);
            canvas.DrawBitmap(bitmap, slice.Column * sliceSize, slice.Row * sliceSize);
        }
        return full!;
    }

    /** A top-down orthographic capture: the image is OrthoWidth world units wide and as tall, centred on the capture. */
    private static MapBounds CaptureBounds(UObject captureActor)
    {
        var root = captureActor.Get<FPackageIndex>("RootComponent").Load()!;
        var capture = captureActor.Get<FPackageIndex>("CaptureComponent2D").Load()!;
        var center = root.GetOrDefault<FVector>("RelativeLocation") + capture.GetOrDefault<FVector>("RelativeLocation");
        float width = capture.Get<float>("OrthoWidth");
        if (width <= 0) throw new Exception("Minimap capture has no OrthoWidth.");
        float half = width / 2;
        return new MapBounds(center.X - half, center.Y - half, center.X + half, center.Y + half);
    }

    private static SortedDictionary<string, byte[]> EncodeIcons(Dictionary<string, UTexture2D> textures)
    {
        var icons = new SortedDictionary<string, byte[]>(StringComparer.Ordinal);
        foreach (var (key, texture) in textures)
        {
            try
            {
                using var bitmap = (texture.Decode() ?? throw new Exception("decode returned null")).ToSkBitmap();
                using var scaled = new SKBitmap(new SKImageInfo(IconSize, IconSize, SKColorType.Rgba8888, SKAlphaType.Premul));
                bitmap.ScalePixels(scaled, SKFilterQuality.High);
                using var image = SKImage.FromBitmap(scaled);
                using var data = image.Encode(SKEncodedImageFormat.Png, 100);
                icons[key] = data.ToArray();
            }
            catch (Exception e)
            {
                Console.Error.WriteLine($"WARN: map icon {key} ({texture.Name}) failed: {e.Message}");
            }
        }
        return icons;
    }

    private static string ContentId(SKBitmap full, MapBounds bounds, SortedDictionary<string, byte[]> icons)
    {
        using var sha = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
        sha.AppendData(full.GetPixelSpan());
        sha.AppendData(System.Text.Encoding.UTF8.GetBytes(JsonSerializer.Serialize(bounds, Json.Options)));
        foreach (var (key, png) in icons)
        {
            sha.AppendData(System.Text.Encoding.UTF8.GetBytes(key));
            sha.AppendData(png);
        }
        return Convert.ToHexStringLower(sha.GetHashAndReset())[..16];
    }

    private static bool IsPowerOfTwo(int value) => value > 0 && (value & (value - 1)) == 0;
}

// ---- pickup contents ----

record PickupContent(string? Item, int Amount);

/** What each loose item pickup holds, from the world partition cells that contain the pickups. */
static class PickupContents
{
    private const string CellsDir = "FactoryGame/Content/FactoryGame/Map/GameLevel01/Persistent_Level/_Generated_/";
    private static readonly string[] DescriptorRoots =
        ["FactoryGame/Content/FactoryGame/Resource/", "FactoryGame/Content/FactoryGame/Equipment/"];
    private static readonly string[] DescriptorPrefixes = ["Desc_", "BP_EquipmentDescriptor", "BP_ItemDescriptor"];

    public static Dictionary<string, PickupContent> Read(IFileProvider provider)
    {
        var started = DateTime.UtcNow;
        var meshes = new Dictionary<string, (string? Mesh, int Amount)>();
        foreach (var cell in provider.Files.Keys.Where(k => k.StartsWith(CellsDir, StringComparison.OrdinalIgnoreCase) && k.EndsWith(".umap")))
        {
            IEnumerable<UObject> exports;
            try { exports = provider.LoadPackage(cell).GetExports(); }
            catch { continue; }
            foreach (var export in exports)
            {
                if (export.ExportType != "FGItemPickup_Spawnable") continue;
                // The item itself is a natively serialized FInventoryItem that cannot be read without mappings;
                // the pickup's mesh is the item's conveyor mesh, which identifies it just as well.
                string? mesh = null;
                try
                {
                    mesh = export.Get<FPackageIndex>("mMeshComponent").Load()?
                        .GetOrDefault<FPackageIndex>("StaticMesh")?.ResolvedObject?.GetPathName();
                }
                catch { /* unknown item */ }
                int amount = export.GetOrDefault<FStructFallback>("mPickupItems")?.GetOrDefault<int>("NumItems") ?? 0;
                meshes[export.Name] = (mesh, amount);
            }
        }

        var items = DescriptorsByMesh(provider, meshes.Values.Select(m => m.Mesh).OfType<string>().ToHashSet());
        var contents = meshes.ToDictionary(
            entry => entry.Key,
            entry => new PickupContent(entry.Value.Mesh is null ? null : items.GetValueOrDefault(entry.Value.Mesh), entry.Value.Amount));
        Console.WriteLine($"Pickup contents: {contents.Count} pickups, {contents.Values.Count(c => c.Item is not null)} identified ({(DateTime.UtcNow - started).TotalSeconds:F0} s)");
        return contents;
    }

    private static Dictionary<string, string> DescriptorsByMesh(IFileProvider provider, HashSet<string> wanted)
    {
        var result = new Dictionary<string, string>();
        var descriptors = provider.Files.Keys
            .Where(k => DescriptorRoots.Any(root => k.StartsWith(root, StringComparison.OrdinalIgnoreCase))
                        && DescriptorPrefixes.Any(prefix => Path.GetFileName(k).StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                        && k.EndsWith(".uasset", StringComparison.OrdinalIgnoreCase));
        foreach (var file in descriptors)
        {
            if (result.Count == wanted.Count) break;
            try
            {
                var cdo = provider.LoadPackage(file).GetExports().FirstOrDefault(e => e.Name.StartsWith("Default__"));
                string? mesh = cdo?.GetOrDefault<FPackageIndex>("mConveyorMesh")?.ResolvedObject?.GetPathName();
                if (mesh is not null && wanted.Contains(mesh)) result.TryAdd(mesh, cdo!.ExportType);
            }
            catch { /* not an item descriptor */ }
        }
        return result;
    }
}

// ---- map icons ----

/** Icons the map needs that the item data does not have: crash sites, creatures and fallbacks. */
static class MapIcons
{
    private const string Root = "FactoryGame/Content/FactoryGame/";
    private const string CreatureDescriptors = Root + "Character/Creature/CreatureDescriptors/";

    private static readonly Dictionary<string, string> Fixed = new()
    {
        ["hardDrive"] = "Resource/Environment/CrashSites/UI/HardDrive_256",
        ["hostile"] = "Equipment/ObjectScanner/Icons/Monsters_256",
        ["friendly"] = "Interface/UI/InGame/MapMarker/Icons/TXUI_Creature_Map",
        ["item"] = "Interface/UI/InGame/MapMarker/Icons/TXUI_Crate_Map",
    };

    public const string ArachnophobiaSuffix = "-arachnophobia";

    /** Keys: the fixed names above, each creature's class name (e.g. "Char_Hog_C") and, for creatures the game swaps in its arachnophobia mode, the class name + ArachnophobiaSuffix. */
    public static Dictionary<string, UTexture2D> Collect(IFileProvider provider)
    {
        var icons = new Dictionary<string, UTexture2D>();
        foreach (var (key, path) in Fixed)
        {
            string objectPath = Root + path + "." + Path.GetFileName(path);
            try { icons[key] = provider.LoadPackageObject<UTexture2D>(objectPath); }
            catch (Exception e) { Console.Error.WriteLine($"WARN: map icon {key} not found ({e.Message})"); }
        }

        foreach (var file in provider.Files.Keys.Where(k => k.StartsWith(CreatureDescriptors, StringComparison.OrdinalIgnoreCase)
                                                            && Path.GetFileName(k).StartsWith("Desc_") && k.EndsWith(".uasset")))
        {
            try
            {
                var cdo = provider.LoadPackage(file).GetExports().FirstOrDefault(e => e.Name.StartsWith("Default__"));
                if (cdo is null || !cdo.TryGetValue(out FPackageIndex creature, "mCreatureClass") || creature.IsNull) continue;
                var texture = cdo.GetOrDefault<FPackageIndex>("mPersistentBigIcon")?.Load<UTexture2D>()
                              ?? cdo.GetOrDefault<FPackageIndex>("mSmallIcon")?.Load<UTexture2D>();
                if (texture is not null) icons[creature.Name] = texture;
                var arachnophobia = cdo.GetOrDefault<FPackageIndex>("mBigIconArachnophobiaMode")?.Load<UTexture2D>()
                                    ?? cdo.GetOrDefault<FPackageIndex>("mSmallIconArachnophobiaMode")?.Load<UTexture2D>();
                if (arachnophobia is not null) icons[creature.Name + ArachnophobiaSuffix] = arachnophobia;
            }
            catch (Exception e) { Console.Error.WriteLine($"WARN: creature descriptor {file}: {e.Message}"); }
        }
        return icons;
    }
}

// ---- output model (matches Konsl/satisfactory-world-generator default-world.json) ----

record ResourceNode(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("location")] float[] Location,
    [property: JsonPropertyName("resource")] string Resource,
    [property: JsonPropertyName("purity")] string Purity);

record GeyserNode(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("location")] float[] Location,
    [property: JsonPropertyName("purity")] string Purity);

class FrackingCore(string name, float[] location)
{
    [JsonPropertyName("name")] public string Name { get; } = name;
    [JsonPropertyName("location")] public float[] Location { get; } = location;
    [JsonPropertyName("resource")] public string? Resource { get; set; }
    [JsonPropertyName("satellites")] public List<GeyserNode> Satellites { get; } = [];
}

record World(
    [property: JsonPropertyName("gameVersion")] string GameVersion,
    [property: JsonPropertyName("resourceNodes")] List<ResourceNode> ResourceNodes,
    [property: JsonPropertyName("geysers")] List<GeyserNode> Geysers,
    [property: JsonPropertyName("frackingCores")] List<FrackingCore> FrackingCores);

// ---- collectibles + map output ----

record ScannableEntry(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("class")] string Class,
    [property: JsonPropertyName("creature"), JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)] string? Creature,
    [property: JsonPropertyName("item"), JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)] string? Item,
    [property: JsonPropertyName("amount"), JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)] int? Amount,
    [property: JsonPropertyName("location")] float[] Location);

record Collectibles(
    [property: JsonPropertyName("gameVersion")] string GameVersion,
    [property: JsonPropertyName("pickups")] List<ScannableEntry> Pickups,
    [property: JsonPropertyName("dropPods")] List<ScannableEntry> DropPods,
    [property: JsonPropertyName("creatureSpawners")] List<ScannableEntry> CreatureSpawners);

record MapBounds(
    [property: JsonPropertyName("minX")] float MinX,
    [property: JsonPropertyName("minY")] float MinY,
    [property: JsonPropertyName("maxX")] float MaxX,
    [property: JsonPropertyName("maxY")] float MaxY);

/** Image x grows with world X and image y with world Y; tile (z, x, y) covers 2^-z of the image per axis. */
record MapInfo(
    [property: JsonPropertyName("id")] string Id,
    [property: JsonPropertyName("gameVersion")] string GameVersion,
    [property: JsonPropertyName("size")] int Size,
    [property: JsonPropertyName("tileSize")] int TileSize,
    [property: JsonPropertyName("minZoom")] int MinZoom,
    [property: JsonPropertyName("maxZoom")] int MaxZoom,
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("world")] MapBounds World,
    [property: JsonPropertyName("icons")] Dictionary<string, string> Icons);

static class Json
{
    public static readonly JsonSerializerOptions Options = new()
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.Never,
    };
}

class Args(Dictionary<string, string> values)
{
    public static Args Parse(string[] args)
    {
        var values = new Dictionary<string, string>();
        for (int i = 0; i + 1 < args.Length; i += 2)
        {
            if (!args[i].StartsWith("--")) throw new Exception($"Unexpected argument '{args[i]}'");
            values[args[i][2..]] = args[i + 1];
        }
        return new Args(values);
    }

    public string? Get(string name) => values.GetValueOrDefault(name);
    public string Require(string name) => Get(name) ?? throw new Exception($"Missing required --{name}");
}
